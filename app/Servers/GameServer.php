<?php

namespace App\Servers;

use Ratchet\Client\WebSocket;
use Ratchet\Client\Connector;
use Ratchet\RFC6455\Messaging\MessageInterface;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use App\Servers\Utilities\Logger;
use App\Services\WebSocketAuthService;
use Exception;
use App\Servers\Utilities\GameUtilities;

class GameServer
{
    private $loop;
    private $connector;
    private $conn;
    private $serverId;
    private $serverToken;
    private $wsUrl;
    private $players = [];
    private $rooms = [];
    private Logger $logger;
    private int $capacity;
    private bool $isRunning = false;
    private WebSocketAuthService $auth;
    private bool $isConnected = false;
    private bool $isAuthenticated = false;
    private bool $isRegistered = false;
    private int $lastPong = 0;
    private array $heartbeatTimer;

    public function __construct(string $wsUrl = '', string $serverId = '', int $capacity = 1000)
    {
        $this->wsUrl = $wsUrl ?: 'ws://localhost:9502';
        $this->serverId = $serverId ?: uniqid('game_server_', true);
        $this->capacity = $capacity;
        $this->serverToken = $this->generateToken();
        $this->connector = new Connector(Loop::get());
        $this->logger = new Logger($serverId);
        $this->auth = new WebSocketAuthService();
        
        // Pokaż PID procesu w logach
        Logger::showPid(true);
        
        $this->logger->info("GameServer initialized", [
            'server_id' => $this->serverId,
            'ws_url' => $this->wsUrl,
            'capacity' => $this->capacity
        ]);
    }

    private function generateToken(): string
    {
        return bin2hex(random_bytes(16));
    }

    public function start(): void
    {
        $this->logger->info("Starting game server", ['server_id' => $this->serverId]);
        
        // Generate a server token
        $this->serverToken = $this->generateToken();
        $this->logger->info("Server token generated", ['server_id' => $this->serverId]);
        
        // Connect to the main WebSocket server
        $this->connect();
        
        // Start the main event loop
        Loop::run();
    }

    private function connect(): void
    {
        $this->logger->info("Connecting to WebSocket server", ['ws_url' => $this->wsUrl]);
        
        $this->connector->__invoke($this->wsUrl)
            ->then(
                function (WebSocket $conn) {
                    $this->conn = $conn;
                    $this->isConnected = true;
                    $this->logger->info("Connected to WebSocket server");
                    
                    // Setup message handlers
                    $conn->on('message', function (MessageInterface $msg) {
                        $this->handleMessage($msg);
                    });
                    
                    $conn->on('close', function ($code = null, $reason = null) {
                        $this->handleDisconnect($code, $reason);
                    });
                    
                    // Authenticate with the server
                    $this->authenticate();
                },
                function (Exception $e) {
                    $this->logger->error("Could not connect to WebSocket server", [
                        'error' => $e->getMessage()
                    ]);
                    $this->reconnect();
                }
            );
    }

    private function authenticate(): void
    {
        if (!$this->isConnected) {
            $this->logger->error("Cannot authenticate: Not connected");
            return;
        }

        $this->logger->info("Authenticating with WebSocket server", [
            'server_id' => $this->serverId,
            'capacity' => $this->capacity
        ]);

        // Get server token from auth service
        $this->serverToken = $this->auth->generateServerToken();

        // Send authentication message
        $authData = [
            'type' => 'authenticate',
            'token' => $this->serverToken,
            'server_id' => $this->serverId,
            'capacity' => $this->capacity,
            'auth_type' => 'server'
        ];

        $this->logger->debug("Sending authentication data", [
            'server_id' => $this->serverId,
            'auth_type' => 'server',
            'token_length' => strlen($this->serverToken)
        ]);

        $this->send($authData);
    }

    private function handleMessage(MessageInterface $msg): void
    {
        try {
            $data = json_decode($msg->getPayload(), true);
            if (!$data) {
                $this->logger->error("Invalid message format");
                return;
            }

            $this->logger->debug("Received message", ['type' => $data['type'] ?? 'unknown']);

            switch ($data['type']) {
                case 'auth_success':
                    $this->handleAuthSuccess($data);
                    break;
                case 'auth_error':
                    $this->handleAuthError($data);
                    break;
                case 'pong':
                    $this->handlePong();
                    break;
                case 'connection':
                    // Connection confirmation from server
                    break;
                    
                case 'player_join':
                    $this->handlePlayerJoin($data);
                    break;
                    
                case 'player_leave':
                    $this->handlePlayerLeave($data);
                    break;
                    
                case 'game_action':
                    $this->handleGameAction($data);
                    break;
                    
                case 'create_room':
                    $this->handleCreateRoom($data);
                    break;
                    
                case 'add_player_to_room':
                    $this->handleAddPlayerToRoom($data);
                    break;
                    
                case 'server_registered':
                    $this->isRegistered = true;
                    $this->logger->info("Server registered successfully", [
                        'server_id' => $data['server_id']
                    ]);
                    break;

                case 'heartbeat_ack':
                    $this->lastPong = time();
                    $this->logger->debug("Heartbeat acknowledged");
                    break;

                case 'register_ack':
                    $this->logger->info("Game server registered successfully");
                    break;

                case 'token_refreshed':
                    $this->serverToken = $data['token'];
                    $this->logger->info("Server token refreshed");
                    break;
                
                case 'room_update':
                    $this->handleRoomUpdate($data);
                    break;
                    
                default:
                    $this->logger->warning("Unknown message type", ['type' => $data['type']]);
            }
        } catch (\Exception $e) {
            $this->logger->error("Error handling message", [
                'error' => $e->getMessage()
            ]);
        }
    }

    private function handleAuthSuccess(array $data): void
    {
        $this->isAuthenticated = true;
        $this->logger->info("Authentication successful", [
            'server_id' => $this->serverId
        ]);

        // Register server with WebSocket server
        $this->send([
            'type' => 'register_server',
            'server_id' => $this->serverId,
            'capacity' => $this->capacity
        ]);

        // Start sending heartbeats
        $this->startHeartbeats();
    }

    private function handleAuthError(array $data): void
    {
        $this->logger->error("Authentication failed", [
            'error' => $data['message'] ?? 'Unknown error',
            'server_id' => $this->serverId,
            'token' => $this->serverToken
        ]);
        $this->isAuthenticated = false;
    }

    private function startHeartbeats(): void
    {
        // Send heartbeat every 30 seconds
        $this->heartbeatTimer = Loop::addPeriodicTimer(30, function () {
            if ($this->isConnected && $this->isAuthenticated) {
                $this->send(['type' => 'ping']);
            }
        });
    }

    private function handlePong(): void
    {
        $this->lastPong = time();
    }

    private function send(array $data): void
    {
        if (!$this->isConnected || !$this->conn) {
            $this->logger->error("Cannot send message: Not connected");
            return;
        }

        try {
            $this->conn->send(json_encode($data));
            $this->logger->debug("Message sent", [
                'type' => $data['type'] ?? 'unknown',
                'server_id' => $this->serverId
            ]);
        } catch (\Exception $e) {
            $this->logger->error("Error sending message", [
                'error' => $e->getMessage()
            ]);
        }
    }

    private function handleDisconnect($code = null, $reason = null): void
    {
        $this->isConnected = false;
        $this->isAuthenticated = false;
        $this->logger->info("Disconnected from WebSocket server", [
            'code' => $code,
            'reason' => $reason
        ]);

        // Try to reconnect after a delay
        $this->reconnect();
    }

    private function reconnect(): void
    {
        $this->logger->info("Attempting to reconnect...");
        
        // Wait 5 seconds before reconnecting
        Loop::addTimer(5, function () {
            $this->connect();
        });
    }

    private function handlePlayerJoin(array $data): void
    {
        $this->logger->info("Player joining", ['data' => $data]);
        
        $playerId = $data['player_id'];
        $roomId = $data['room_id'] ?? null;

        if ($roomId && !isset($this->rooms[$roomId])) {
            $this->rooms[$roomId] = [
                'players' => [],
                'state' => 'waiting'
            ];
        }

        $this->players[$playerId] = [
            'room_id' => $roomId,
            'status' => 'connected'
        ];

        if ($roomId) {
            $this->rooms[$roomId]['players'][] = $playerId;
        }
    }

    private function handlePlayerLeave(array $data): void
    {
        $this->logger->info("Player leaving", ['data' => $data]);
        
        $playerId = $data['player_id'];
        $roomId = $this->players[$playerId]['room_id'] ?? null;

        if ($roomId && isset($this->rooms[$roomId])) {
            $this->rooms[$roomId]['players'] = array_diff(
                $this->rooms[$roomId]['players'],
                [$playerId]
            );
        }

        unset($this->players[$playerId]);
    }

    private function handleGameAction(array $data): void
    {
        $this->logger->info("Game action received", ['data' => $data]);
        
        $playerId = $data['player_id'];
        $roomId = $this->players[$playerId]['room_id'] ?? null;

        if (!$roomId || !isset($this->rooms[$roomId])) {
            $this->logger->warning("Invalid game action - player not in room", [
                'player_id' => $playerId,
                'room_id' => $roomId
            ]);
            return;
        }

        // Process game action
        $this->processGameAction($roomId, $playerId, $data['action']);
    }

    private function processGameAction(string $roomId, string $playerId, array $action): void
    {
        $this->logger->debug("Processing game action", [
            'room_id' => $roomId,
            'player_id' => $playerId,
            'action' => $action
        ]);
        
        // Implement game-specific action processing here
    }

    private function processRoom(string $roomId, array $room): void
    {
        // Process room state
        if (count($room['players']) >= 2) {
            // Start game if enough players
            $this->startGame($roomId);
        }
    }

    private function startGame(string $roomId): void
    {
        $this->logger->info("Starting game in room", ['room_id' => $roomId]);
        
        // Send game start message to all players
        $this->broadcastToRoom($roomId, [
            'type' => 'game_start',
            'room_id' => $roomId
        ]);
    }

    private function broadcastToRoom(string $roomId, array $message): void
    {
        $this->logger->debug("Broadcasting message to room", ['room_id' => $roomId, 'message' => $message]);
        
        if (isset($this->rooms[$roomId])) {
            foreach ($this->rooms[$roomId]['players'] as $playerId) {
                $this->sendMessage([
                    'type' => 'game_action',
                    'player_id' => $playerId,
                    'room_id' => $roomId,
                    'action' => $message['action']
                ]);
            }
        } else {
            $this->logger->warning("Room not found", ['room_id' => $roomId]);
        }
    }

    private function sendMessage(array $message): void
    {
        $this->logger->debug("Sending message", ['message' => $message]);
        
        try {
            if ($this->conn) {
                $this->conn->send(json_encode($message));
            } else {
                $this->logger->warning("Cannot send message - WebSocket not connected");
            }
        } catch (\Exception $e) {
            $this->logger->error("Failed to send message", [
                'error' => $e->getMessage(),
                'message' => $message
            ]);
        }
    }

    public function stop(): void
    {
        $this->logger->info("Stopping game server");
        $this->isRunning = false;
        
        if ($this->conn) {
            $this->conn->close();
        }
        
        Loop::stop();
        $this->auth->revokeServerToken($this->serverId);
        $this->logger->info("Game server stopped", ['server_id' => $this->serverId]);
    }

    private function startGameLoop(): void
    {
        $this->logger->info("Starting game loop");
        
        while ($this->isRunning) {
            try {
                // Process game logic here
                $this->processGameLogic();
                
                // Sleep for a short time to prevent CPU overload
                usleep(100000); // 100ms
            } catch (\Exception $e) {
                $this->logger->error("Error in game loop", [
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    private function processGameLogic(): void
    {
        // Process game rooms
        foreach ($this->rooms as $roomId => $room) {
            try {
                // Process room logic here
                $this->processRoom($roomId, $room);
            } catch (\Exception $e) {
                $this->logger->error("Error processing room", [
                    'room_id' => $roomId,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    /**
     * Handle room update messages
     */
    private function handleRoomUpdate(array $data): void
    {
        $roomId = $data['room_id'] ?? null;
        if (!$roomId) {
            $this->logger->warning("Room update missing room_id");
            return;
        }

        $this->logger->info("Room update received", [
            'room_id' => $roomId,
            'players_count' => count($data['players'] ?? []),
            'room_name' => $data['room_name'] ?? 'Unknown'
        ]);

        // Store or update room in local cache
        $this->rooms[$roomId] = [
            'name' => $data['room_name'] ?? 'Room ' . $roomId,
            'players' => $data['players'] ?? [],
            'current_players' => $data['current_players'] ?? 0,
            'max_players' => $data['max_players'] ?? 0,
            'last_updated' => time()
        ];

        // Debug log the players in the room
        if (isset($data['players']) && is_array($data['players'])) {
            foreach ($data['players'] as $index => $player) {
                $this->logger->debug("Room player info", [
                    'index' => $index,
                    'user_id' => $player['user_id'] ?? 'unknown',
                    'username' => $player['username'] ?? 'unknown',
                    'status' => $player['status'] ?? 'unknown',
                    'ready' => $player['ready'] ?? false
                ]);
            }
        }
    }

    /**
     * Register a room with the WebSocket server
     */
    private function registerRoomWithWebSocketServer(string $roomId, array $roomData): void
    {
        $this->logger->info("Registering room with WebSocket server", [
            'room_id' => $roomId,
            'server_id' => $this->serverId
        ]);
        
        $this->sendMessage([
            'type' => 'register_room',
            'room_id' => $roomId,
            'server_id' => $this->serverId,
            'room_data' => [
                'name' => $roomData['name'],
                'status' => $roomData['status'],
                'max_players' => $roomData['max_players'],
                'current_players' => $roomData['current_players'] ?? 0,
                'players' => $roomData['players'] ?? []
            ]
        ]);
    }

    /**
     * Create a new room and register it with the WebSocket server
     */
    public function createRoom(string $roomId, array $roomData): void
    {
        $this->logger->info("Creating new room", [
            'room_id' => $roomId,
            'name' => $roomData['name'] ?? 'Unknown'
        ]);
        
        // Store room locally
        $this->rooms[$roomId] = $roomData;
        
        // Register with WebSocket server
        $this->registerRoomWithWebSocketServer($roomId, $roomData);
    }

    /**
     * Handle room assignment from WebSocket server
     */
    private function handleAssignRoom(array $data): void
    {
        if (!isset($data['room_id']) || !isset($data['room_data'])) {
            $this->logger->warning("Invalid assign_room data");
            return;
        }

        $roomId = $data['room_id'];
        $roomData = $data['room_data'];

        $this->logger->info("Room assigned to this server", [
            'room_id' => $roomId,
            'name' => $roomData['name'] ?? 'Unknown',
            'max_players' => $roomData['max_players'] ?? 0
        ]);

        // Store room locally
        $this->rooms[$roomId] = [
            'name' => $roomData['name'] ?? 'Room ' . $roomId,
            'status' => $roomData['status'] ?? 'waiting',
            'max_players' => $roomData['max_players'] ?? 4,
            'players' => $roomData['players'] ?? [],
            'current_players' => count($roomData['players'] ?? []),
            'last_updated' => time()
        ];

        // Acknowledge the assignment
        $this->sendMessage([
            'type' => 'room_assignment_ack',
            'room_id' => $roomId,
            'server_id' => $this->serverId
        ]);
    }

    /**
     * Handle a request to create a new room
     */
    private function handleCreateRoom(array $data): void
    {
        if (!isset($data['room_id']) || !isset($data['room_data'])) {
            $this->logger->warning("Invalid create_room data");
            return;
        }

        $roomId = $data['room_id'];
        $roomData = $data['room_data'];

        $this->logger->info("Creating room", [
            'room_id' => $roomId,
            'name' => $roomData['name'] ?? 'Unknown'
        ]);

        // Store room locally
        $this->rooms[$roomId] = [
            'name' => $roomData['name'] ?? 'Room ' . $roomId,
            'status' => $roomData['status'] ?? 'waiting',
            'max_players' => $roomData['max_players'] ?? 4,
            'players' => [],
            'current_players' => 0,
            'is_private' => $roomData['is_private'] ?? false,
            'private_code' => $roomData['private_code'] ?? null,
            'creator_id' => $roomData['creator_id'] ?? null,
            'creator_username' => $roomData['creator_username'] ?? null,
            'last_updated' => time(),
            'game_data' => [
                'deck' => GameUtilities::createNewDeck(),
                'current_player' => null,
                'status' => 'waiting'
            ]
        ];

        // Acknowledge room creation
        $this->sendMessage([
            'type' => 'room_created_ack',
            'room_id' => $roomId,
            'server_id' => $this->serverId
        ]);
    }

    /**
     * Handle adding a player to an existing room
     */
    private function handleAddPlayerToRoom(array $data): void
    {
        if (!isset($data['room_id']) || !isset($data['player_id']) || !isset($data['player_fd']) || !isset($data['username'])) {
            $this->logger->warning("Invalid add_player_to_room data");
            return;
        }

        $roomId = $data['room_id'];
        $playerId = $data['player_id'];
        $playerFd = $data['player_fd'];
        $username = $data['username'];

        if (!isset($this->rooms[$roomId])) {
            $this->logger->warning("Room not found for adding player", ['room_id' => $roomId]);
            return;
        }

        $this->logger->info("Adding player to room", [
            'room_id' => $roomId,
            'player_id' => $playerId,
            'username' => $username
        ]);

        // Add player to room
        if (!in_array($playerId, $this->rooms[$roomId]['players'])) {
            $this->rooms[$roomId]['players'][] = $playerId;
            $this->rooms[$roomId]['current_players'] = count($this->rooms[$roomId]['players']);
            $this->rooms[$roomId]['last_updated'] = time();
        }

        // Store player data
        $this->players[$playerId] = [
            'user_id' => $playerId,
            'username' => $username,
            'room_id' => $roomId,
            'fd' => $playerFd,
            'status' => 'not_ready',
            'last_activity' => time()
        ];

        // Acknowledge player added
        $this->sendMessage([
            'type' => 'player_added_to_room_ack',
            'room_id' => $roomId,
            'player_id' => $playerId,
            'server_id' => $this->serverId
        ]);
    }
}
