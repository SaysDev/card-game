<?php

namespace App\Game;

use Swoole\Event;
use Swoole\Http\Request;
use App\Game\Core\Matchmaker;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server;
use App\Models\User;
use Swoole\Table;
use Exception;
use Swoole\Server as SwooleServer;
use App\Game\GameServerManager;
use App\Game\Core\Logger;
use App\Game\Core\MessageType;
use App\Services\WebSocketAuthService;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use App\Game\Core\WebsocketAuth;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class LobbyServer
{
    private const QUEUE_PROCESS_INTERVAL = 100000; // 100ms in microseconds
    private const SERVER_CHECK_INTERVAL = 5000; // 5 seconds

    private Logger $logger;
    private string $host;
    private int $port;
    private int $tcpPort;
    private ?\OpenSwoole\WebSocket\Server $wsServer = null;
    private ?\OpenSwoole\Server $tcpServer = null;
    private \OpenSwoole\Table $gameServersTable;
    private \OpenSwoole\Table $roomsTable;
    private \OpenSwoole\Table $playersTable;
    private \OpenSwoole\Table $clientsTable;
    private string $readyFile;
    private int $mainPid;
    private Matchmaker $matchmaker;
    private string $pidFile;
    private WebSocketAuthService $authService;
    private Logger $wsLogger;
    private Logger $tcpLogger;

    public function __construct(
        string $host = '127.0.0.1',
        int $port = 5555,
        ?LoggerInterface $logger = null
    ) {
        $this->host = $host;
        $this->port = $port;
        $this->tcpPort = $port + 1; // TCP server will run on port 5556
        $this->pidFile = storage_path('logs/lobby.pid');
        $this->readyFile = storage_path('logs/lobby_server.ready');
        
        if ($logger === null) {
            $this->logger = new Logger(storage_path('logs'), 'lobby');
        } else {
            $this->logger = $logger;
        }
        
        // Initialize WebSocket and TCP loggers
        $this->wsLogger = new Logger(storage_path('logs'), 'lobby_ws');
        $this->tcpLogger = new Logger(storage_path('logs'), 'lobby_tcp');
        
        // Register signal handlers for graceful shutdown
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGINT, [$this, 'handleSignal']);
            pcntl_signal(SIGTERM, [$this, 'handleSignal']);
        }
        
        // Clean up any stale PID files
        $this->cleanupStaleFiles();
        
        // Check ports at construction time
        $this->logger->debug("Checking ports availability in constructor...");
        if ($this->isPortInUse($this->port)) {
            $this->logger->warning("Port {$this->port} is already in use, will try to clean up before start");
        }
        
        if ($this->isPortInUse($this->tcpPort)) {
            $this->logger->warning("Port {$this->tcpPort} is already in use, will try to clean up before start");
        }

        // Initialize auth service
        $this->authService = new WebSocketAuthService();

        $this->logger->info("Initializing Lobby Server...");
    }

    private function cleanupStaleFiles(): void
    {
        // Clean up stale PID file if it exists but process is not running
        if (file_exists($this->pidFile)) {
            $pid = (int)file_get_contents($this->pidFile);
            if ($pid > 0) {
                try {
                    $result = posix_kill($pid, 0);
                    if (!$result) {
                        $this->logger->info("Removing stale PID file for non-existent process {$pid}");
                        unlink($this->pidFile);
                    }
                } catch (\Exception $e) {
                    $this->logger->info("Removing stale PID file: " . $e->getMessage());
                    unlink($this->pidFile);
                }
            }
        }
        
        // Clean up stale ready file
        if (file_exists($this->readyFile)) {
            $this->logger->info("Removing stale ready file");
            unlink($this->readyFile);
        }
    }

    private function initializeTables(): void
    {
        // Initialize clients table
        $this->clientsTable = new \OpenSwoole\Table(1024);
        $this->clientsTable->column('authenticated', \OpenSwoole\Table::TYPE_INT, 1);
        $this->clientsTable->column('user_id', \OpenSwoole\Table::TYPE_INT, 8);
        $this->clientsTable->column('username', \OpenSwoole\Table::TYPE_STRING, 64);
        $this->clientsTable->column('room_id', \OpenSwoole\Table::TYPE_INT, 8);
        $this->clientsTable->column('last_ping', \OpenSwoole\Table::TYPE_FLOAT, 8);
        $this->clientsTable->create();

        // Initialize rooms table
        $this->roomsTable = new \OpenSwoole\Table(128);
        $this->roomsTable->column('id', \OpenSwoole\Table::TYPE_INT);
        $this->roomsTable->column('server_id', \OpenSwoole\Table::TYPE_INT);
        $this->roomsTable->column('game_type', \OpenSwoole\Table::TYPE_STRING, 32);
        $this->roomsTable->column('status', \OpenSwoole\Table::TYPE_STRING, 32);
        $this->roomsTable->column('player_count', \OpenSwoole\Table::TYPE_INT);
        $this->roomsTable->column('max_players', \OpenSwoole\Table::TYPE_INT);
        $this->roomsTable->column('is_private', \OpenSwoole\Table::TYPE_INT, 1);
        $this->roomsTable->column('private_code', \OpenSwoole\Table::TYPE_STRING, 32);
        $this->roomsTable->column('created_at', \OpenSwoole\Table::TYPE_INT);
        $this->roomsTable->create();

        // Game servers table
        $this->gameServersTable = new Table(1024);
        $this->gameServersTable->column('id', Table::TYPE_INT);
        $this->gameServersTable->column('ip', Table::TYPE_STRING, 64);
        $this->gameServersTable->column('port', Table::TYPE_INT);
        $this->gameServersTable->column('max_rooms', Table::TYPE_INT);
        $this->gameServersTable->column('fd', Table::TYPE_INT);
        $this->gameServersTable->column('status', Table::TYPE_STRING, 32);
        $this->gameServersTable->column('load', Table::TYPE_FLOAT, 8);
        $this->gameServersTable->column('last_ping', Table::TYPE_INT);
        $this->gameServersTable->column('last_update', Table::TYPE_INT);
        $this->gameServersTable->create();

        // Players table
        $this->playersTable = new Table(1024);
        $this->playersTable->column('id', Table::TYPE_INT);
        $this->playersTable->column('fd', Table::TYPE_INT);
        $this->playersTable->column('room_id', Table::TYPE_INT);
        $this->playersTable->column('server_id', Table::TYPE_INT);
        $this->playersTable->create();
    }

    private function startWebSocketServer(): void
    {
        try {
            $this->logger->debug("Initializing WebSocket server on {$this->host}:{$this->port}");
            
            // Create WebSocket server
            $this->wsServer = new \OpenSwoole\WebSocket\Server($this->host, $this->port);
            
            // Set server options
            $this->wsServer->set([
                'worker_num' => 4,
                'daemonize' => false,
                'max_request' => 10000,
                'dispatch_mode' => 2,
                'debug_mode' => 1,
                'log_level' => SWOOLE_LOG_INFO,
                'log_file' => storage_path('logs/lobby_server_ws.log'),
                'heartbeat_check_interval' => 30,
                'heartbeat_idle_time' => 60,
                'buffer_output_size' => 8 * 1024 * 1024, // 8MB
                'max_conn' => 1000,
                'max_wait_time' => 60,
                'enable_reuse_port' => true,
                'max_coroutine' => 3000,
                'hook_flags' => SWOOLE_HOOK_ALL
            ]);
            
            // Register event handlers
            $this->wsServer->on('start', [$this, 'onWsStart']);
            $this->wsServer->on('workerStart', [$this, 'onWsWorkerStart']);
            $this->wsServer->on('open', [$this, 'onWsOpen']);
            $this->wsServer->on('message', [$this, 'onWsMessage']);
            $this->wsServer->on('close', [$this, 'onWsClose']);
            $this->wsServer->on('shutdown', [$this, 'onWsShutdown']);

            $this->logger->debug("WebSocket server initialized successfully");
        } catch (Exception $e) {
            $this->logger->error("Failed to initialize WebSocket server: " . $e->getMessage());
            $this->logger->error("Stack trace: " . $e->getTraceAsString());
            throw $e;
        }
    }

    private function startTcpServer(): void
    {
        try {
            $this->logger->debug("Initializing TCP server on {$this->host}:{$this->tcpPort}");
            
            // Create TCP server
            $this->tcpServer = new \OpenSwoole\Server($this->host, $this->tcpPort, SWOOLE_PROCESS, SWOOLE_SOCK_TCP);
            
            // Set server options
            $this->tcpServer->set([
                'worker_num' => 4,
                'daemonize' => false,
                'max_request' => 10000,
                'dispatch_mode' => 2,
                'debug_mode' => 1,
                'log_level' => 0,
                'heartbeat_check_interval' => 30,
                'heartbeat_idle_time' => 60,
            ]);
            
            // Set event handlers
            $this->tcpServer->on('Start', [$this, 'onTcpStart']);
            $this->tcpServer->on('WorkerStart', [$this, 'onTcpWorkerStart']);
            $this->tcpServer->on('Connect', [$this, 'onTcpConnect']);
            $this->tcpServer->on('Receive', [$this, 'onTcpReceive']);
            $this->tcpServer->on('Close', [$this, 'onTcpClose']);
            
            $this->logger->info("TCP Server initialized successfully");
            
        } catch (Exception $e) {
            $this->logger->error("Failed to initialize TCP server: " . $e->getMessage());
            $this->logger->error("Stack trace: " . $e->getTraceAsString());
            throw $e;
        }
    }

    public function start(): void
    {
        try {
            $this->logger->info("Starting Lobby Server on {$this->host}:{$this->port}");
            
            // Initialize tables first
            $this->initializeTables();
            
            // Fork process for TCP server first
            $tcpPid = pcntl_fork();
            if ($tcpPid === -1) {
                throw new Exception("Failed to fork TCP server process");
            } else if ($tcpPid === 0) {
                // Child process - run TCP server
                // Reset signal handlers in child process
                if (function_exists('pcntl_signal')) {
                    pcntl_signal(SIGINT, SIG_DFL);
                    pcntl_signal(SIGTERM, SIG_DFL);
                }
                
                // Set process title
                if (function_exists('cli_set_process_title')) {
                    cli_set_process_title("lobby-server-tcp");
                }
                
                $this->logger->info("Starting TCP server in child process");
                
                // Initialize and start TCP server in child process
                $this->startTcpServer();
                $this->tcpServer->start();
                exit(0);
            }
            
            // Parent process - run WebSocket server
            $this->logger->info("Starting WebSocket server in parent process");
            
            // Initialize WebSocket server
            $this->startWebSocketServer();
            
            // Create ready file
            file_put_contents($this->readyFile, time());
            chmod($this->readyFile, 0666);
            
            // Start WebSocket server
            $this->wsServer->start();
            
        } catch (Exception $e) {
            $this->logger->error("Failed to start server: " . $e->getMessage());
            $this->logger->error("Stack trace: " . $e->getTraceAsString());
            throw $e;
        }
    }

    public function onWsStart(\OpenSwoole\WebSocket\Server $server): void
    {
        $this->logger->info("WebSocket Server started on {$this->host}:{$this->port}");
        
        // Set process title
        if (function_exists('cli_set_process_title')) {
            cli_set_process_title("lobby-server-ws");
        }
        
        // Create ready file
        file_put_contents($this->readyFile, time());
        chmod($this->readyFile, 0666);
    }

    public function onWsWorkerStart(\OpenSwoole\WebSocket\Server $server, int $workerId): void
    {
        $this->logger->info("WebSocket Worker process {$workerId} started");
        
        // Set process title
        if (function_exists('cli_set_process_title')) {
            cli_set_process_title("lobby-server-ws-worker-{$workerId}");
        }
    }

    public function onWsOpen(\OpenSwoole\WebSocket\Server $server, \OpenSwoole\Http\Request $request): void
    {
        $this->logger->debug("New WebSocket connection: {$request->fd}");
        $this->clients[$request->fd] = [
            'authenticated' => false,
            'user_id' => null,
            'username' => null,
            'room_id' => null
        ];
    }

    public function onWsMessage(\OpenSwoole\WebSocket\Server $server, \OpenSwoole\WebSocket\Frame $frame): void
    {
        try {
            $message = json_decode($frame->data, true);
            if (!$message || !isset($message['type'])) {
                $this->logger->warning("Invalid message format from client {$frame->fd}");
                return;
            }

            $this->logger->debug("Received message from client {$frame->fd}: " . json_encode($message));

            switch ($message['type']) {
                case MessageType::AUTH->value:
                    $this->handleClientAuth($server, $frame->fd, $message);
                    break;
                case MessageType::MATCHMAKING_JOIN->value:
                    $this->handleMatchmakingJoin($server, $frame->fd, $message);
                    break;
                case MessageType::SET_READY->value:
                    $this->handleSetReady($server, $frame->fd, $message);
                    break;
                case MessageType::PING->value:
                    $this->handlePing($server, $frame->fd);
                    break;
                default:
                    $this->logger->warning("Unknown message type from client {$frame->fd}: {$message['type']}");
                    break;
            }
        } catch (Exception $e) {
            $this->logger->error("Error handling message: " . $e->getMessage());
            $this->logger->error("Stack trace: " . $e->getTraceAsString());
            $this->sendError($server, $frame->fd, "Internal server error: " . $e->getMessage());
        }
    }

    private function handleClientAuth(\OpenSwoole\WebSocket\Server $server, int $fd, array $message): void
    {
        try {
            if (!isset($message['token'])) {
                throw new Exception("Missing authentication token");
            }

            // In a real app, validate token with your authentication system
            // For this example, we'll use a dummy validation
            $token = $message['token'];
            $userData = $this->validateToken($token);

            if (!$userData) {
                throw new Exception("Invalid authentication token");
            }

            // Store client information
            $this->clientsTable->set($fd, [
                'fd' => $fd,
                'user_id' => $userData['id'],
                'username' => $userData['username'],
                'room_id' => 0,
                'authenticated' => 1
            ]);

            $this->sendWsResponse($server, $fd, [
                'type' => MessageType::AUTH_SUCCESS->value,
                'user_id' => $userData['id'],
                'username' => $userData['username']
            ]);

            $this->logger->info("Client {$fd} authenticated as user {$userData['id']} ({$userData['username']})");
        } catch (Exception $e) {
            $this->logger->error("Authentication error for client {$fd}: " . $e->getMessage());
            $this->sendWsError($server, $fd, $e->getMessage());
        }
    }

    private function validateToken(string $token): ?array
    {
        try {
            // In a real app, validate the token with your authentication system
            // For testing, we'll just decode the JWT and return the payload
            $tokenParts = explode('.', $token);
            if (count($tokenParts) !== 3) {
                return null;
            }

            $payload = json_decode(base64_decode($tokenParts[1]), true);
            if (!$payload || !isset($payload['user_id']) || !isset($payload['username'])) {
                return null;
            }

            return [
                'id' => $payload['user_id'],
                'username' => $payload['username']
            ];
        } catch (Exception $e) {
            $this->logger->error("Token validation error: " . $e->getMessage());
            return null;
        }
    }

    public function onWsClose(\OpenSwoole\WebSocket\Server $server, int $fd): void
    {
        $this->logger->debug("WebSocket connection closed: {$fd}");
        if (isset($this->clients[$fd])) {
            $client = $this->clients[$fd];
            if ($client['room_id']) {
                $this->handleLeaveGame($server, $fd, [
                    'type' => MessageType::LEAVE_GAME->value,
                'room_id' => $client['room_id']
            ]);
        }
            unset($this->clients[$fd]);
        }
    }

    public function onWsShutdown(\OpenSwoole\WebSocket\Server $server): void
    {
        $this->logger->info("WebSocket Server shutting down");
        
        // Remove ready file
        if (file_exists($this->readyFile)) {
            unlink($this->readyFile);
        }
        
        $this->isRunning = false;
    }

    private function ensurePortsAreFree(array $ports): void
    {
        foreach ($ports as $port) {
            $pids = [];
            exec("lsof -ti:{$port}", $pids);
            foreach ($pids as $pid) {
                if (is_numeric($pid)) {
                    $this->logger->warning("Killing process $pid on port $port");
                    posix_kill((int)$pid, SIGKILL);
                }
            }
        }
        // Poczekaj aż porty się zwolnią
        $timeout = 10;
        $start = time();
        while (true) {
            $busy = false;
            foreach ($ports as $port) {
                $check = [];
                exec("lsof -ti:{$port}", $check);
                if (!empty($check)) $busy = true;
            }
            if (!$busy || (time() - $start) > $timeout) break;
            usleep(200000);
        }
    }

    private function handleSignal(int $signal): void
    {
        $this->logger->info("Received signal {$signal}, shutting down...");
        
        // Stop servers first
        if ($this->wsServer) {
            $this->wsServer->stop();
            $this->wsServer = null;
        }
        
        if ($this->tcpServer) {
            $this->tcpServer->stop();
            $this->tcpServer = null;
        }
        
        // Clean up files
        if (file_exists($this->readyFile)) {
            unlink($this->readyFile);
        }
        
        if (file_exists($this->pidFile)) {
            unlink($this->pidFile);
        }
        
        // Kill any remaining processes
        $this->killProcessesOnPorts([$this->port, $this->tcpPort]);
        
        $this->logger->info("Lobby Server stopped");
        exit(0);
    }

    private function startEventLoop(): void
    {
        $this->logger->info("Starting event loop...");
        
        // Set up signal handling
        if (function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
        }
        
        while (true) {
            // Process signals
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
            
            // Process events
            if ($this->wsServer) {
                $this->wsServer->tick(100);
            }
            
            if ($this->tcpServer) {
                $this->tcpServer->tick(100);
            }
            
            // Sleep for a short time to prevent CPU overuse
            usleep(100000); // 100ms
        }
    }

    private function killProcessesOnPorts(array $ports): void
    {
        foreach ($ports as $port) {
            $cmd = "lsof -ti:{$port}";
            $output = [];
            exec($cmd, $output);
            
            foreach ($output as $pid) {
                if (is_numeric($pid)) {
                    posix_kill((int)$pid, SIGKILL);
                    $this->logger->info("Killed process {$pid} on port {$port}");
                }
            }
        }
    }

    public function isRunning(): bool
    {
        $pidFile = storage_path('logs/lobby.pid');
        if (!file_exists($pidFile)) {
            return false;
        }
        
        $pid = file_get_contents($pidFile);
        if (!$pid) {
            return false;
        }
        
        return posix_kill($pid, 0);
    }

    private function isPortInUse(int $port): bool
    {
        $connection = @fsockopen('127.0.0.1', $port);
        if (is_resource($connection)) {
            fclose($connection);
            return true;
        }
        return false;
    }

    private function findLeastLoadedGameServer(): ?array
    {
        $leastLoadedServer = null;
        $minLoad = PHP_FLOAT_MAX;

        foreach ($this->gameServersTable as $serverId => $serverInfo) {
            if ($serverInfo['status'] === 'active' && $serverInfo['load'] < $minLoad) {
                $leastLoadedServer = $serverInfo;
                $minLoad = $serverInfo['load'];
            }
        }

        return $leastLoadedServer;
    }

    private function handleMatchmakingJoin(\OpenSwoole\WebSocket\Server $server, int $fd, array $message): void
    {
        try {
            if (!isset($message['data'])) {
                throw new Exception("Missing data parameter");
            }

            $data = $message['data'];
            if (!isset($data['game_type']) || !isset($data['size'])) {
                throw new Exception("Missing required parameters");
            }

            // Get client info
            $client = $this->clientsTable->get($fd);
            if (!$client || !$client['authenticated']) {
                throw new Exception("Client not authenticated");
            }

            // Find least loaded game server
            $gameServer = $this->findLeastLoadedGameServer();
            if (!$gameServer) {
                throw new Exception("No available game servers");
            }

            // Send create room request to game server
            $response = $this->sendToGameServer($gameServer['ip'], $gameServer['port'], [
                'type' => 'create_room',
                'game_type' => $data['game_type'],
                'max_players' => $data['size'],
                'is_private' => $data['is_private'] ?? false,
                'private_code' => $data['private_code'] ?? '',
                'user_id' => $client['user_id'],
                'username' => $client['username']
            ]);

            if (!$response) {
                throw new Exception("No response from game server");
            }

            if (!isset($response['type']) || $response['type'] !== 'create_room_success') {
                throw new Exception("Failed to create room: " . ($response['message'] ?? 'Unknown error'));
            }

            // Store room information
            $this->roomsTable->set($response['room_id'], [
                'id' => $response['room_id'],
                'game_type' => $response['game_type'],
                'status' => 'waiting',
                'player_count' => 1,
                'max_players' => $response['max_players'],
                'is_private' => $data['is_private'] ? 1 : 0,
                'private_code' => $data['private_code'] ?? '',
                'game_server_id' => $gameServer['id'],
                'game_server_ip' => $gameServer['ip'],
                'game_server_port' => $gameServer['port']
            ]);

            // Send success response to client
            $server->push($fd, json_encode([
                'type' => 'matchmaking_success',
                'data' => [
                    'room_id' => $response['room_id'],
                    'game_type' => $response['game_type'],
                    'max_players' => $response['max_players'],
                    'server' => [
                        'ip' => $gameServer['ip'],
                        'port' => $gameServer['port']
                    ]
                ]
            ]));

            $this->logger->info("Player {$client['user_id']} ({$client['username']}) joined matchmaking for {$data['game_type']}");
        } catch (Exception $e) {
            $this->logger->error("Error in matchmaking join: " . $e->getMessage());
            $server->push($fd, json_encode([
                'type' => 'error',
                'message' => $e->getMessage()
            ]));
        }
    }

    private function generateGameServerToken(int $clientFd, int $serverId): string
    {
        $payload = [
            'client_fd' => $clientFd,
            'server_id' => $serverId,
            'exp' => time() + 300 // 5 minutes expiration
        ];

        return JWT::encode($payload, config('app.key'), 'HS256');
    }

    private function cleanupPorts(): void
    {
        $this->logger->debug("Checking and cleaning ports...");
        
        $ports = [$this->port, $this->tcpPort];
        foreach ($ports as $port) {
            if ($this->isPortInUse($port)) {
                $this->logger->debug("Port {$port} is in use, attempting to clean up...");
                
                // Try SIGTERM first
                $pid = $this->getProcessIdOnPort($port);
                if ($pid) {
                    $this->logger->debug("Sending SIGTERM to process {$pid} on port {$port}");
                    posix_kill($pid, SIGTERM);
                    
                    // Wait a bit for graceful shutdown
                    sleep(1);
                    
                    // Check if process is still running
                    if (posix_kill($pid, 0)) {
                        $this->logger->debug("Process {$pid} still running, sending SIGKILL");
                        posix_kill($pid, SIGKILL);
                    }
                }
            }
        }
        
        $this->logger->debug("Port cleanup completed");
    }

    private function getProcessIdOnPort(int $port): ?int
    {
        $cmd = "lsof -i :{$port} -t";
        $pid = trim(shell_exec($cmd));
        return $pid ? (int)$pid : null;
    }

    private function handleMatchmakingLeave(\OpenSwoole\WebSocket\Server $server, int $fd, array $data): void
    {
        try {
            $client = $this->clientsTable->get($fd);
            if (!$client) {
                return; // Client already disconnected
            }

            $roomId = $client['room_id'];
            if (!$roomId) {
                return; // Client not in a room
            }

            $room = $this->roomsTable->get($roomId);
            if (!$room) {
                // Room doesn't exist anymore
                $this->clientsTable->set($fd, [
                    'room_id' => 0
                ]);
                return;
            }

            $serverId = $room['server_id'];
            $serverInfo = $this->gameServersTable->get($serverId);
            
            if ($serverInfo) {
                // Connect to the game server
                $gameServerClient = $this->connectToGameServer($serverInfo['ip'], $serverInfo['port']);
                if ($gameServerClient) {
                    // Send leave request
                    $leaveMessage = [
                        'type' => MessageType::LEAVE_GAME->value,
                        'room_id' => $roomId,
                        'user_id' => $client['user_id']
                    ];

                    $messageJson = json_encode($leaveMessage);
                    $packed = pack('N', strlen($messageJson)) . $messageJson;
                    $gameServerClient->send($packed);

                    // Don't need to wait for response
                    $gameServerClient->close();
                }
            }

            // Update player's state
            $this->clientsTable->set($fd, [
                'room_id' => 0
            ]);

            // Send confirmation to client
            $this->sendResponse($server, $fd, [
                'type' => MessageType::LEAVE_ROOM->value,
                'room_id' => $roomId
            ]);

            $this->logger->info("Player {$client['user_id']} left room {$roomId}");
        } catch (Exception $e) {
            $this->logger->error("Error in matchmaking leave: " . $e->getMessage());
            $this->sendError($server, $fd, "Internal server error: " . $e->getMessage());
        }
    }

    private function connectToGameServer(string $ip, int $port): ?\OpenSwoole\Client
    {
        try {
            $client = new \OpenSwoole\Client(SWOOLE_SOCK_TCP);
            $client->set([
                'open_length_check' => true,
                'package_length_type' => 'N',
                'package_length_offset' => 0,
                'package_body_offset' => 4,
                'package_max_length' => 8 * 1024 * 1024
            ]);

            if (!$client->connect($ip, $port, 5)) {
                $this->logger->error("Failed to connect to game server at {$ip}:{$port}");
                return null;
            }

            return $client;
        } catch (Exception $e) {
            $this->logger->error("Error connecting to game server: " . $e->getMessage());
            return null;
        }
    }

    private function receiveFromGameServer(\OpenSwoole\Client $client): ?array
    {
        try {
            $this->logger->debug("Waiting for response from Game Server...");

            // With 'open_length_check' enabled, recv() will return the complete message body.
            // A timeout can be specified here or it will use the client's default.
            $data = $client->recv(5.0); // Wait for up to 5 seconds for a response.

            if ($data === false) {
                $this->logger->error("Failed to receive message from Game Server. Error: " . socket_strerror($client->errCode));
                return null;
            }
            if ($data === '') {
                $this->logger->error("Connection closed by Game Server before response.");
                return null;
            }

            $this->logger->debug("Raw response from Game Server: " . $data);
            
            // Decode the JSON message
            $message = json_decode($data, true);
            if ($message === null) {
                $this->logger->error("Failed to decode JSON from Game Server: " . json_last_error_msg());
                return null;
            }

            $this->logger->debug("Decoded response from Game Server: " . json_encode($message));
            return $message;
        } catch (Exception $e) {
            $this->logger->error("Error receiving from Game Server: " . $e->getMessage());
            $this->logger->error("Stack trace: " . $e->getTraceAsString());
            return null;
        }
    }

    public function onTcpStart(\OpenSwoole\Server $server): void
    {
        $this->logger->info("TCP Server started on {$this->host}:{$this->tcpPort}");
        
        // Start cleanup timer
        $this->startCleanupTimer();
    }

    public function onTcpWorkerStart(\OpenSwoole\Server $server, int $workerId): void
    {
        $this->logger->info("TCP Worker process {$workerId} started");
        
        // Set process title
        if (function_exists('cli_set_process_title')) {
            cli_set_process_title("lobby-server-tcp-worker-{$workerId}");
        }
    }

    public function onTcpConnect(\OpenSwoole\Server $server, int $fd): void
    {
        $this->logger->debug("TCP Client connected: {$fd}");
    }

    public function onTcpReceive(\OpenSwoole\Server $server, int $fd, int $reactorId, string $data): void
    {
        try {
            // Check if the message has a valid length prefix
            if (strlen($data) < 4) {
                $this->logger->warning("Message too short from client {$fd}");
                return;
            }

            // Extract length prefix
            $length = unpack('N', substr($data, 0, 4))[1];
            
            // Extract message
            $messageData = substr($data, 4);
            
            // Validate message length
            if (strlen($messageData) !== $length) {
                $this->logger->warning("Message length mismatch from client {$fd}: expected {$length}, got " . strlen($messageData));
                return;
            }

            $message = json_decode($messageData, true);
            if (!$message || !isset($message['type'])) {
                $this->logger->warning("Invalid message format from client {$fd}");
                return;
            }

            $this->logger->debug("Received TCP message from client {$fd}: " . $messageData);

            switch ($message['type']) {
                case 'register':
                    $this->handleGameServerRegistration($server, $fd, $message);
                    break;
                case 'ping':
                    $this->handleGameServerPing($server, $fd, $message);
                    break;
                case 'status_update':
                    $this->handleGameServerStatusUpdate($server, $fd, $message);
                    break;
                default:
                    $this->logger->warning("Unknown message type from client {$fd}: {$message['type']}");
                    $this->sendTcpError($server, $fd, "Unknown message type: {$message['type']}");
                    break;
            }
        } catch (Exception $e) {
            $this->logger->error("Error handling TCP message: " . $e->getMessage());
            $this->logger->error("Stack trace: " . $e->getTraceAsString());
            $this->sendTcpError($server, $fd, "Internal server error: " . $e->getMessage());
        }
    }

    public function onTcpClose(\OpenSwoole\Server $server, int $fd): void
    {
        $this->logger->debug("TCP Client disconnected: {$fd}");
        
        // Don't immediately remove game servers on disconnect
        // They will be cleaned up by the cleanup timer if they don't reconnect
    }

    public function onTcpShutdown(\OpenSwoole\Server $server): void
    {
        $this->logger->info("TCP Server shutting down");
    }

    private function handleGameServerRegistration(\OpenSwoole\Server $server, int $fd, array $message): void
    {
        try {
            if (!isset($message['server_id']) || !isset($message['ip']) || !isset($message['port'])) {
                throw new Exception("Missing required registration fields");
            }

            $serverId = $message['server_id'];
            $ip = $message['ip'];
            $port = $message['port'];
            $maxRooms = $message['max_rooms'] ?? 10;
            $currentTime = time();

            // Store game server info
            $this->gameServersTable->set($serverId, [
                'id' => $serverId,
                'ip' => $ip,
                'port' => $port,
                'max_rooms' => $maxRooms,
                'fd' => $fd,
                'status' => 'active',
                'load' => 0.0,
                'last_ping' => $currentTime,
                'last_update' => $currentTime
            ]);

            $this->logger->info("Game Server #{$serverId} registered at {$ip}:{$port}");

            // Send success response
            $this->sendTcpResponse($server, $fd, [
                'type' => 'register_success',
                'server_id' => $serverId
            ]);
        } catch (Exception $e) {
            $this->logger->error("Error registering game server: " . $e->getMessage());
            $this->sendTcpError($server, $fd, $e->getMessage());
        }
    }

    private function handleGameServerPing(\OpenSwoole\Server $server, int $fd, array $message): void
    {
        try {
            if (!isset($message['server_id'])) {
                throw new Exception("Missing server_id in ping message");
            }

            $serverId = $message['server_id'];
            $serverInfo = $this->gameServersTable->get($serverId);

            if (!$serverInfo) {
                throw new Exception("Game server {$serverId} not found");
            }

            // Update last ping time
            $this->gameServersTable->set($serverId, [
                'last_ping' => time()
            ]);

            // Send pong response
            $this->sendTcpResponse($server, $fd, [
                'type' => 'pong',
                'server_id' => $serverId,
                'timestamp' => time()
            ]);
        } catch (Exception $e) {
            $this->logger->error("Error handling game server ping: " . $e->getMessage());
            $this->sendTcpError($server, $fd, $e->getMessage());
        }
    }

    private function handleGameServerStatusUpdate(\OpenSwoole\Server $server, int $fd, array $message): void
    {
        try {
            if (!isset($message['server_id']) || !isset($message['status']) || !isset($message['load'])) {
                throw new Exception("Missing required status update fields");
            }

            $serverId = $message['server_id'];
            $status = $message['status'];
            $load = (float)$message['load'];
            $serverInfo = $this->gameServersTable->get($serverId);

            if (!$serverInfo) {
                throw new Exception("Game server {$serverId} not found");
            }

            // Update server status, load, and timestamps
            $this->gameServersTable->set($serverId, [
                'status' => $status,
                'load' => $load,
                'last_update' => time(),
                'last_ping' => time()  // Update last ping time on status update
            ]);

            $this->logger->info("Game Server #{$serverId} status updated to {$status} with load {$load}");

            // Send success response
            $this->sendTcpResponse($server, $fd, [
                'type' => 'status_update_success',
                'server_id' => $serverId,
                'timestamp' => time()
            ]);
        } catch (Exception $e) {
            $this->logger->error("Error handling game server status update: " . $e->getMessage());
            $this->sendTcpError($server, $fd, $e->getMessage());
        }
    }

    private function sendTcpResponse(\OpenSwoole\Server $server, int $fd, array $data): void
    {
        try {
            $json = json_encode($data);
            if ($json === false) {
                $this->logger->error("Failed to encode response: " . json_last_error_msg());
                return;
            }

            // Pack the length as a 4-byte network byte order integer
            $length = strlen($json);
            $packed = pack('N', $length) . $json;
            
            $this->logger->debug("Sending TCP response to client {$fd}: " . $json);
            $this->logger->debug("Response length: " . strlen($packed));
            
            $server->send($fd, $packed);
        } catch (Exception $e) {
            $this->logger->error("Error sending TCP response: " . $e->getMessage());
            $this->logger->error("Stack trace: " . $e->getTraceAsString());
        }
    }

    private function sendTcpError(\OpenSwoole\Server $server, int $fd, string $message): void
    {
        $this->sendTcpResponse($server, $fd, [
            'type' => 'error',
            'message' => $message
        ]);
    }

    public function onClientConnect(\OpenSwoole\WebSocket\Server $server, \OpenSwoole\Http\Request $request): void
    {
        $fd = $request->fd;
        $this->logger->debug("New client connected: {$fd}");
        
        // Initialize client data
        $this->clientsTable->set($fd, [
            'authenticated' => 0,
            'user_id' => 0,
            'username' => '',
            'room_id' => 0,
            'last_ping' => microtime(true)
        ]);
    }

    public function onClientMessage(\OpenSwoole\WebSocket\Frame $frame): void
    {
        try {
            $fd = $frame->fd;
            $data = json_decode($frame->data, true);
            
            if (!$data) {
                $this->logger->warning("Invalid message format from client {$fd}");
                return;
            }

            if (!isset($data['type'])) {
                $this->logger->warning("Message missing type from client {$fd}");
                return;
            }

            // Check if client is authenticated for non-auth messages
            if ($data['type'] !== MessageType::AUTH->value) {
                $client = $this->clientsTable->get($fd);
                if (!$client || !$client['authenticated']) {
                    $this->sendWsError($this->wsServer, $fd, "Not authenticated");
                    return;
                }
            }

            switch ($data['type']) {
                case MessageType::AUTH->value:
                    $this->handleAuth($this->wsServer, $fd, $data);
                    break;
                case MessageType::PING->value:
                    $this->handlePing($this->wsServer, $fd);
                    break;
                case MessageType::MATCHMAKING_JOIN->value:
                    $this->handleMatchmakingJoin($this->wsServer, $fd, $data);
                    break;
                case MessageType::MATCHMAKING_LEAVE->value:
                    $this->handleMatchmakingLeave($this->wsServer, $fd, $data);
                    break;
                default:
                    $this->logger->warning("Unknown message type from client {$fd}: {$data['type']}");
                    break;
            }
        } catch (Exception $e) {
            $this->logger->error("Error handling client message: " . $e->getMessage());
            $this->sendWsError($this->wsServer, $frame->fd, "Internal server error");
        }
    }

    public function onClientClose(\OpenSwoole\WebSocket\Server $server, int $fd, int $reactorId): void
    {
        $this->logger->debug("Client disconnected: {$fd}");
        
        // Clean up client data
        $client = $this->clientsTable->get($fd);
        if ($client && $client['room_id'] > 0) {
            $this->handleMatchmakingLeave($server, $fd, [
                'type' => 'matchmaking_leave',
                'room_id' => $client['room_id']
            ]);
        }
        
        $this->clientsTable->del($fd);
    }

    private function sendWsResponse(\OpenSwoole\WebSocket\Server $server, int $fd, array $data): void
    {
        try {
            $json = json_encode($data);
            $server->push($fd, $json);
        } catch (Exception $e) {
            $this->wsLogger->error("Error sending response to client {$fd}: " . $e->getMessage());
        }
    }

    private function sendWsError(\OpenSwoole\WebSocket\Server $server, int $fd, string $message): void
    {
        $this->sendWsResponse($server, $fd, [
            'type' => 'error',
            'message' => $message
        ]);
    }

    public function stop(): void
    {
        try {
            $this->logger->info("Stopping Lobby Server...");
            
            // Stop TCP server if running
            if ($this->tcpServer && $this->tcpServer->isRunning()) {
                $this->tcpServer->shutdown();
            }
            
            // Stop WebSocket server if running
            if ($this->wsServer && $this->wsServer->isRunning()) {
                $this->wsServer->shutdown();
            }
            
            // Remove ready file
            if (file_exists($this->readyFile)) {
                unlink($this->readyFile);
            }
            
            $this->isRunning = false;
            $this->logger->info("Lobby Server stopped");
        } catch (Exception $e) {
            $this->logger->error("Error stopping Lobby Server: " . $e->getMessage());
            $this->logger->error("Stack trace: " . $e->getTraceAsString());
        }
    }

    private function startCleanupTimer(): void
    {
        // Check for inactive game servers every 30 seconds
        \OpenSwoole\Timer::tick(30000, function() {
            $currentTime = time();
            foreach ($this->gameServersTable as $key => $server) {
                // Remove server if no ping received in last 60 seconds
                if ($currentTime - $server['last_ping'] > 60) {
                    $this->gameServersTable->del($key);
                    $this->logger->info("Game server {$key} removed due to inactivity");
                }
            }
        });
    }

    private function sendToGameServer(string $ip, int $port, array $message): ?array
    {
        try {
            $client = new \OpenSwoole\Client(SWOOLE_SOCK_TCP);
            $client->set([
                'open_length_check' => true,
                'package_length_type' => 'N',
                'package_length_offset' => 0,
                'package_body_offset' => 4,
                'package_max_length' => 8 * 1024 * 1024,
                'socket_buffer_size' => 8 * 1024 * 1024,
                'buffer_output_size' => 8 * 1024 * 1024,
                'tcp_nodelay' => true,
                'tcp_keepalive' => true
            ]);

            if (!$client->connect($ip, $port, 5)) {
                $this->logger->error("Failed to connect to game server {$ip}:{$port}");
                return null;
            }

            $json = json_encode($message);
            if ($json === false) {
                $this->logger->error("Failed to encode message: " . json_last_error_msg());
                $client->close();
                return null;
            }

            // Add length prefix (4 bytes)
            $packed = pack('N', strlen($json)) . $json;
            
            $this->logger->debug("Sending to game server {$ip}:{$port}: " . $json);
            
            if (!$client->send($packed)) {
                $this->logger->error("Failed to send message to game server");
                $client->close();
                return null;
            }

            // Wait for response with timeout
            $startTime = microtime(true);
            $timeout = 5.0; // 5 seconds timeout
            
            while (microtime(true) - $startTime < $timeout) {
                $response = $client->recv(100000); // 100ms timeout in microseconds
                if ($response !== false) {
                    // Extract the JSON part (skip the 4-byte length prefix)
                    $json = substr($response, 4);
                    $data = json_decode($json, true);
                    if ($data !== null) {
                        $client->close();
                        return $data;
                    }
                }
                usleep(100000); // Sleep for 100ms to prevent busy waiting
            }

            $this->logger->error("Timeout waiting for response from game server");
            $client->close();
            return null;
        } catch (Exception $e) {
            $this->logger->error("Error sending to game server: " . $e->getMessage());
            return null;
        }
    }

    private function handleSetReady(\OpenSwoole\WebSocket\Server $server, int $fd, array $message): void
    {
        try {
            if (!isset($message['data']) || !isset($message['data']['ready'])) {
                throw new Exception("Missing ready status in message");
            }

            $client = $this->clientsTable->get($fd);
            if (!$client || !$client['authenticated']) {
                throw new Exception("Client not authenticated");
            }

            $roomId = $client['room_id'];
            if (!$roomId) {
                throw new Exception("Client not in a room");
            }

            $room = $this->roomsTable->get($roomId);
            if (!$room) {
                throw new Exception("Room not found");
            }

            $ready = (bool)$message['data']['ready'];
            $userId = $client['user_id'];
            $username = $client['username'];

            // Update player's ready status
            $this->playersTable->set($userId, [
                'id' => $userId,
                'user_id' => $userId,
                'username' => $username,
                'room_id' => $roomId,
                'status' => $ready ? 'ready' : 'waiting'
            ]);

            // Notify other players in the room
            $this->notifyRoomPlayers($server, $roomId, [
                'type' => $ready ? MessageType::PLAYER_READY->value : MessageType::PLAYER_NOT_READY->value,
                'room_id' => $roomId,
                'player_id' => $userId,
                'player_name' => $username
            ]);

            // Send confirmation to the player
            $this->sendResponse($server, $fd, [
                'type' => MessageType::SET_READY->value,
                'success' => true,
                'ready' => $ready
            ]);

            $this->logger->info("Player {$userId} ({$username}) set ready status to " . ($ready ? 'ready' : 'not ready') . " in room {$roomId}");

            // Check if all players are ready
            $allReady = true;
            foreach ($this->playersTable as $player) {
                if ($player['room_id'] == $roomId && $player['status'] !== 'ready') {
                    $allReady = false;
                    break;
                }
            }

            if ($allReady && $room['player_count'] >= 2) {
                $this->startGame($server, $roomId);
            }
        } catch (Exception $e) {
            $this->logger->error("Error handling set ready: " . $e->getMessage());
            $this->sendError($server, $fd, $e->getMessage());
        }
    }

    private function notifyRoomPlayers(\OpenSwoole\WebSocket\Server $server, int $roomId, array $message, array $excludePlayers = []): void
    {
        $room = $this->roomsTable->get($roomId);
        if (!$room) {
            return;
        }

        foreach ($this->clientsTable as $fd => $client) {
            if ($client['room_id'] == $roomId && !in_array($client['user_id'], $excludePlayers)) {
                $server->push($fd, json_encode($message));
            }
        }
    }
} // End of LobbyServer class