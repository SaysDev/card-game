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
        
        if ($this->isPortInUse($this->port + 1)) {
            $this->logger->warning("Port " . ($this->port + 1) . " is already in use, will try to clean up before start");
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
        $this->roomsTable->column('name', \OpenSwoole\Table::TYPE_STRING, 64);
        $this->roomsTable->column('host_id', \OpenSwoole\Table::TYPE_INT, 8);
        $this->roomsTable->column('game_id', \OpenSwoole\Table::TYPE_INT, 8);
        $this->roomsTable->column('status', \OpenSwoole\Table::TYPE_INT, 1);
        $this->roomsTable->create();

        // Game servers table
        $this->gameServersTable = new Table(1024);
        $this->gameServersTable->column('id', Table::TYPE_INT);
        $this->gameServersTable->column('ip', Table::TYPE_STRING, 15);
        $this->gameServersTable->column('port', Table::TYPE_INT);
        $this->gameServersTable->column('load', Table::TYPE_INT);
        $this->gameServersTable->column('last_ping', Table::TYPE_FLOAT);
        $this->gameServersTable->column('fd', Table::TYPE_INT);
        $this->gameServersTable->create();

        // Players table
        $this->playersTable = new Table(1024);
        $this->playersTable->column('id', Table::TYPE_INT);
        $this->playersTable->column('fd', Table::TYPE_INT);
        $this->playersTable->column('room_id', Table::TYPE_INT);
        $this->playersTable->column('server_id', Table::TYPE_INT);
        $this->playersTable->create();
    }

    public function initializeServer(): void
    {
        try {
            // Clean up ports first
            $this->logger->debug("Cleaning up ports before initialization...");
            $this->killProcessesOnPorts([$this->port, $this->port + 1]);
            
            // Double-check ports are free
            if ($this->isPortInUse($this->port)) {
                $this->logger->error("Port {$this->port} is still in use after cleanup");
                throw new \RuntimeException("Port {$this->port} is still in use after cleanup");
            }
            
            if ($this->isPortInUse($this->port + 1)) {
                $this->logger->error("Port " . ($this->port + 1) . " is still in use after cleanup");
                throw new \RuntimeException("Port " . ($this->port + 1) . " is still in use after cleanup");
            }
            
            // Create WebSocket server
            $this->logger->debug("Initializing WebSocket server on {$this->host}:{$this->port}");
            $this->wsServer = new \OpenSwoole\WebSocket\Server($this->host, $this->port);
            
            // Set server options
            $this->wsServer->set([
                'worker_num' => 4,
                'max_conn' => 1000,
                'heartbeat_check_interval' => 60,
                'heartbeat_idle_time' => 120,
                'buffer_output_size' => 8 * 1024 * 1024, // 8MB
                'max_request' => 10000,
                'dispatch_mode' => 2,
                'log_level' => 0,
                'log_file' => storage_path('logs/websocket_server.log'),
                'daemonize' => false, // Run in foreground
                'pid_file' => $this->pidFile,
                'hook_flags' => SWOOLE_HOOK_ALL,
                'package_max_length' => 8 * 1024 * 1024, // 8MB
                'open_length_check' => true,
                'package_length_type' => 'N',
                'package_length_offset' => 0,
                'package_body_offset' => 4
            ]);
            
            // Set up TCP server for game server communication on port+1 (usually 5556)
            $tcpPort = $this->port + 1;
            $this->logger->debug("Initializing TCP server for game servers on {$this->host}:{$tcpPort}");
            
            // Register event handlers
            $this->wsServer->on('start', [$this, 'onWsStart']);
            $this->wsServer->on('open', [$this, 'onWsOpen']);
            $this->wsServer->on('message', [$this, 'onWebSocketMessage']);
            $this->wsServer->on('close', [$this, 'onWsClose']);
            
            // Set up TCP listeners for game server communication
            if (!@$this->wsServer->addlistener($this->host, $tcpPort, SWOOLE_SOCK_TCP)) {
                $this->logger->error("Failed to add TCP listener on port {$tcpPort}");
                throw new \RuntimeException("Failed to add TCP listener on port {$tcpPort}");
            }
            
            // Register TCP event handlers
            $this->wsServer->on('receive', [$this, 'onTcpReceive']);
            $this->wsServer->on('connect', [$this, 'onTcpConnect']);
            $this->wsServer->on('close', [$this, 'onTcpClose']);
            
            $this->logger->info("Server initialization complete");
        } catch (Exception $e) {
            $this->logger->error("Failed to initialize server: " . $e->getMessage());
            $this->logger->error("Stack trace: " . $e->getTraceAsString());
            throw $e;
        }
    }

    private function cleanupPorts(): void
    {
        $this->logger->debug("Sprawdzanie i czyszczenie portów...");
        
        // Sprawdź i wyczyść port WebSocket
        $this->killProcessOnPort($this->port);
        
        // Sprawdź i wyczyść port TCP
        $tcpPort = $this->port + 1;
        $this->killProcessOnPort($tcpPort);
        
        $this->logger->debug("Porty sprawdzone i wyczyszczone");
    }

    private function killProcessOnPort(int $port): void
    {
        // Spróbuj utworzyć tymczasowy serwer na porcie, aby sprawdzić czy jest zajęty
        try {
            $testServer = new \OpenSwoole\Server("127.0.0.1", $port, SWOOLE_PROCESS, SWOOLE_SOCK_TCP);
            $testServer->on('receive', function() {}); // Add empty callback to satisfy OpenSwoole requirement
            $testServer->start();
        } catch (\Throwable $e) {
            // Port jest zajęty, spróbuj znaleźć i zakończyć proces
            $this->logger->debug("Port {$port} jest zajęty, próbuję znaleźć i zakończyć proces...");
            
            // Użyj ps i grep do znalezienia procesu
            $cmd = "ps aux | grep 'swoole' | grep '{$port}' | grep -v grep | awk '{print $2}'";
            $pid = shell_exec($cmd);
            
            if ($pid) {
                $this->logger->debug("Znaleziono proces na porcie {$port} (PID: {$pid}), zatrzymuję...");
                shell_exec("kill -9 {$pid} 2>/dev/null");
                sleep(2); // Daj więcej czasu na zwolnienie portu
            }
        }
    }

    public function onWsStart(\OpenSwoole\WebSocket\Server $server): void
    {
        $this->wsLogger->info("Server started on {$this->host}:{$this->port}");
        
        // Set process title
        if (function_exists('cli_set_process_title')) {
            cli_set_process_title("lobby-server-ws");
        }
    }

    public function onWsOpen(\OpenSwoole\WebSocket\Server $server, \OpenSwoole\Http\Request $request): void
    {
        $this->wsLogger->info("New connection from {$request->server['remote_addr']}");
    }

    public function onWebSocketMessage(\OpenSwoole\WebSocket\Server $server, Frame $frame): void
    {
        try {
            $data = json_decode($frame->data, true);
            if (!$data || !isset($data['type'])) {
                $this->wsLogger->warning("Invalid message format from client {$frame->fd}");
                return;
            }

            $this->wsLogger->debug("Received message from client {$frame->fd}: " . $frame->data);

            switch ($data['type']) {
                case 'auth':
                    if (!isset($data['token'])) {
                        $this->sendError($server, $frame->fd, "Missing authentication token");
                        return;
                    }

                    $userData = $this->authService->validateToken($data['token']);
                    if (!$userData) {
                        $this->sendError($server, $frame->fd, "Invalid authentication token");
                        return;
                    }

                    // Store client info
                    $this->clientsTable->set($frame->fd, [
                        'fd' => $frame->fd,
                        'user_id' => $userData['user_id'],
                        'username' => $userData['username'],
                        'authenticated' => 1
                    ]);

                    // Send success response
                    $this->sendResponse($server, $frame->fd, [
                        'type' => 'auth_success',
                        'user_id' => $userData['user_id'],
                        'username' => $userData['username']
                    ]);

                    $this->wsLogger->info("Client {$frame->fd} authenticated as user {$userData['user_id']} ({$userData['username']})");
                    break;
                case 'ping':
                    $this->handlePing($server, $frame->fd);
                    break;
                default:
                    $this->wsLogger->warning("Unknown message type from client {$frame->fd}: {$data['type']}");
                    break;
            }
        } catch (Exception $e) {
            $this->wsLogger->error("Error handling message: " . $e->getMessage());
            $this->wsLogger->error("Stack trace: " . $e->getTraceAsString());
            $this->sendError($server, $frame->fd, "Internal server error: " . $e->getMessage());
        }
    }

    public function onWsClose(\OpenSwoole\WebSocket\Server $server, int $fd, int $reactorId): void
    {
        $this->wsLogger->info("Connection closed: {$fd}");
    }

    public function onWsShutdown(\OpenSwoole\WebSocket\Server $server): void
    {
        $this->wsLogger->info("Server shutting down");
    }

    public function onTcpStart(\OpenSwoole\Server $server): void
    {
        $this->tcpLogger->info("Server started on {$this->host}:" . ($this->port + 1));
        
        // Set process title
        if (function_exists('cli_set_process_title')) {
            cli_set_process_title("lobby-server-tcp");
        }
    }

    public function onTcpConnect(\OpenSwoole\Server $server, int $fd, int $reactorId): void
    {
        $this->tcpLogger->info("New game server connection: {$fd}");
    }

    public function onTcpReceive(\OpenSwoole\Server $server, int $fd, int $reactorId, string $data): void
    {
        try {
            $this->tcpLogger->debug("Received raw data from client {$fd}: " . bin2hex($data));

            // Check if the message has a valid length prefix
            if (strlen($data) < 4) {
                $this->tcpLogger->warning("Message too short from client {$fd}");
                return;
            }

            // Extract length prefix
            $length = unpack('N', substr($data, 0, 4))[1];
            
            // Extract message
            $messageData = substr($data, 4);
            
            // Validate message length
            if (strlen($messageData) !== $length) {
                $this->tcpLogger->warning("Message length mismatch from client {$fd}: expected {$length}, got " . strlen($messageData));
                return;
            }

            $message = json_decode($messageData, true);
            if (!$message || !isset($message['type'])) {
                $this->tcpLogger->warning("Invalid message format from client {$fd}");
                return;
            }

            $this->tcpLogger->debug("Received message from client {$fd}: " . $messageData);

            switch ($message['type']) {
                case MessageType::REGISTER->value:
                    $this->handleGameServerRegistration($server, $fd, $message);
                    break;
                case MessageType::PING->value:
                    $this->handleGameServerPing($server, $fd, $message);
                    break;
                case 'status_update':
                    $this->handleGameServerStatusUpdate($server, $fd, $message);
                    break;
                default:
                    $this->tcpLogger->warning("Unknown message type from client {$fd}: {$message['type']}");
                    break;
            }
        } catch (Exception $e) {
            $this->tcpLogger->error("Error handling message: " . $e->getMessage());
            $this->tcpLogger->error("Stack trace: " . $e->getTraceAsString());
            try {
                $this->sendError($server, $fd, "Internal server error: " . $e->getMessage());
            } catch (Exception $sendError) {
                $this->tcpLogger->error("Failed to send error response: " . $sendError->getMessage());
            }
        }
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

            $this->tcpLogger->info("Processing registration for game server #{$serverId} at {$ip}:{$port}");

            // Store game server info
            $this->gameServersTable->set($serverId, [
                'id' => $serverId,
                'ip' => $ip,
                'port' => $port,
                'max_rooms' => $maxRooms,
                'load' => 0,
                'last_ping' => microtime(true)
            ]);

            // Send registration success response
            $response = [
                'type' => 'register_success',
                'server_id' => $serverId,
                'timestamp' => time()
            ];

            $json = json_encode($response);
            if ($json === false) {
                throw new Exception("Failed to encode response: " . json_last_error_msg());
            }

            $packed = pack('N', strlen($json)) . $json;
            $this->tcpLogger->debug("Sending registration success response: " . $json);
            
            if (!$server->send($fd, $packed)) {
                throw new Exception("Failed to send registration response");
            }

            $this->tcpLogger->info("Game server #{$serverId} registered successfully");
        } catch (Exception $e) {
            $this->tcpLogger->error("Error handling game server registration: " . $e->getMessage());
            $this->tcpLogger->error("Stack trace: " . $e->getTraceAsString());
            try {
                $this->sendError($server, $fd, "Registration failed: " . $e->getMessage());
            } catch (Exception $sendError) {
                $this->tcpLogger->error("Failed to send error response: " . $sendError->getMessage());
            }
        }
    }

    private function handleGameServerPing(int $fd, array $message): void
    {
        try {
            if (!isset($message['server_id'])) {
                return;
            }

            $serverId = (int)$message['server_id'];
            $serverInfo = $this->gameServersTable->get($serverId);

            if ($serverInfo) {
                $this->gameServersTable->set($serverId, [
                    'id' => $serverId,
                    'ip' => $serverInfo['ip'],
                    'port' => $serverInfo['port'],
                    'load' => $serverInfo['load'],
                    'last_ping' => microtime(true),
                    'fd' => $fd
                ]);
                $this->logger->info("Ping! Aktualny stan gameServersTable: " . json_encode(iterator_to_array($this->gameServersTable)));
            }
        } catch (Exception $e) {
            $this->logger->error("Error handling game server ping: " . $e->getMessage());
        }
    }

    private function handleGameServerLoadUpdate($server, $fd, array $message): void
    {
        if (!isset($message['load'])) {
            throw new Exception('Missing load value');
        }

        $serverId = array_search($fd, array_column($this->gameServersTable->get(), 'fd'));
        if ($serverId) {
            $this->gameServersTable->set($serverId, [
                'load' => $message['load'],
                'last_ping' => microtime(true)
            ]);
        }
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
                    $this->sendError($this->wsServer, $fd, "Not authenticated");
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
            $this->sendError($this->wsServer, $frame->fd, "Internal server error");
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

    private function handleAuth(\OpenSwoole\WebSocket\Server $server, int $fd, array $data): void
    {
        try {
            if (!isset($data['token'])) {
                throw new Exception("Missing authentication token");
            }

            $token = $data['token'];
            $userData = $this->validateToken($token);

            if (!$userData) {
                throw new Exception("Invalid authentication token");
            }

            // Store client info
            $this->clientsTable->set($fd, [
                'fd' => $fd,
                'user_id' => $userData['id'],
                'username' => $userData['username'],
                'authenticated' => 1
            ]);

            // Send success response
            $this->sendResponse($server, $fd, [
                'type' => 'auth_success',
                'user_id' => $userData['id'],
                'username' => $userData['username']
            ]);

            $this->wsLogger->info("Client {$fd} authenticated as user {$userData['id']} ({$userData['username']})");
        } catch (Exception $e) {
            $this->wsLogger->error("Authentication error for client {$fd}: " . $e->getMessage());
            $this->sendError($server, $fd, $e->getMessage());
        }
    }

    private function validateToken(string $token): ?array
    {
        try {
            // Decode JWT token
            $decoded = JWT::decode($token, new Key(config('app.key'), 'HS256'));
            
            // Check required fields
            if (!isset($decoded->user_id, $decoded->username, $decoded->exp)) {
                return null;
            }
            
            // Check token expiration
            if ($decoded->exp < time()) {
                return null;
            }
            
            return [
                'id' => $decoded->user_id,
                'username' => $decoded->username
            ];
        } catch (Exception $e) {
            $this->wsLogger->error("Token validation error: " . $e->getMessage());
            return null;
        }
    }

    private function sendResponse(\OpenSwoole\WebSocket\Server $server, int $fd, array $data): void
    {
        try {
            $json = json_encode($data);
            $server->push($fd, $json);
        } catch (Exception $e) {
            $this->wsLogger->error("Error sending response to client {$fd}: " . $e->getMessage());
        }
    }

    private function sendError(\OpenSwoole\WebSocket\Server $server, int $fd, string $message): void
    {
        $this->sendResponse($server, $fd, [
            'type' => 'error',
            'message' => $message
        ]);
    }

    public function onShutdown(\OpenSwoole\Server $server): void
    {
        $this->logger->info("Lobby Server shutting down");
        // Clean up PID file
        if (file_exists($this->pidFile)) {
            unlink($this->pidFile);
        }
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

    public function start(): void
    {
        try {
            $this->logger->info("Starting Lobby Server on {$this->host}:{$this->port}");
            
            // Initialize tables
            $this->initializeTables();
            
            // More aggressive port cleanup before starting
            $this->logger->info("Performing aggressive port cleanup before starting...");
            
            // First try standard port cleanup
            $this->killProcessesOnPorts([$this->port, $this->port + 1]);
            
            // Now try a more aggressive approach using fuser to kill any processes
            $this->logger->info("Using fuser to check for any remaining processes on ports...");
            shell_exec("fuser -k {$this->port}/tcp 2>/dev/null");
            shell_exec("fuser -k " . ($this->port + 1) . "/tcp 2>/dev/null");
            
            // Wait to ensure ports are really free
            sleep(1);
            
            // Final verification
            if ($this->isPortInUse($this->port)) {
                $this->logger->error("Port {$this->port} is still in use despite cleanup efforts");
                throw new \RuntimeException("Port {$this->port} is still in use, cannot start server");
            }
            
            if ($this->isPortInUse($this->port + 1)) {
                $this->logger->error("Port " . ($this->port + 1) . " is still in use despite cleanup efforts");
                throw new \RuntimeException("Port " . ($this->port + 1) . " is still in use, cannot start server");
            }
            
            // Initialize server components after port cleanup
            $this->initializeServer();
            
            // Save PID to file
            $pidFile = storage_path('logs/lobby.pid');
            file_put_contents($pidFile, getmypid());
            chmod($pidFile, 0666);
            
            // Create ready file to indicate server is ready
            $readyFile = storage_path('logs/lobby_server.ready');
            file_put_contents($readyFile, time());
            chmod($readyFile, 0666);
            
            // Start the servers
            $this->logger->info("Starting WebSocket and TCP servers...");
            
            // Since the WebSocket server blocks, we need to start it after TCP
            // We use a timer to start TCP server after WebSocket server is running
            $this->wsServer->start();
            
            $this->logger->info("Lobby Server stopped");
        } catch (\Throwable $e) {
            $this->logger->error("Error starting Lobby Server: " . $e->getMessage());
            $this->logger->error("Stack trace: " . $e->getTraceAsString());
            throw $e;
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
        $this->killProcessesOnPorts([$this->port, $this->port + 1]);
        
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

    private function startWebSocketServer(): void
    {
        $this->wsServer = new \OpenSwoole\WebSocket\Server($this->host, $this->port);
        $this->wsServer->set([
            'worker_num' => 4,
            'daemonize' => false,
            'pid_file' => $this->pidFile,
            'max_request' => 10000,
            'dispatch_mode' => 2,
            'debug_mode' => 1,
            'log_level' => SWOOLE_LOG_INFO,
            'log_file' => storage_path('logs/lobby_server.log'),
            'heartbeat_check_interval' => 30,
            'heartbeat_idle_time' => 60,
            'buffer_output_size' => 8 * 1024 * 1024,
            'max_conn' => 1000,
            'max_wait_time' => 60,
            'enable_reuse_port' => true,
            'max_coroutine' => 3000,
            'hook_flags' => SWOOLE_HOOK_ALL
        ]);

        // WebSocket Event Handlers
        $this->wsServer->on('start', [$this, 'onWsStart']);
        $this->wsServer->on('open', [$this, 'onWsOpen']);
        $this->wsServer->on('message', [$this, 'onWebSocketMessage']);
        $this->wsServer->on('close', [$this, 'onWsClose']);
        $this->wsServer->on('shutdown', [$this, 'onWsShutdown']);

        $this->wsServer->start();
    }

    private function startTcpServer(): void
    {
        try {
            $tcpPort = $this->port + 1;
            $this->tcpLogger->debug("Initializing TCP server for game servers on {$this->host}:{$tcpPort}");
            
            // Create TCP server
            $this->tcpServer = new \OpenSwoole\Server($this->host, $tcpPort, SWOOLE_PROCESS, SWOOLE_SOCK_TCP);
            
            // Set server options
            $this->tcpServer->set([
                'worker_num' => 4,
                'max_request' => 10000,
                'dispatch_mode' => 2,
                'debug_mode' => 1,
                'log_level' => SWOOLE_LOG_INFO,
                'log_file' => storage_path('logs/lobby_tcp.log'),
                'heartbeat_check_interval' => 30,
                'heartbeat_idle_time' => 60,
                'buffer_output_size' => 8 * 1024 * 1024, // 8MB
                'max_conn' => 1000,
                'max_wait_time' => 60,
                'enable_reuse_port' => true,
                'max_coroutine' => 3000,
                'hook_flags' => SWOOLE_HOOK_ALL,
                'package_max_length' => 8 * 1024 * 1024, // 8MB
                'open_length_check' => true,
                'package_length_type' => 'N',
                'package_length_offset' => 0,
                'package_body_offset' => 4
            ]);

            // Register event handlers
            $this->tcpServer->on('start', [$this, 'onTcpStart']);
            $this->tcpServer->on('connect', [$this, 'onTcpConnect']);
            $this->tcpServer->on('receive', [$this, 'onTcpReceive']);
            $this->tcpServer->on('close', [$this, 'onTcpClose']);
            $this->tcpServer->on('shutdown', [$this, 'onTcpShutdown']);

            $this->tcpLogger->info("TCP server initialized successfully");
            
            // Start the TCP server
            $this->tcpServer->start();
        } catch (Exception $e) {
            $this->tcpLogger->error("Failed to initialize TCP server: " . $e->getMessage());
            $this->tcpLogger->error("Stack trace: " . $e->getTraceAsString());
            throw $e;
        }
    }

    private function handleMatchmakingJoin(\OpenSwoole\WebSocket\Server $server, int $fd, array $data): void
    {
        try {
            if (!isset($data['data'])) {
                $this->sendError($server, $fd, "Missing data parameter");
                return;
            }

            $matchmakingData = $data['data'];
            if (!isset($matchmakingData['game_type'])) {
                $this->sendError($server, $fd, "Missing game_type parameter");
                return;
            }

            $gameType = $matchmakingData['game_type'];
            $maxPlayers = $matchmakingData['size'] ?? 2;
            $isPrivate = $matchmakingData['isPrivate'] ?? false;
            $privateCode = $matchmakingData['privateCode'] ?? '';

            $client = $this->clientsTable->get($fd);
            
            if (!$client) {
                $this->sendError($server, $fd, "Client not found");
                return;
            }

            $userId = $client['user_id'];
            $username = $client['username'];

            $this->logger->info("Player {$userId} ({$username}) is looking for a {$gameType} game");

            // First, check if there's an available room to join
            $foundRoom = null;
            
            foreach ($this->roomsTable as $roomId => $room) {
                if ($room['game_type'] === $gameType && 
                    $room['status'] === 'waiting' && 
                    $room['player_count'] < $room['max_players']) {
                    
                    $foundRoom = [
                        'id' => $roomId,
                        'server_id' => $room['server_id']
                    ];
                    break;
                }
            }

            if ($foundRoom) {
                // Found a room to join
                $roomId = $foundRoom['id'];
                $serverId = $foundRoom['server_id'];
                $this->logger->info("Found existing room {$roomId} on server {$serverId} for player {$userId}");

                // Get game server info
                $serverInfo = $this->gameServersTable->get($serverId);
                if (!$serverInfo) {
                    $this->sendError($server, $fd, "Game server not available");
                    return;
                }

                // Connect to the game server
                $gameServerClient = $this->connectToGameServer($serverInfo['ip'], $serverInfo['port']);
                if (!$gameServerClient) {
                    $this->sendError($server, $fd, "Failed to connect to game server");
                    return;
                }

                // Send join request to game server
                $joinMessage = [
                    'type' => MessageType::JOIN_GAME->value,
                    'room_id' => $roomId,
                    'user_id' => $userId,
                    'username' => $username
                ];

                $messageJson = json_encode($joinMessage);
                $packed = pack('N', strlen($messageJson)) . $messageJson;
                $gameServerClient->send($packed);

                // Wait for response
                $response = $this->receiveFromGameServer($gameServerClient);
                $gameServerClient->close();

                if (!$response || !isset($response['type']) || $response['type'] !== 'room_joined') {
                    $this->sendError($server, $fd, "Failed to join room: " . ($response['message'] ?? 'Unknown error'));
                    return;
                }

                // Update client's room
                $this->clientsTable->set($fd, [
                    'room_id' => $roomId
                ]);

                // Send success response to client
                $this->sendResponse($server, $fd, [
                    'type' => 'matchmaking_joined',
                    'room_id' => $roomId,
                    'game_server_ip' => $serverInfo['ip'],
                    'game_server_port' => $serverInfo['port']
                ]);

                $this->logger->info("Player {$userId} joined room {$roomId} on server {$serverId}");
            } else {
                // Need to create a new room
                $this->logger->info("No existing room found for {$gameType}, creating a new one");

                // Find the least loaded game server
                $leastLoadedServer = $this->findLeastLoadedServer();
                if (!$leastLoadedServer) {
                    $this->sendError($server, $fd, "No game servers available");
                    return;
                }

                $serverId = $leastLoadedServer['id'];
                $serverIp = $leastLoadedServer['ip'];
                $serverPort = $leastLoadedServer['port'];

                $this->logger->info("Selected server {$serverId} ({$serverIp}:{$serverPort}) to create new room");

                // Connect to the game server
                $gameServerClient = $this->connectToGameServer($serverIp, $serverPort);
                if (!$gameServerClient) {
                    $this->sendError($server, $fd, "Failed to connect to game server");
                    return;
                }

                // Send create room request
                $createMessage = [
                    'type' => 'create_room',
                    'game_type' => $gameType,
                    'max_players' => $maxPlayers,
                    'user_id' => $userId,
                    'username' => $username
                ];

                $messageJson = json_encode($createMessage);
                $packed = pack('N', strlen($messageJson)) . $messageJson;
                $gameServerClient->send($packed);

                // Wait for response
                $response = $this->receiveFromGameServer($gameServerClient);
                $gameServerClient->close();

                if (!$response || !isset($response['type']) || $response['type'] !== 'room_created') {
                    $this->sendError($server, $fd, "Failed to create room: " . ($response['message'] ?? 'Unknown error'));
                    return;
                }

                $roomId = $response['room_id'];

                // Store room in our table
                $this->roomsTable->set($roomId, [
                    'server_id' => $serverId,
                    'game_type' => $gameType,
                    'player_count' => 1,
                    'max_players' => $maxPlayers,
                    'status' => 'waiting'
                ]);

                // Update client's room
                $this->clientsTable->set($fd, [
                    'room_id' => $roomId
                ]);

                // Send success response to client
                $this->sendResponse($server, $fd, [
                    'type' => 'matchmaking_joined',
                    'room_id' => $roomId,
                    'game_server_ip' => $serverIp,
                    'game_server_port' => $serverPort
                ]);

                $this->logger->info("Player {$userId} created and joined room {$roomId} on server {$serverId}");
            }
        } catch (Exception $e) {
            $this->logger->error("Error in matchmaking join: " . $e->getMessage());
            $this->logger->error("Stack trace: " . $e->getTraceAsString());
            $this->sendError($server, $fd, "Internal server error: " . $e->getMessage());
        }
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
            // First receive 4-byte length prefix
            $lengthData = $client->recv(4, 1);
            if ($lengthData === false || strlen($lengthData) !== 4) {
                return null;
            }

            // Unpack length
            $length = unpack('N', $lengthData)[1];
            if ($length <= 0 || $length > 8 * 1024 * 1024) {
                $this->logger->error("Invalid message length: {$length}");
                return null;
            }

            // Receive message body
            $data = $client->recv($length, 1);
            if ($data === false || strlen($data) !== $length) {
                $this->logger->error("Failed to receive message body");
                return null;
            }

            // Decode JSON
            $message = json_decode($data, true);
            if ($message === null) {
                $this->logger->error("Failed to decode JSON: " . json_last_error_msg());
                return null;
            }

            $this->logger->debug("Received from Game Server: " . json_encode($message));
            return $message;
        } catch (Exception $e) {
            $this->logger->error("Error receiving from Game Server: " . $e->getMessage());
            return null;
        }
    }

    private function findLeastLoadedServer(): ?array
    {
        $leastLoaded = null;
        $minLoad = PHP_INT_MAX;

        foreach ($this->gameServersTable as $serverId => $server) {
            if ($server['load'] < $minLoad) {
                $minLoad = $server['load'];
                $leastLoaded = [
                    'id' => $serverId,
                    'ip' => $server['ip'],
                    'port' => $server['port'],
                    'load' => $server['load']
                ];
            }
        }

        return $leastLoaded;
    }

    public function onTcpClose(\OpenSwoole\Server $server, int $fd, int $reactorId): void
    {
        $this->tcpLogger->info("Game server connection closed: {$fd}");
        
        // Remove server from table
        foreach ($this->gameServersTable as $serverId => $server) {
            if ($server['fd'] === $fd) {
                $this->gameServersTable->del($serverId);
                $this->tcpLogger->info("Game server #{$serverId} removed from table");
                break;
            }
        }
    }

    private function handlePing(\OpenSwoole\WebSocket\Server $server, int $fd): void
    {
        $this->sendResponse($server, $fd, [
            'type' => 'pong',
            'timestamp' => time()
        ]);
    }
}