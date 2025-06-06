<?php

namespace App\Servers;

use OpenSwoole\WebSocket\Server;
use OpenSwoole\Http\Request;
use OpenSwoole\Http\Response;
use OpenSwoole\WebSocket\Frame;
use OpenSwoole\Coroutine;
use App\Servers\Storage\RoomStorage;
use App\Servers\Storage\GameServerStorage;
use App\Services\WebSocketAuthService;
use App\Models\User;
use App\Servers\Utilities\Logger;
use App\Servers\Handlers\RoomHandler;
use App\Servers\Handlers\AuthenticationHandler;
use App\Servers\Handlers\GameHandler;
use App\Servers\Storage\MemoryStorage;
use App\Servers\Handlers\ServerHandler;

class WebSocketServer
{
    private Server $server;
    private RoomStorage $roomStorage;
    private GameServerStorage $gameServerStorage;
    private array $clients = [];
    private array $gameServers = [];
    private Logger $logger;
    private WebSocketAuthService $auth;
    private array $rooms = [];
    private array $authenticatedClients = [];
    private MemoryStorage $storage;
    private array $roomServers = [];
    private array $pendingRoomJoins = [];
    private RoomHandler $roomHandler;
    private AuthenticationHandler $authHandler;
    private GameHandler $gameHandler;
    private ServerHandler $serverHandler;

    public function __construct(string $host, int $port, Logger $logger)
    {
        $this->server = new Server($host, $port);
        $this->auth = new WebSocketAuthService();
        $this->storage = new MemoryStorage();
        $this->logger = $logger;
        
        // Pokaż PID procesu w logach
        Logger::showPid(true);
        
        // Initialize handlers
        $this->roomHandler = new RoomHandler($this->server, $this->storage, $this->logger);
        $this->authHandler = new AuthenticationHandler($this->storage);
        $this->gameHandler = new GameHandler($this->server, $this->storage, $this->logger);
        $this->serverHandler = new ServerHandler($this->server, $this->storage, $this->logger);
        
        $this->setupEventHandlers();
    }

    private function setupEventHandlers(): void
    {
        $this->server->on('start', [$this, 'onStart']);
        $this->server->on('connect', [$this, 'onConnect']);
        $this->server->on('open', [$this, 'onOpen']);
        $this->server->on('message', [$this, 'onMessage']);
        $this->server->on('close', [$this, 'onClose']);
    }

    public function onStart(Server $server): void
    {
        $this->logger->info("WebSocket server started");
    }

    public function onOpen(Server $server, Request $request): void
    {
        $this->logger->info("Client connected", ['fd' => $request->fd]);
        // Initialize as a generic connection instead of forcing 'client' type
        // This avoids overriding server registrations that happen later
        $this->clients[$request->fd] = [
            'type' => 'connection', // Changed from 'client' to 'connection'
            'room_id' => null
        ];
        
        // Debug current connections
        $serverCount = 0;
        $clientCount = 0;
        foreach ($this->clients as $fd => $client) {
            if (isset($client['type']) && $client['type'] === 'server') {
                $serverCount++;
            } else if (isset($client['type']) && ($client['type'] === 'connection' || $client['type'] === 'client')) {
                $clientCount++;
            }
        }
        
        $this->logger->debug("Connection statistics", [
            'total_connections' => count($this->clients),
            'server_connections' => $serverCount,
            'client_connections' => $clientCount,
            'gameServers_count' => count($this->gameServers)
        ]);
    }

    public function onMessage(Server $server, Frame $frame): void
    {
        $data = json_decode($frame->data, true);
        if (!$data || !isset($data['type'])) {
            $this->sendError($frame->fd, 'Invalid message format');
            return;
        }

        switch ($data['type']) {
            case 'auth':
                $this->authHandler->handleAuthentication($server, $frame->fd, $data);
                break;
            case 'matchmaking_join':
                $this->handleMatchmakingJoin($frame->fd, $data);
                break;
            case 'game_action':
                $this->gameHandler->handleGameAction($server, $frame->fd, $data);
                break;
            case 'register_server':
                $this->serverHandler->handleRegisterServer($frame->fd, $data);
                break;
            case 'register_room':
                $this->handleRegisterRoom($data);
                break;
            case 'room_created_ack':
                $this->handleRoomCreatedAck($data);
                break;
            case 'player_added_to_room_ack':
                $this->handlePlayerAddedToRoomAck($data);
                break;
            default:
                $this->sendError($frame->fd, 'Unknown message type');
        }
    }

    public function onConnect(Server $server, int $fd): void
    {
        // Intentionally left empty as onOpen already logs the connection
    }

    private function handleAuthentication(int $fd, array $data): void
    {
        try {
            $token = $data['token'] ?? null;
            if (!$token) {
                $this->logger->warning("Missing authentication token", ['fd' => $fd]);
                $this->sendError($fd, "Missing authentication token");
                return;
            }

            $authData = $this->auth->validateToken($token);
            if (!$authData) {
                // For server tokens, try to generate a new one
                if (isset($data['type']) && $data['type'] === 'server') {
                    $this->logger->info("Generating new server token");
                    $newToken = $this->auth->generateServerToken();
                    if ($token === $newToken) {
                        $this->logger->info("Server authenticated", ['fd' => $fd]);
                        $this->clients[$fd] = [
                            'type' => 'server',
                            'authenticated' => true,
                            'last_ping' => time()
                        ];
                        $this->sendMessage($fd, [
                            'type' => 'auth_success',
                            'message' => 'Server authenticated successfully'
                        ]);
                        return;
                    }
                }
                
                $this->logger->warning("Invalid authentication token", ['fd' => $fd]);
                $this->sendError($fd, "Invalid token");
                return;
            }

            // Store client info
            $this->clients[$fd] = [
                'type' => $authData['type'],
                'authenticated' => true,
                'last_ping' => time(),
                'user_id' => $authData['user_id'] ?? null,
                'username' => $authData['username'] ?? null
            ];

            if ($authData['type'] === 'server') {
                $this->logger->info("Server authenticated", ['fd' => $fd]);
                $this->sendMessage($fd, [
                    'type' => 'auth_success',
                    'message' => 'Server authenticated successfully'
                ]);
            } else {
                $this->logger->info("User authenticated", [
                    'fd' => $fd,
                    'user_id' => $authData['user_id'],
                    'username' => $authData['username']
                ]);
                $this->sendMessage($fd, [
                    'type' => 'auth_success',
                    'message' => 'User authenticated successfully',
                    'user' => [
                        'id' => $authData['user_id'],
                        'username' => $authData['username']
                    ]
                ]);
            }
        } catch (\Exception $e) {
            $this->logger->error("Authentication error", [
                'fd' => $fd,
                'error' => $e->getMessage()
            ]);
            $this->sendError($fd, "Authentication failed: " . $e->getMessage());
        }
    }

    private function handleRegisterServer(int $fd, array $data): void
    {
        try {
            $serverId = $data['server_id'] ?? null;
            $capacity = $data['capacity'] ?? null;

            if (!$serverId || !$capacity) {
                $this->logger->warning("Missing server registration data", ['fd' => $fd]);
                $this->sendError($fd, "Missing server_id or capacity");
                return;
            }

            // Store server info in the clients array
            $this->clients[$fd] = [
                'type' => 'server',
                'authenticated' => true,
                'server_id' => $serverId,
                'capacity' => $capacity,
                'last_ping' => time()
            ];
            
            // Also store in gameServers for tracking
            $this->gameServers[$fd] = [
                'server_id' => $serverId,
                'capacity' => $capacity,
                'last_ping' => time()
            ];

            $this->logger->info("Game server registered", [
                'fd' => $fd,
                'server_id' => $serverId,
                'capacity' => $capacity,
                'gameServers_count' => count($this->gameServers)
            ]);

            // Log current server counts
            $serverCount = count(array_filter($this->clients, function($client) {
                return isset($client['type']) && $client['type'] === 'server';
            }));

            $this->logger->debug("Server registration status", [
                'gameServers_count' => count($this->gameServers),
                'servers_in_clients' => $serverCount,
                'all_clients' => array_map(function($client) {
                    return $client['type'] ?? 'unknown';
                }, $this->clients)
            ]);

            $this->sendMessage($fd, [
                'type' => 'server_registered',
                'message' => 'Server registered successfully',
                'server_id' => $serverId
            ]);
        } catch (\Exception $e) {
            $this->logger->error("Error registering server", [
                'fd' => $fd,
                'error' => $e->getMessage()
            ]);
            $this->sendError($fd, "Error registering server: " . $e->getMessage());
        }
    }

    private function handleHeartbeat(int $fd): void
    {
        try {
            // Get client info
            $player = $this->storage->getPlayer($fd);
            $isServer = isset($this->clients[$fd]) && $this->clients[$fd]['type'] === 'server';
            
            // Update last ping time for all clients
            if (isset($this->clients[$fd])) {
                $this->clients[$fd]['last_ping'] = time();
            }
            
            // For regular users, just log the heartbeat but don't send a response
            // This prevents unnecessary traffic to user clients
            if (!$isServer) {
                $this->logger->debug("User heartbeat received", ['fd' => $fd]);
                return;
            }
            
            // Only send heartbeat_ack to servers
            $this->logger->debug("Server heartbeat received", ['fd' => $fd]);
            $this->sendMessage($fd, [
                'type' => 'heartbeat_ack'
            ]);
        } catch (\Exception $e) {
            $this->logger->error("Error handling heartbeat", [
                'fd' => $fd,
                'error' => $e->getMessage()
            ]);
        }
    }

    private function handlePing(int $fd): void
    {
        try {
            // Get client info
            $isServer = isset($this->clients[$fd]) && $this->clients[$fd]['type'] === 'server';
            
            // For regular users, just log the ping but don't send a response
            if (!$isServer) {
                $this->logger->debug("User ping received", ['fd' => $fd]);
                return;
            }
            
            // Only send pong to servers
            $this->logger->info("Server ping received", ['fd' => $fd]);
            $this->sendMessage($fd, [
                'type' => 'pong'
            ]);
        } catch (\Exception $e) {
            $this->logger->error("Error handling ping", [
                'fd' => $fd,
                'error' => $e->getMessage()
            ]);
        }
    }

    private function handleCreateRoom(int $fd, array $data): void
    {
        if (!isset($data['room_id'])) {
            $this->logger->warning("Missing room_id for create_room", ['fd' => $fd]);
            $this->sendError($fd, "Missing room_id");
            return;
        }

        $roomId = $data['room_id'];
        if (isset($this->rooms[$roomId])) {
            $this->logger->warning("Room already exists", ['room_id' => $roomId]);
            $this->sendError($fd, "Room already exists");
            return;
        }

        $this->rooms[$roomId] = [
            'players' => [],
            'game_server' => null
        ];

        $this->logger->info("Room created", ['room_id' => $roomId]);
        $this->sendMessage($fd, [
            'type' => 'room_created',
            'room_id' => $roomId
        ]);
    }

    private function handleJoinRoom(int $fd, array $data): void
    {
        // Check for required data
        if (!isset($data['room_id'])) {
            $this->logger->warning("Missing room_id for join_room", ['fd' => $fd]);
            $this->sendError($fd, "Missing room_id");
            return;
        }

        // Get player info from storage
        $player = $this->storage->getPlayer($fd);
        if (!$player) {
            $this->logger->warning("Player not found for join_room", ['fd' => $fd]);
            $this->sendError($fd, "Authentication required");
            return;
        }

        $roomId = $data['room_id'];
        if (!$this->storage->roomExists($roomId)) {
            $this->logger->warning("Room does not exist", ['room_id' => $roomId]);
            $this->sendError($fd, "Room not found");
            return;
        }

        $room = $this->storage->getRoom($roomId);
        $gameData = json_decode($room['game_data'], true);

        // Check if room is full
        if (isset($gameData['players']) && count($gameData['players']) >= $room['max_players']) {
            $this->logger->warning("Room is full", ['room_id' => $roomId]);
            $this->sendError($fd, "Room is full");
            return;
        }

        // Add player to room
        if (!isset($gameData['players'])) {
            $gameData['players'] = [];
        }
        $gameData['players'][] = $fd;
        $gameData['last_updated'] = time();

        // Update room in storage
        $this->storage->updateRoom($roomId, [
            'name' => $room['name'],
            'status' => $room['status'],
            'max_players' => $room['max_players'],
            'current_players' => count($gameData['players']),
            'game_data' => json_encode($gameData),
            'created_at' => $room['created_at']
        ]);

        // Update player in storage
        $this->storage->setPlayer($fd, [
            'user_id' => $player['user_id'],
            'username' => $player['username'],
            'room_id' => $roomId,
            'status' => 'not_ready',
            'cards' => '[]',
            'score' => $player['score'] ?? 0,
            'last_activity' => time()
        ]);

        $this->logger->info("Player joined room", [
            'fd' => $fd,
            'user_id' => $player['user_id'],
            'username' => $player['username'],
            'room_id' => $roomId
        ]);

        // Get all players in the room for the response
        $playersInRoom = [];
        foreach ($gameData['players'] as $playerFd) {
            if ($this->storage->playerExists($playerFd)) {
                $playerInfo = $this->storage->getPlayer($playerFd);
                $playersInRoom[] = [
                    'user_id' => $playerInfo['user_id'],
                    'username' => $playerInfo['username'],
                    'status' => $playerInfo['status'] ?? 'not_ready',
                    'ready' => ($playerInfo['status'] ?? '') === 'ready'
                ];
            }
        }

        // Send success message to the joining player
        $this->sendMessage($fd, [
            'type' => 'room_joined',
            'room_id' => $roomId,
            'room_name' => $room['name'],
            'players' => $playersInRoom
        ]);

        // Broadcast room update to all players
        $this->broadcastRoomUpdate($roomId);
        
        // Notify the game server about the player joining
        $serverId = $this->getGameServerForRoom($roomId);
        if ($serverId) {
            $gameServerFd = $this->findGameServerFdById($serverId);
            if ($gameServerFd) {
                $this->sendMessage($gameServerFd, [
                    'type' => 'add_player_to_room',
                    'room_id' => $roomId,
                    'player_id' => $player['user_id'],
                    'player_fd' => $fd,
                    'username' => $player['username']
                ]);
            }
        }
    }

    private function handleLeaveRoom(int $fd, array $data): void
    {
        // Get player info from storage
        $player = $this->storage->getPlayer($fd);
        if (!$player) {
            $this->logger->warning("Player not found for leave_room", ['fd' => $fd]);
            return;
        }

        // Check if room_id is provided in the data
        $roomId = $data['room_id'] ?? $player['room_id'] ?? null;
        if (!$roomId) {
            $this->logger->warning("No room_id for leave_room", ['fd' => $fd]);
            $this->sendError($fd, "Missing room_id");
            return;
        }

        // Get player_id from data or player info
        $playerId = $data['player_id'] ?? $player['user_id'] ?? null;
        if (!$playerId) {
            $this->logger->warning("No player_id for leave_room", ['fd' => $fd]);
            $this->sendError($fd, "Missing player_id");
            return;
        }

        $this->logger->info("Player leaving room", [
            'fd' => $fd,
            'player_id' => $playerId,
            'room_id' => $roomId
        ]);

        // Update player in storage to remove room association
        $this->storage->setPlayer($fd, [
            'user_id' => $player['user_id'],
            'username' => $player['username'],
            'room_id' => '',
            'status' => 'online',
            'cards' => '[]',
            'score' => $player['score'] ?? 0,
            'last_activity' => time()
        ]);

        // Handle room in memory storage
        $room = $this->storage->getRoom($roomId);
        if ($room) {
            $gameData = json_decode($room['game_data'], true);
            
            // Remove player from the room's players array
            if (isset($gameData['players'])) {
                $gameData['players'] = array_values(array_filter($gameData['players'], function($playerFd) use ($fd) {
                    return $playerFd != $fd;
                }));
                $gameData['last_updated'] = time();
                
                // Update or remove room
                if (empty($gameData['players'])) {
                    $this->storage->removeRoom($roomId);
                    $this->logger->info("Room removed (empty)", ['room_id' => $roomId]);
                } else {
                    $this->storage->updateRoom($roomId, [
                        'name' => $room['name'],
                        'status' => $room['status'],
                        'max_players' => $room['max_players'],
                        'current_players' => count($gameData['players']),
                        'game_data' => json_encode($gameData),
                        'created_at' => $room['created_at']
                    ]);
                    
                    // Notify remaining players
                    foreach ($gameData['players'] as $playerFd) {
                        if ($this->server->isEstablished($playerFd)) {
                            $this->sendMessage($playerFd, [
                                'type' => 'player_left',
                                'user_id' => $playerId,
                                'username' => $player['username'],
                                'room_id' => $roomId
                            ]);
                        }
                    }
                }
            }
        }

        // Confirm to the leaving player
        $this->sendMessage($fd, [
            'type' => 'left_room',
            'room_id' => $roomId
        ]);
    }

    private function handleGameAction(int $fd, array $data): void
    {
        if (!isset($data['player_id']) || !isset($data['room_id']) || !isset($data['action'])) {
            $this->logger->warning("Invalid game action data");
            return;
        }

        $roomId = $data['room_id'];
        $this->logger->info("Game action received", [
            'player_id' => $data['player_id'],
            'room_id' => $roomId,
            'action' => $data['action']
        ]);

        // Broadcast action to all players in the room
        $this->broadcastToRoom($roomId, [
            'type' => 'game_action',
            'player_id' => $data['player_id'],
            'room_id' => $roomId,
            'action' => $data['action']
        ]);
    }

    private function broadcastToRoom(string $roomId, array $message): void
    {
        if (!$this->storage->roomExists($roomId)) {
            $this->logger->warning("Room not found for broadcast", ['room_id' => $roomId]);
            return;
        }

        $room = $this->storage->getRoom($roomId);
        $gameData = json_decode($room['game_data'], true);
        
        if (!isset($gameData['players']) || !is_array($gameData['players'])) {
            $this->logger->warning("No players in room for broadcast", ['room_id' => $roomId]);
            return;
        }
        
        foreach ($gameData['players'] as $playerFd) {
            if ($this->server->isEstablished($playerFd)) {
                $this->sendMessage($playerFd, $message);
            }
        }
    }

    public function onClose(Server $server, int $fd): void
    {
        try {
            // Check if this was a server
            $isServer = isset($this->clients[$fd]) && isset($this->clients[$fd]['type']) && $this->clients[$fd]['type'] === 'server';
            $serverId = $isServer && isset($this->clients[$fd]['server_id']) ? $this->clients[$fd]['server_id'] : null;
            
            if ($isServer) {
                $this->logger->info("Game server disconnected", [
                    'fd' => $fd,
                    'server_id' => $serverId ?? 'unknown'
                ]);
                
                // Remove from gameServers array
                if (isset($this->gameServers[$fd])) {
                    unset($this->gameServers[$fd]);
                    $this->logger->debug("Removed server from gameServers array", [
                        'fd' => $fd, 
                        'remaining_servers' => count($this->gameServers)
                    ]);
                }
                
                // Update all rooms that were on this server
                if ($serverId) {
                    foreach ($this->roomServers as $roomId => $roomServerId) {
                        if ($roomServerId === $serverId) {
                            $this->logger->warning("Room lost its server", ['room_id' => $roomId]);
                            unset($this->roomServers[$roomId]);
                            
                            // Notify players in that room
                            if ($this->storage->roomExists($roomId)) {
                                $room = $this->storage->getRoom($roomId);
                                $gameData = json_decode($room['game_data'], true);
                                if (isset($gameData['players']) && is_array($gameData['players'])) {
                                    foreach ($gameData['players'] as $playerFd) {
                                        if ($this->server->isEstablished($playerFd)) {
                                            $this->sendMessage($playerFd, [
                                                'type' => 'server_disconnected',
                                                'message' => 'Game server disconnected, please rejoin matchmaking.'
                                            ]);
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            } else {
                // Handle regular client disconnect
                $player = $this->storage->getPlayer($fd);
                $userId = $player ? ($player['user_id'] ?? 'unknown') : 'unknown';
                $username = $player ? ($player['username'] ?? 'unknown') : 'unknown';
                
                $this->logger->info("Client disconnected", [
                    'fd' => $fd,
                    'user_id' => $userId,
                    'username' => $username
                ]);
                
                // Handle player leaving room
                if ($player && isset($player['room_id']) && $player['room_id']) {
                    $this->handleLeaveRoom($fd, ['room_id' => $player['room_id']]);
                }
                
                // Remove player from storage
                $this->storage->removePlayer($fd);
            }
            
            // Always remove from clients array
            unset($this->clients[$fd]);
            
        } catch (\Exception $e) {
            $this->logger->error("Error in onClose", [
                'fd' => $fd,
                'error' => $e->getMessage()
            ]);
        }
    }

    public function start(): void
    {
        $this->logger->info("Starting WebSocket server");
        $this->server->start();
    }

    private function sendMessage(int $fd, array $data): void
    {
        try {
            $this->server->push($fd, json_encode($data));
        } catch (\Exception $e) {
            $this->logger->error("Failed to send message", [
                'fd' => $fd,
                'error' => $e->getMessage()
            ]);
        }
    }

    private function sendError(int $fd, string $message): void
    {
        $this->sendMessage($fd, [
            'type' => 'error',
            'message' => $message
        ]);
    }

    private function handleGetOnlineCount(int $fd): void
    {
        $count = count(array_filter($this->clients, function($client) {
            return $client['type'] === 'user' && $client['authenticated'];
        }));

        $this->sendMessage($fd, [
            'type' => 'online_count',
            'count' => $count
        ]);
    }

    private function handleMatchmakingJoin(int $fd, array $data): void
    {
        $player = $this->storage->getPlayer($fd);
        if (!$player) {
            $this->sendError($fd, 'Not authenticated');
            return;
        }

        $gameType = $data['game_type'] ?? 'default';
        $maxPlayers = $data['max_players'] ?? 4;
        $isPrivate = $data['is_private'] ?? false;
        $privateCode = $data['private_code'] ?? null;

        // Try to find existing suitable room
        $roomId = $this->roomHandler->findSuitableRoom($maxPlayers, $isPrivate, $privateCode);
        
        if ($roomId) {
            // Join existing room
            $gameServerId = $this->serverHandler->getGameServerForRoom($roomId);
            if ($gameServerId) {
                $this->roomHandler->addPlayerToRoom($fd, $roomId, $gameServerId);
                $this->sendMessage($fd, [
                    'type' => 'matchmaking_success',
                    'room_id' => $roomId
                ]);
            }
        } else {
            // Create new room
            $gameServerFd = $this->serverHandler->findLeastLoadedGameServer();
            if (!$gameServerFd) {
                $this->sendError($fd, 'No available game servers');
                return;
            }

            $this->roomHandler->createRoomAndAddPlayer($fd, $maxPlayers, $isPrivate, $privateCode);
        }
    }

    private function broadcastRoomUpdate(string $roomId): void
    {
        if (!$this->storage->roomExists($roomId)) {
            $this->logger->warning("Room not found for update", ['room_id' => $roomId]);
            return;
        }

        $room = $this->storage->getRoom($roomId);
        $gameData = json_decode($room['game_data'], true);
        $players = [];
        
        if (isset($gameData['players']) && is_array($gameData['players'])) {
            foreach ($gameData['players'] as $playerFd) {
                if ($this->storage->playerExists($playerFd)) {
                    $player = $this->storage->getPlayer($playerFd);
                    if ($player) {
                        $this->logger->debug("Adding player to room update", [
                            'fd' => $playerFd,
                            'user_id' => $player['user_id'],
                            'username' => $player['username']
                        ]);
                        
                        $players[] = [
                            'user_id' => $player['user_id'],
                            'username' => $player['username'],
                            'status' => $player['status'] ?? 'not_ready',
                            'ready' => ($player['status'] ?? '') === 'ready'
                        ];
                    }
                }
            }
        }

        $message = [
            'type' => 'room_update',
            'room_id' => $roomId,
            'room_name' => $room['name'],
            'players' => $players,
            'current_players' => count($players),
            'max_players' => $room['max_players']
        ];

        $this->logger->debug("Broadcasting room update", [
            'room_id' => $roomId,
            'players_count' => count($players),
            'players' => $players
        ]);

        // Send to all players in the room
        if (isset($gameData['players']) && is_array($gameData['players'])) {
            foreach ($gameData['players'] as $playerFd) {
                if ($this->server->isEstablished($playerFd)) {
                    $this->sendMessage($playerFd, $message);
                }
            }
        }
    }

    private function handlePlayerReady(int $fd, array $data): void
    {
        try {
            // Get player info
            $player = $this->storage->getPlayer($fd);
            if (!$player) {
                $this->logger->warning("Player not found for ready status", ['fd' => $fd]);
                $this->sendError($fd, "Authentication required");
                return;
            }

            // Check if room_id is provided or use player's current room
            $roomId = $data['room_id'] ?? $player['room_id'] ?? null;
            if (!$roomId) {
                $this->logger->warning("No room_id for player ready", ['fd' => $fd]);
                $this->sendError($fd, "You are not in a room");
                return;
            }

            // Check if room exists
            $room = $this->storage->getRoom($roomId);
            if (!$room) {
                $this->logger->warning("Room not found for player ready", ['room_id' => $roomId]);
                $this->sendError($fd, "Room not found");
                return;
            }

            // Get ready status
            $isReady = isset($data['ready']) && $data['ready'] === true;
            $newStatus = $isReady ? 'ready' : 'not_ready';

            $this->logger->info("Player ready status change", [
                'fd' => $fd,
                'user_id' => $player['user_id'],
                'username' => $player['username'],
                'ready' => $isReady
            ]);

            // Update player in storage
            $this->storage->setPlayer($fd, [
                'user_id' => $player['user_id'],
                'username' => $player['username'],
                'room_id' => $roomId,
                'status' => $newStatus,
                'cards' => $player['cards'] ?? '[]',
                'score' => $player['score'] ?? 0,
                'last_activity' => time()
            ]);

            // Confirm to the player
            $this->sendMessage($fd, [
                'type' => 'ready_status_updated',
                'ready' => $isReady,
                'status' => $newStatus
            ]);

            // Notify other players in the room
            $gameData = json_decode($room['game_data'], true);
            if (isset($gameData['players']) && is_array($gameData['players'])) {
                foreach ($gameData['players'] as $playerFd) {
                    if ($playerFd != $fd && $this->server->isEstablished($playerFd)) {
                        $this->sendMessage($playerFd, [
                            'type' => 'player_status_changed',
                            'player_id' => $player['user_id'],
                            'username' => $player['username'],
                            'status' => $newStatus,
                            'ready' => $isReady
                        ]);
                    }
                }
            }

            // Update room game data with player status
            if (!isset($gameData['player_status'])) {
                $gameData['player_status'] = [];
            }
            $gameData['player_status'][$player['user_id']] = [
                'status' => $newStatus,
                'ready' => $isReady
            ];
            $room['game_data'] = json_encode($gameData);
            $this->storage->updateRoom($roomId, $room);

            // Check if all players are ready to start the game
            $this->checkAllPlayersReady($roomId);
        } catch (\Exception $e) {
            $this->logger->error("Error handling player ready", [
                'fd' => $fd,
                'error' => $e->getMessage()
            ]);
            $this->sendError($fd, "Error handling ready status: " . $e->getMessage());
        }
    }

    /**
     * Check if all players in a room are ready to start the game
     */
    private function checkAllPlayersReady(string $roomId): void
    {
        if (!$this->storage->roomExists($roomId)) {
            return;
        }

        $room = $this->storage->getRoom($roomId);
        $gameData = json_decode($room['game_data'], true);

        // Count ready players
        $readyCount = 0;
        $totalCount = 0;
        $playerStatuses = [];

        if (isset($gameData['players']) && is_array($gameData['players'])) {
            foreach ($gameData['players'] as $playerFd) {
                if ($this->storage->playerExists($playerFd)) {
                    $player = $this->storage->getPlayer($playerFd);
                    $playerStatuses[] = [
                        'fd' => $playerFd,
                        'user_id' => $player['user_id'],
                        'username' => $player['username'],
                        'status' => $player['status']
                    ];
                    $totalCount++;
                    if ($player['status'] === 'ready') {
                        $readyCount++;
                    }
                }
            }
        }

        $this->logger->info("Room ready status", [
            'room_id' => $roomId,
            'ready_count' => $readyCount,
            'total_count' => $totalCount,
            'max_players' => $room['max_players'],
            'player_statuses' => $playerStatuses
        ]);

        // Start game if all players are ready and we have enough players
        if ($readyCount >= 2 && $readyCount == $totalCount && $room['status'] === 'waiting') {
            $this->logger->info("Starting game - all players ready", ['room_id' => $roomId]);
            $this->startGame($roomId);
        }
    }

    /**
     * Start a game in the specified room
     */
    private function startGame(string $roomId): void
    {
        if (!$this->storage->roomExists($roomId)) {
            return;
        }

        $room = $this->storage->getRoom($roomId);
        $gameData = json_decode($room['game_data'], true);

        // Update room status
        $room['status'] = 'playing';
        $gameData['game_started'] = true;
        $gameData['start_time'] = time();
        $room['game_data'] = json_encode($gameData);
        $this->storage->updateRoom($roomId, $room);

        // Notify all players
        if (isset($gameData['players']) && is_array($gameData['players'])) {
            $playerInfos = [];
            foreach ($gameData['players'] as $playerFd) {
                if ($this->storage->playerExists($playerFd)) {
                    $player = $this->storage->getPlayer($playerFd);
                    $playerInfos[] = [
                        'user_id' => $player['user_id'],
                        'username' => $player['username']
                    ];
                }
            }
            
            // Send game start notification to all players
            foreach ($gameData['players'] as $playerFd) {
                if ($this->storage->playerExists($playerFd)) {
                    $this->sendMessage($playerFd, [
                        'type' => 'game_started',
                        'room_id' => $roomId,
                        'players' => $playerInfos
                    ]);
                }
            }
        }
    }

    /**
     * Handle room registration from a game server
     */
    private function handleRegisterRoom(array $data): void
    {
        if (!isset($data['room_id']) || !isset($data['server_id']) || !isset($data['room_data'])) {
            $this->logger->warning("Invalid register_room data");
            return;
        }

        $roomId = $data['room_id'];
        $serverId = $data['server_id'];
        $roomData = $data['room_data'];

        $this->logger->info("Registering room from game server", [
            'room_id' => $roomId,
            'server_id' => $serverId
        ]);

        // Store the mapping of room to server
        $this->roomServers[$roomId] = $serverId;

        // Create or update the room in storage if it doesn't exist
        if (!$this->storage->roomExists($roomId)) {
            $gameData = [
                'players' => $roomData['players'] ?? [],
                'server_id' => $serverId,
                'last_updated' => time()
            ];
            
            $this->storage->createRoom($roomId, [
                'name' => $roomData['name'] ?? 'Room ' . $roomId,
                'status' => $roomData['status'] ?? 'waiting',
                'max_players' => $roomData['max_players'] ?? 4,
                'current_players' => $roomData['current_players'] ?? 0,
                'game_data' => json_encode($gameData),
                'created_at' => time()
            ]);
            
            $this->logger->info("Room registered from game server", [
                'room_id' => $roomId,
                'server_id' => $serverId
            ]);
        } else {
            $this->logger->info("Room already exists, updating server mapping", [
                'room_id' => $roomId,
                'server_id' => $serverId
            ]);
            
            // Update the existing room with the server ID
            $room = $this->storage->getRoom($roomId);
            $gameData = json_decode($room['game_data'], true);
            $gameData['server_id'] = $serverId;
            $gameData['last_updated'] = time();
            
            $this->storage->updateRoom($roomId, [
                'name' => $room['name'],
                'status' => $room['status'],
                'max_players' => $room['max_players'],
                'current_players' => $room['current_players'],
                'game_data' => json_encode($gameData),
                'created_at' => $room['created_at']
            ]);
        }
    }

    /**
     * Handle acknowledgment of room creation from game server
     */
    private function handleRoomCreatedAck(array $data): void
    {
        if (!isset($data['room_id']) || !isset($data['server_id'])) {
            $this->logger->warning("Invalid room_created_ack data");
            return;
        }

        $roomId = $data['room_id'];
        $serverId = $data['server_id'];

        $this->logger->info("Room creation acknowledged by game server", [
            'room_id' => $roomId,
            'server_id' => $serverId
        ]);

        // Check if we have pending joins for this room
        if (isset($this->pendingRoomJoins[$roomId])) {
            $pendingJoins = $this->pendingRoomJoins[$roomId];
            unset($this->pendingRoomJoins[$roomId]);

            $this->logger->info("Processing pending joins for room", [
                'room_id' => $roomId, 
                'count' => count($pendingJoins)
            ]);

            // Process all pending joins
            foreach ($pendingJoins as $fd) {
                $this->roomHandler->addPlayerToRoom($fd, $roomId, $serverId);
            }
        }
    }

    /**
     * Handle acknowledgment of player added to room from game server
     */
    private function handlePlayerAddedToRoomAck(array $data): void
    {
        if (!isset($data['room_id']) || !isset($data['player_id'])) {
            $this->logger->warning("Invalid player_added_to_room_ack data");
            return;
        }

        $roomId = $data['room_id'];
        $playerId = $data['player_id'];

        $this->logger->info("Player added to room acknowledged by game server", [
            'room_id' => $roomId,
            'player_id' => $playerId
        ]);

        // At this point we could notify other clients about the new player
        // or perform additional logic if needed
    }
}
