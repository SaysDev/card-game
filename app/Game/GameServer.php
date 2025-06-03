<?php

namespace App\Game;

use OpenSwoole\Event;
use OpenSwoole\Http\Request;
use App\Game\Core\Matchmaker;
use OpenSwoole\WebSocket\Frame;
use OpenSwoole\WebSocket\Server;
use App\Models\User;
use OpenSwoole\Table;
use Exception;
use OpenSwoole\Server as OpenSwooleServer;
use App\Game\Core\Logger;
use App\Game\Core\MessageType;
use Psr\Log\LoggerInterface;

class GameServer
{
    private const QUEUE_PROCESS_INTERVAL = 100000; // 100ms in microseconds
    private const SERVER_CHECK_INTERVAL = 5000; // 5 seconds

    private int $lobbyPort;
    private string $lobbyHost;
    private int $maxRooms;
    private ?\OpenSwoole\Server $server = null;
    private ?Table $roomsTable = null;
    private ?Table $playersTable = null;
    private ?Table $clientsTable = null;
    private string $pidFile;
    private bool $isRegistered = false;
    private ?\OpenSwoole\Client $lobbyConnection = null;
    private bool $isRunning = false;
    private array $clients = [];
    private array $games = [];
    private array $rooms = [];
    private string $readyFile;
    private ?LoggerInterface $logger = null;

    public function __construct(
        private int $serverId,
        private string $host = '127.0.0.1',
        private int $port = 5557,
        ?LoggerInterface $logger = null
    ) {
        $this->pidFile = storage_path("logs/game_server_{$serverId}.pid");
        $this->readyFile = storage_path("logs/game_server_{$serverId}.ready");
        $this->maxRooms = 10;
        
        // Initialize Lobby Server connection parameters
        $this->lobbyHost = '127.0.0.1';
        $this->lobbyPort = 5556; // Use Lobby Server's TCP port directly
        
        // Initialize logger if not provided
        if ($logger === null) {
            $this->logger = new Logger(storage_path('logs'), 'game');
        } else {
            $this->logger = $logger;
        }
        
        $this->initializeTables();
        
        // Register signal handlers for graceful shutdown
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGINT, [$this, 'handleSignal']);
            pcntl_signal(SIGTERM, [$this, 'handleSignal']);
        }
    }

    private function initializeTables(): void
    {
        // Initialize rooms table
        $this->roomsTable = new Table(1024);
        $this->roomsTable->column('id', Table::TYPE_INT);
        $this->roomsTable->column('game_type', Table::TYPE_STRING, 32);
        $this->roomsTable->column('status', Table::TYPE_STRING, 32);
        $this->roomsTable->column('player_count', Table::TYPE_INT);
        $this->roomsTable->column('max_players', Table::TYPE_INT);
        $this->roomsTable->column('players', Table::TYPE_STRING, 1024); // JSON encoded array of player IDs
        $this->roomsTable->create();

        // Initialize players table
        $this->playersTable = new Table(1024);
        $this->playersTable->column('id', Table::TYPE_INT);
        $this->playersTable->column('user_id', Table::TYPE_INT);
        $this->playersTable->column('username', Table::TYPE_STRING, 64);
        $this->playersTable->column('room_id', Table::TYPE_INT);
        $this->playersTable->column('status', Table::TYPE_STRING, 32);
        $this->playersTable->create();

        // Initialize clients table
        $this->clientsTable = new Table(1024);
        $this->clientsTable->column('fd', Table::TYPE_INT);
        $this->clientsTable->column('user_id', Table::TYPE_INT);
        $this->clientsTable->column('username', Table::TYPE_STRING, 64);
        $this->clientsTable->column('room_id', Table::TYPE_INT);
        $this->clientsTable->column('authenticated', Table::TYPE_INT);
        $this->clientsTable->create();
    }

    public function start(): void
    {
        try {
            $this->logger->info("Starting Game Server #{$this->serverId} on {$this->host}:{$this->port}");
            
            // Initialize server
            $this->server = new \OpenSwoole\Server($this->host, $this->port);
            
            // Set server options
            $this->server->set([
                'worker_num' => 4,
                'daemonize' => false,
                'pid_file' => $this->pidFile,
                'max_request' => 10000,
                'dispatch_mode' => 2,
                'debug_mode' => 1,
                'log_level' => SWOOLE_LOG_INFO,
                'log_file' => storage_path("logs/game_server_{$this->serverId}.log"),
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
            $this->server->on('start', [$this, 'onStart']);
            $this->server->on('workerStart', [$this, 'onWorkerStart']);
            $this->server->on('connect', [$this, 'onConnect']);
            $this->server->on('receive', [$this, 'onReceive']);
            $this->server->on('close', [$this, 'onClose']);
            $this->server->on('shutdown', [$this, 'onShutdown']);

            // Create PID file
            file_put_contents($this->pidFile, getmypid());
            chmod($this->pidFile, 0666);
            
            // Create ready file
            file_put_contents($this->readyFile, time());
            chmod($this->readyFile, 0666);

            $this->logger->info("Game Server #{$this->serverId} initialized and ready to start");
            
            // Register with Lobby Server
            $this->connectToLobby();
            
            // Start server (this call blocks)
            $this->isRunning = true;
            $this->server->start();
            
        } catch (Exception $e) {
            $this->logger->error("Failed to start server: " . $e->getMessage());
            $this->logger->error($e->getTraceAsString());
            throw $e;
        }
    }

    public function onStart(\OpenSwoole\Server $server): void
    {
        $this->logger->info("Game Server #{$this->serverId} started on {$this->host}:{$this->port}");
        
        // Create ready file
        file_put_contents($this->readyFile, time());
        chmod($this->readyFile, 0666);
        
        // Set process title
        if (function_exists('cli_set_process_title')) {
            cli_set_process_title("game-server-{$this->serverId}");
        }
    }

    public function onWorkerStart(\OpenSwoole\Server $server, int $workerId): void
    {
        $this->logger->info("Game Server #{$this->serverId} worker process {$workerId} started");
        $this->logger->debug("Worker process ID: " . getmypid());
    }

    public function onConnect(\OpenSwoole\Server $server, int $fd): void
    {
        $this->logger->debug("New connection: {$fd}");
        $this->clients[$fd] = [
            'authenticated' => false,
            'user_id' => null,
            'username' => null,
            'room_id' => null
        ];
    }

    public function onReceive(\OpenSwoole\Server $server, int $fd, int $reactorId, string $data): void
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

            $this->logger->debug("Received message from client {$fd}: " . json_encode($message));

            switch ($message['type']) {
                case MessageType::AUTH->value:
                    $this->handleClientAuth($server, $fd, $message);
                    break;
                case MessageType::JOIN_GAME->value:
                    $this->handleJoinGame($server, $fd, $message);
                    break;
                case MessageType::LEAVE_GAME->value:
                    $this->handleLeaveGame($server, $fd, $message);
                    break;
                case MessageType::GAME_ACTION->value:
                    $this->handleGameAction($server, $fd, $message);
                    break;
                case 'create_room':
                    $this->handleCreateRoom($server, $fd, $message);
                    break;
                case 'join_room':
                    $this->handleJoinRoom($server, $fd, $message);
                    break;
                case 'ping':
                    $this->handlePing($server, $fd);
                    break;
                default:
                    $this->logger->warning("Unknown message type from client {$fd}: {$message['type']}");
                    break;
            }
        } catch (Exception $e) {
            $this->logger->error("Error handling message: " . $e->getMessage());
            $this->logger->error("Stack trace: " . $e->getTraceAsString());
            $this->sendError($server, $fd, "Internal server error: " . $e->getMessage());
        }
    }

    public function onClose(\OpenSwoole\Server $server, int $fd): void
    {
        $this->logger->debug("Connection closed: {$fd}");
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

    public function handleSignal(int $signal): void
    {
        $this->logger->info("Received signal {$signal}, shutting down...");
        $this->stop();
    }

    public function onShutdown(\OpenSwoole\Server $server): void
    {
        $this->logger->info("Game Server #{$this->serverId} shutting down");
        
        // Remove ready file
        if (file_exists($this->readyFile)) {
            unlink($this->readyFile);
        }
        
        $this->isRunning = false;
    }

    private function handlePing(\OpenSwoole\Server $server, int $fd): void
    {
        $this->sendResponse($server, $fd, [
            'type' => 'pong',
            'timestamp' => time()
        ]);
    }

    private function sendResponse(\OpenSwoole\Server $server, int $fd, array $data): void
    {
        try {
            $json = json_encode($data);
            $message = pack('N', strlen($json)) . $json;
            $server->send($fd, $message);
        } catch (Exception $e) {
            $this->logger->error("Error sending response to client {$fd}: " . $e->getMessage());
        }
    }

    private function sendError(\OpenSwoole\Server $server, int $fd, string $message): void
    {
        $this->sendResponse($server, $fd, [
            'type' => MessageType::ERROR->value,
            'message' => $message
        ]);
    }

    public function isRunning(): bool
    {
        if (!file_exists($this->pidFile)) {
            return false;
        }
        
        $pid = (int)file_get_contents($this->pidFile);
        if (!$pid) {
            return false;
        }
        
        // Try to send signal 0 to the process to check if it exists
        try {
            $result = posix_kill($pid, 0);
            if (!$result) {
                // Process doesn't exist, clean up stale PID file
                unlink($this->pidFile);
                return false;
            }
        } catch (Exception $e) {
            return false;
        }
        
        return true;
    }

    public function stop(): void
    {
        try {
            $this->logger->info("Stopping Game Server #{$this->serverId}...");
            
            // Clean up ready file
            if (file_exists($this->readyFile)) {
                unlink($this->readyFile);
            }
            
            // Unregister from lobby server if registered
            if ($this->isRegistered && $this->lobbyConnection && $this->lobbyConnection->isConnected()) {
                $this->sendToLobby([
                    'type' => MessageType::UNREGISTER->value,
                    'server_id' => $this->serverId
                ]);
                $this->lobbyConnection->close();
            }
            
            // Stop the server
            if ($this->server) {
                $this->server->shutdown();
                $this->server = null;
            }
            
            // Clean up PID file
            if (file_exists($this->pidFile)) {
                unlink($this->pidFile);
            }
            
            $this->isRunning = false;
            $this->logger->info("Game Server #{$this->serverId} stopped");
        } catch (Exception $e) {
            $this->logger->error("Error stopping Game Server #{$this->serverId}: " . $e->getMessage());
            $this->logger->error("Stack trace: " . $e->getTraceAsString());
        }
    }

    private function connectToLobby(): void
    {
        try {
            $this->logger->info("Connecting to Lobby Server at {$this->lobbyHost}:{$this->lobbyPort}");
            
            $this->lobbyConnection = new \OpenSwoole\Client(SWOOLE_SOCK_TCP);
            $this->lobbyConnection->set([
                'open_length_check' => true,
                'package_length_type' => 'N',
                'package_length_offset' => 0,
                'package_body_offset' => 4,
                'package_max_length' => 8 * 1024 * 1024, // 8MB
                'socket_buffer_size' => 8 * 1024 * 1024, // 8MB
                'buffer_output_size' => 8 * 1024 * 1024, // 8MB
                'tcp_nodelay' => true,
                'tcp_keepalive' => true
            ]);

            if (!$this->lobbyConnection->connect($this->lobbyHost, $this->lobbyPort, 5)) {
                $this->logger->error("Failed to connect to Lobby Server: " . socket_strerror($this->lobbyConnection->errCode));
                return;
            }

            $this->logger->info("Connected to Lobby Server successfully");
            
            // Register with Lobby Server
            $this->registerWithLobby();

            // Start ping timer
            \OpenSwoole\Timer::tick(30000, function() {
                if ($this->lobbyConnection && $this->lobbyConnection->isConnected() && $this->isRegistered) {
                    $this->sendPingToLobby();
                }
            });
            
            // Start status update timer
            \OpenSwoole\Timer::tick(5000, function() {
                if ($this->lobbyConnection && $this->lobbyConnection->isConnected() && $this->isRegistered) {
                    $this->sendStatusUpdateToLobby();
                }
            });
        } catch (Exception $e) {
            $this->logger->error("Error connecting to Lobby Server: " . $e->getMessage());
            $this->logger->error("Stack trace: " . $e->getTraceAsString());
        }
    }

    private function registerWithLobby(): bool
    {
        try {
            $message = [
                'type' => MessageType::REGISTER->value,
                'server_id' => $this->serverId,
                'ip' => $this->host,
                'port' => $this->port,
                'max_rooms' => $this->maxRooms,
                'timestamp' => time()
            ];

            $this->logger->info("Registering with Lobby Server: " . json_encode($message));
            
            // Create a new connection for registration
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

            if (!$client->connect($this->lobbyHost, $this->lobbyPort, 5)) {
                $this->logger->error("Failed to connect to Lobby Server: " . socket_strerror($client->errCode));
                return false;
            }

            // Send registration message
            $json = json_encode($message);
            if ($json === false) {
                $this->logger->error("Failed to encode registration message: " . json_last_error_msg());
                $client->close();
                return false;
            }

            $packed = pack('N', strlen($json)) . $json;
            $this->logger->debug("Sending registration message: " . $json);
            
            if (!$client->send($packed)) {
                $this->logger->error("Failed to send registration message: " . socket_strerror($client->errCode));
                $client->close();
                return false;
            }

            // Receive response
            $response = $this->receiveFromLobby($client);
            $client->close();

            if (!$response) {
                $this->logger->error("No response from Lobby Server for registration");
                return false;
            }

            if (!isset($response['type']) || $response['type'] !== 'register_success') {
                $this->logger->error("Invalid registration response: " . json_encode($response));
                return false;
            }

            $this->isRegistered = true;
            $this->logger->info("Successfully registered with Lobby Server");
            return true;
        } catch (Exception $e) {
            $this->logger->error("Error registering with Lobby Server: " . $e->getMessage());
            $this->logger->error("Stack trace: " . $e->getTraceAsString());
            return false;
        }
    }

    private function receiveFromLobby(\OpenSwoole\Client $client): ?array
    {
        try {
            // First receive 4-byte length prefix
            $lengthData = $client->recv(4, 1);
            if ($lengthData === false || strlen($lengthData) !== 4) {
                $this->logger->error("Failed to receive length prefix");
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

            $this->logger->debug("Received from Lobby Server: " . json_encode($message));
            return $message;
        } catch (Exception $e) {
            $this->logger->error("Error receiving from Lobby Server: " . $e->getMessage());
            return null;
        }
    }

    private function sendToLobby(array $data): bool
    {
        try {
            // Create a new connection for each message
            $client = new \OpenSwoole\Client(SWOOLE_SOCK_TCP);
            $client->set([
                'open_length_check' => true,
                'package_length_type' => 'N',
                'package_length_offset' => 0,
                'package_body_offset' => 4,
                'package_max_length' => 8 * 1024 * 1024
            ]);

            if (!$client->connect($this->lobbyHost, $this->lobbyPort, 5)) {
                $this->logger->error("Failed to connect to Lobby Server");
                return false;
            }

            $json = json_encode($data);
            if ($json === false) {
                $this->logger->error("Failed to encode message: " . json_last_error_msg());
                $client->close();
                return false;
            }

            // Add length prefix (4 bytes)
            $message = pack('N', strlen($json)) . $json;
            
            $this->logger->debug("Sending to Lobby Server: " . $json);
            $result = $client->send($message);
            
            if ($result === false) {
                $this->logger->error("Failed to send message to Lobby Server");
                $client->close();
                return false;
            }

            $client->close();
            return true;
        } catch (Exception $e) {
            $this->logger->error("Error sending to Lobby Server: " . $e->getMessage());
            return false;
        }
    }

    private function sendPingToLobby(): bool
    {
        $message = [
            'type' => MessageType::PING->value,
            'server_id' => $this->serverId,
            'timestamp' => microtime(true)
        ];
        return $this->sendToLobby($message);
    }

    private function sendStatusUpdateToLobby(): bool
    {
        $message = [
            'type' => 'status_update',
            'server_id' => $this->serverId,
            'load' => $this->calculateServerLoad(),
            'rooms_count' => count($this->rooms),
            'players_count' => count($this->clients),
            'timestamp' => microtime(true)
        ];
        return $this->sendToLobby($message);
    }

    private function calculateServerLoad(): int
    {
        $roomCount = 0;
        $playerCount = 0;
        
        foreach ($this->roomsTable as $room) {
            $roomCount++;
            $playerCount += $room['player_count'];
        }
        
        // Simple load calculation based on room and player count
        return ($roomCount * 10) + $playerCount;
    }

    private function handleClientAuth(\OpenSwoole\Server $server, int $fd, array $message): void
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

            $this->clients[$fd]['authenticated'] = true;
            $this->clients[$fd]['user_id'] = $userData['id'];
            $this->clients[$fd]['username'] = $userData['username'];

            $this->clientsTable->set($fd, [
                'fd' => $fd,
                'user_id' => $userData['id'],
                'username' => $userData['username'],
                'room_id' => 0,
                'authenticated' => 1
            ]);

            $this->sendResponse($server, $fd, [
                'type' => MessageType::AUTH_SUCCESS->value,
                'user_id' => $userData['id'],
                'username' => $userData['username']
            ]);

            $this->logger->info("Client {$fd} authenticated as user {$userData['id']} ({$userData['username']})");
        } catch (Exception $e) {
            $this->logger->error("Authentication error for client {$fd}: " . $e->getMessage());
            $this->sendError($server, $fd, $e->getMessage());
        }
    }

    private function validateToken(string $token): ?array
    {
        // This is a placeholder for token validation
        // In a real application, validate the token with your authentication system
        
        // For testing, return dummy user data
        return [
            'id' => rand(1, 1000),
            'username' => 'user_' . rand(1000, 9999)
        ];
    }

    private function handleJoinGame(\OpenSwoole\Server $server, int $fd, array $message): void
    {
        try {
            if (!isset($message['room_id'])) {
                throw new Exception("Missing room_id parameter");
            }

            $client = $this->clients[$fd] ?? null;
            if (!$client || !$client['authenticated']) {
                throw new Exception("Client not authenticated");
            }

            $roomId = $message['room_id'];
            $room = $this->roomsTable->get($roomId);
            
            if (!$room) {
                throw new Exception("Room {$roomId} not found");
            }

            if ($room['status'] !== 'waiting') {
                throw new Exception("Game already in progress");
            }

            if ($room['player_count'] >= $room['max_players']) {
                throw new Exception("Room is full");
            }

            // Add player to room
            $players = json_decode($room['players'], true);
            $players[] = $client['user_id'];

            $this->roomsTable->set($roomId, [
                'player_count' => count($players),
                'players' => json_encode($players)
            ]);

            $this->clients[$fd]['room_id'] = $roomId;
            $this->clientsTable->set($fd, ['room_id' => $roomId]);
            
            $this->playersTable->set($client['user_id'], [
                'id' => $client['user_id'],
                'user_id' => $client['user_id'],
                'username' => $client['username'],
                'room_id' => $roomId,
                'status' => 'waiting'
            ]);

            // Notify client that they've joined the room
            $this->sendResponse($server, $fd, [
                'type' => MessageType::PLAYER_JOINED->value,
                'room_id' => $roomId,
                'player_id' => $client['user_id'],
                'player_name' => $client['username']
            ]);

            // Notify other players in the room
            $this->notifyRoomPlayers($server, $roomId, [
                'type' => MessageType::PLAYER_JOINED->value,
                'room_id' => $roomId,
                'player_id' => $client['user_id'],
                'player_name' => $client['username']
            ], [$client['user_id']]);

            $this->logger->info("Player {$client['user_id']} ({$client['username']}) joined room {$roomId}");
        } catch (Exception $e) {
            $this->logger->error("Error joining game for client {$fd}: " . $e->getMessage());
            $this->sendError($server, $fd, $e->getMessage());
        }
    }

    private function handleLeaveGame(\OpenSwoole\Server $server, int $fd, array $message): void
    {
        try {
            $client = $this->clients[$fd] ?? null;
            if (!$client) {
                return; // Client already disconnected
            }

            $roomId = $client['room_id'] ?? null;
            if (!$roomId) {
                return; // Client not in a room
            }

            $room = $this->roomsTable->get($roomId);
            if (!$room) {
                // Room doesn't exist anymore
                $this->clients[$fd]['room_id'] = null;
                $this->clientsTable->set($fd, ['room_id' => 0]);
                return;
            }

            // Remove player from room
            $players = json_decode($room['players'], true);
            $players = array_filter($players, function($playerId) use ($client) {
                return $playerId !== $client['user_id'];
            });

            if (empty($players)) {
                // Room is empty, remove it
                $this->roomsTable->del($roomId);
                $this->logger->info("Room {$roomId} removed (empty)");
            } else {
                // Update room player count
                $this->roomsTable->set($roomId, [
                    'player_count' => count($players),
                    'players' => json_encode($players)
                ]);

                // Notify other players
                $this->notifyRoomPlayers($server, $roomId, [
                    'type' => MessageType::PLAYER_LEFT->value,
                    'room_id' => $roomId,
                    'player_id' => $client['user_id'],
                    'player_name' => $client['username']
                ]);
            }

            // Update client state
            $this->clients[$fd]['room_id'] = null;
            $this->clientsTable->set($fd, ['room_id' => 0]);
            
            // Remove from players table
            $this->playersTable->del($client['user_id']);

            // Notify client
            $this->sendResponse($server, $fd, [
                'type' => MessageType::LEAVE_ROOM->value,
                'room_id' => $roomId
            ]);

            $this->logger->info("Player {$client['user_id']} ({$client['username']}) left room {$roomId}");
        } catch (Exception $e) {
            $this->logger->error("Error leaving game for client {$fd}: " . $e->getMessage());
            $this->sendError($server, $fd, $e->getMessage());
        }
    }

    private function handleGameAction(\OpenSwoole\Server $server, int $fd, array $message): void
    {
        try {
            if (!isset($message['action'])) {
                throw new Exception("Missing action parameter");
            }

            $client = $this->clients[$fd] ?? null;
            if (!$client || !$client['authenticated']) {
                throw new Exception("Client not authenticated");
            }

            $roomId = $client['room_id'] ?? null;
            if (!$roomId) {
                throw new Exception("Client not in a room");
            }

            $room = $this->roomsTable->get($roomId);
            if (!$room) {
                throw new Exception("Room not found");
            }

            // Process game action based on action type
            switch ($message['action']) {
                case 'play_card':
                    $this->handlePlayCard($server, $fd, $roomId, $client['user_id'], $message['data'] ?? []);
                    break;
                case 'end_turn':
                    $this->handleEndTurn($server, $fd, $roomId, $client['user_id']);
                    break;
                case 'surrender':
                    $this->handleSurrender($server, $fd, $roomId, $client['user_id']);
                    break;
                default:
                    throw new Exception("Unknown game action: {$message['action']}");
            }
        } catch (Exception $e) {
            $this->logger->error("Error processing game action for client {$fd}: " . $e->getMessage());
            $this->sendError($server, $fd, $e->getMessage());
        }
    }

    private function handlePlayCard(\OpenSwoole\Server $server, int $fd, int $roomId, int $playerId, array $data): void
    {
        if (!isset($data['card_id'])) {
            throw new Exception("Missing card_id parameter");
        }

        $cardId = $data['card_id'];
        
        // Here you would implement your game logic
        // For now, we'll just broadcast the action to all players in the room
        
        $this->notifyRoomPlayers($server, $roomId, [
            'type' => MessageType::GAME_ACTION->value,
            'action' => 'card_played',
            'player_id' => $playerId,
            'card_id' => $cardId
        ]);

        $this->logger->info("Player {$playerId} played card {$cardId} in room {$roomId}");
    }

    private function handleEndTurn(\OpenSwoole\Server $server, int $fd, int $roomId, int $playerId): void
    {
        // Here you would implement your end turn game logic
        // For now, we'll just broadcast the action to all players in the room
        
        $this->notifyRoomPlayers($server, $roomId, [
            'type' => MessageType::GAME_ACTION->value,
            'action' => 'turn_ended',
            'player_id' => $playerId
        ]);

        $this->logger->info("Player {$playerId} ended turn in room {$roomId}");
    }

    private function handleSurrender(\OpenSwoole\Server $server, int $fd, int $roomId, int $playerId): void
    {
        // Here you would implement your surrender game logic
        // For now, we'll just broadcast the action to all players in the room
        
        $this->notifyRoomPlayers($server, $roomId, [
            'type' => MessageType::GAME_ACTION->value,
            'action' => 'player_surrendered',
            'player_id' => $playerId
        ]);

        $this->logger->info("Player {$playerId} surrendered in room {$roomId}");
    }

    private function notifyRoomPlayers(\OpenSwoole\Server $server, int $roomId, array $message, array $excludePlayers = []): void
    {
        $room = $this->roomsTable->get($roomId);
        if (!$room) {
            return;
        }

        $players = json_decode($room['players'], true);
        foreach ($players as $playerId) {
            if (in_array($playerId, $excludePlayers)) {
                continue;
            }

            // Find client connection for this player
            foreach ($this->clientsTable as $fd => $client) {
                if ($client['user_id'] == $playerId) {
                    $this->sendResponse($server, $fd, $message);
                    break;
                }
            }
        }
    }

    private function generateRoomId(): int
    {
        return mt_rand(1000, 9999);
    }

    private function findRoomByPlayer(int $playerId): ?array
    {
        foreach ($this->roomsTable as $roomId => $room) {
            $players = json_decode($room['players'], true);
            if (in_array($playerId, $players)) {
                return $room;
            }
        }
        return null;
    }

    private function handleCreateRoom(\OpenSwoole\Server $server, int $fd, array $message): void
    {
        try {
            if (!isset($message['game_type']) || !isset($message['max_players'])) {
                throw new Exception('Missing required parameters');
            }

            if (!isset($message['user_id']) || !isset($message['username'])) {
                throw new Exception('Missing user information');
            }

            $userId = $message['user_id'];
            $username = $message['username'];
            $gameType = $message['game_type'];
            $maxPlayers = $message['max_players'];

            // Generate a unique room ID
            $roomId = $this->generateRoomId();

            // Create the room in our table
            $this->roomsTable->set($roomId, [
                'id' => $roomId,
                'game_type' => $gameType,
                'status' => 'waiting',
                'player_count' => 1,
                'max_players' => $maxPlayers,
                'players' => json_encode([$userId])
            ]);

            // Add player to the room
            $this->playersTable->set($userId, [
                'id' => $userId,
                'user_id' => $userId,
                'username' => $username,
                'room_id' => $roomId,
                'status' => 'waiting'
            ]);

            // Send response to the client
            $this->sendResponse($server, $fd, [
                'type' => 'room_created',
                'room_id' => $roomId,
                'game_type' => $gameType,
                'max_players' => $maxPlayers
            ]);

            $this->logger->info("Room {$roomId} created for game type {$gameType} with max {$maxPlayers} players");
        } catch (Exception $e) {
            $this->logger->error("Error creating room: " . $e->getMessage());
            $this->sendError($server, $fd, $e->getMessage());
        }
    }

    private function handleJoinRoom(\OpenSwoole\Server $server, int $fd, array $message): void
    {
        try {
            if (!isset($message['room_id']) || !isset($message['user_id']) || !isset($message['username'])) {
                throw new Exception('Missing required parameters');
            }

            $roomId = $message['room_id'];
            $userId = $message['user_id'];
            $username = $message['username'];

            // Check if the room exists
            $room = $this->roomsTable->get($roomId);
            if (!$room) {
                throw new Exception("Room {$roomId} not found");
            }

            // Check if the room is full
            if ($room['player_count'] >= $room['max_players']) {
                throw new Exception("Room {$roomId} is full");
            }

            // Check if the room is in correct state
            if ($room['status'] !== 'waiting') {
                throw new Exception("Room {$roomId} is not accepting new players");
            }

            // Add player to the room
            $players = json_decode($room['players'], true);
            $players[] = $userId;

            $this->roomsTable->set($roomId, [
                'id' => $roomId,
                'game_type' => $room['game_type'],
                'status' => 'waiting',
                'player_count' => count($players),
                'max_players' => $room['max_players'],
                'players' => json_encode($players)
            ]);

            // Store player information
            $this->playersTable->set($userId, [
                'id' => $userId,
                'user_id' => $userId,
                'username' => $username,
                'room_id' => $roomId,
                'status' => 'waiting'
            ]);

            // Send response to the client
            $this->sendResponse($server, $fd, [
                'type' => 'room_joined',
                'room_id' => $roomId,
                'player_id' => $userId
            ]);

            $this->logger->info("Player {$userId} ({$username}) joined room {$roomId}");

            // Notify other players in the room
            $this->notifyRoomPlayers($server, $roomId, [
                'type' => MessageType::PLAYER_JOINED->value,
                'room_id' => $roomId,
                'player_id' => $userId,
                'player_name' => $username
            ], [$userId]);

            // Check if room is full and should start
            if (count($players) >= $room['max_players']) {
                $this->startGame($server, $roomId);
            }
        } catch (Exception $e) {
            $this->logger->error("Error joining room: " . $e->getMessage());
            $this->sendError($server, $fd, $e->getMessage());
        }
    }

    private function startGame(\OpenSwoole\Server $server, int $roomId): void
    {
        try {
            $room = $this->roomsTable->get($roomId);
            if (!$room) {
                throw new Exception("Room {$roomId} not found");
            }

            // Update room status
            $this->roomsTable->set($roomId, [
                'id' => $roomId,
                'game_type' => $room['game_type'],
                'status' => 'playing',
                'player_count' => $room['player_count'],
                'max_players' => $room['max_players'],
                'players' => $room['players']
            ]);

            // Notify all players
            $players = json_decode($room['players'], true);
            foreach ($players as $playerId) {
                // Find player's connection
                foreach ($this->clientsTable as $fd => $client) {
                    if ($client['user_id'] == $playerId) {
                        $this->sendResponse($server, $fd, [
                            'type' => MessageType::GAME_STARTED->value,
                            'room_id' => $roomId
                        ]);
                        break;
                    }
                }
            }

            $this->logger->info("Game started in room {$roomId}");
        } catch (Exception $e) {
            $this->logger->error("Error starting game: " . $e->getMessage());
        }
    }
}