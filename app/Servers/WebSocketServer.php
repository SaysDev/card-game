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

    public function __construct(string $host, int $port, Logger $logger)
    {
        $this->server = new Server($host, $port);
        $this->auth = new WebSocketAuthService();
        $this->storage = new MemoryStorage();
        $this->logger = $logger;
        
        // Pokaż PID procesu w logach
        Logger::showPid(true);
        
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
        try {
            $data = json_decode($frame->data, true);
            if (!$data) {
                $this->logger->warning("Invalid JSON message", ['fd' => $frame->fd]);
                return;
            }

            $this->logger->debug("Received message", [
                'fd' => $frame->fd,
                'type' => $data['type'] ?? 'unknown'
            ]);

            if (!isset($data['type'])) {
                $this->sendError($frame->fd, 'No message type specified');
                return;
            }

            // Store the connection type for debugging purposes
            if (!isset($this->clients[$frame->fd])) {
                $this->clients[$frame->fd] = ['type' => 'connection'];
            }

            // Authenticate first or handle special message types that don't require authentication
            if ($data['type'] === 'authenticate') {
                // Handle client authentication
                $authHandler = new AuthenticationHandler($this->storage);
                $authHandler->handleAuthentication($server, $frame->fd, $data);
                return;
            } else if ($data['type'] === 'register_server') {
                // Handle server registration without requiring prior authentication
                $this->handleRegisterServer($frame->fd, $data);
                
                // Log all connections after registration for debugging
                $this->logger->debug("All connections after server registration", [
                    'count' => count($this->clients),
                    'clients' => array_map(function($client) {
                        return $client['type'] ?? 'unknown';
                    }, $this->clients),
                    'gameServers_count' => count($this->gameServers),
                    'gameServers_keys' => array_keys($this->gameServers)
                ]);
                return;
            } else if ($data['type'] === 'register_room') {
                $this->handleRegisterRoom($data);
                return;
            } else if ($data['type'] === 'room_created_ack') {
                $this->handleRoomCreatedAck($data);
                return;
            } else if ($data['type'] === 'player_added_to_room_ack') {
                $this->handlePlayerAddedToRoomAck($data);
                return;
            }

            // Check if client is authenticated for other message types
            $player = $this->storage->getPlayer($frame->fd);
            if (!$player) {
                $this->logger->warning("Player not found", ['fd' => $frame->fd]);
                $this->sendError($frame->fd, "Authentication required");
                return;
            }
            
            // Check authentication status - some older clients might not have the authenticated key
            $isAuthenticated = isset($player['authenticated']) ? $player['authenticated'] : 
                              (isset($player['user_id']) && !empty($player['user_id']));
            
            if (!$isAuthenticated) {
                $this->logger->warning("Unauthenticated message", ['fd' => $frame->fd]);
                $this->sendError($frame->fd, "Authentication required");
                return;
            }

            // Handle different message types
            switch ($data['type']) {
                case 'd':
                    $this->handleHeartbeat($frame->fd);
                    break;
                case 'ping':
                    $this->handlePing($frame->fd);
                    break;
                case 'create_room':
                    $this->handleCreateRoom($frame->fd, $data);
                    break;
                case 'join_room':
                    $this->handleJoinRoom($frame->fd, $data);
                    break;
                case 'leave_room':
                    $this->handleLeaveRoom($frame->fd, $data);
                    break;
                case 'game_action':
                    $this->handleGameAction($frame->fd, $data);
                    break;
                case 'get_online_count':
                    $this->handleGetOnlineCount($frame->fd);
                    break;
                case 'matchmaking_join':
                    $this->handleMatchmakingJoin($frame->fd, $data);
                    break;
                case 'player_ready':
                    $this->handlePlayerReady($frame->fd, $data);
                    break;
                    
                default:
                    $this->logger->warning("Unknown message type", ['type' => $data['type']]);
                    $this->sendError($frame->fd, "Unknown message type: " . $data['type']);
            }
        } catch (\Exception $e) {
            $this->logger->error("Error processing message", [
                'fd' => $frame->fd,
                'error' => $e->getMessage()
            ]);
            $this->sendError($frame->fd, "Error processing message: " . $e->getMessage());
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
        // Check if client is authenticated
        $player = $this->storage->getPlayer($fd);
        if (!$player) {
            $this->logger->warning("Player not found for matchmaking", ['fd' => $fd]);
            $this->sendError($fd, "Authentication required");
            return;
        }
        
        // Check authentication status
        $isAuthenticated = isset($player['authenticated']) ? $player['authenticated'] : 
                          (isset($player['user_id']) && !empty($player['user_id']));
        
        if (!$isAuthenticated) {
            $this->logger->warning("Unauthenticated matchmaking attempt", ['fd' => $fd]);
            $this->sendError($fd, "Authentication required");
            return;
        }

        $size = $data['size'] ?? 2;
        $isPrivate = $data['is_private'] ?? false;
        $privateCode = $data['private_code'] ?? null;

        $this->logger->info("Player joining matchmaking", [
            'fd' => $fd,
            'user_id' => $player['user_id'],
            'username' => $player['username'],
            'size' => $size,
            'is_private' => $isPrivate,
            'private_code' => $privateCode
        ]);

        // Verify game servers and restore if needed
        $this->verifyAndRestoreGameServers();

        // Get server count after verification
        $serverCount = count($this->gameServers);
        
        if ($serverCount === 0) {
            $this->logger->error("No game servers available for matchmaking");
            $this->sendError($fd, "No game servers available. Please try again later.");
            return;
        }

        // 1. Try to find an existing suitable room
        $roomId = $this->findSuitableRoom($size, $isPrivate, $privateCode);
        
        if ($roomId) {
            $this->logger->info("Found suitable existing room", ['room_id' => $roomId]);
            
            // Get the server responsible for this room
            $gameServerId = $this->getGameServerForRoom($roomId);
            if (!$gameServerId) {
                $this->logger->warning("No game server found for room", ['room_id' => $roomId]);
                $this->sendError($fd, "Server error: Cannot find game server");
                return;
            }
            
            // Add player to room
            $this->addPlayerToRoom($fd, $roomId, $gameServerId);
            return;
        }
        
        // 2. No suitable room found, create a new one
        $this->logger->info("No suitable room found, creating new room");
        $this->createRoomAndAddPlayer($fd, $size, $isPrivate, $privateCode);
    }
    
    /**
     * Verify game servers registration and restore if needed
     */
    private function verifyAndRestoreGameServers(): void
    {
        // Count servers before restoration
        $gameServersCount = count($this->gameServers);
        
        $this->logger->debug("Starting game servers verification", [
            'initial_count' => $gameServersCount,
            'clients_count' => count($this->clients)
        ]);
        
        // Get all active connections directly from the server and convert to array
        $allConnections = iterator_to_array($this->server->connections);
        
        // 1. Synchronize clients array with actual server connections
        foreach ($allConnections as $fd) {
            if ($this->server->isEstablished($fd) && !isset($this->clients[$fd])) {
                $this->clients[$fd] = ['type' => 'connection', 'last_ping' => time()];
            }
        }
        
        // 2. Find server clients that aren't in gameServers array
        $restoredCount = 0;
        foreach ($this->clients as $fd => $clientData) {
            if (
                isset($clientData['type']) && 
                $clientData['type'] === 'server' && 
                isset($clientData['server_id']) && 
                !isset($this->gameServers[$fd]) &&
                $this->server->isEstablished($fd)
            ) {
                // Restore missing game server entry
                $this->gameServers[$fd] = [
                    'server_id' => $clientData['server_id'],
                    'capacity' => $clientData['capacity'] ?? 10,
                    'last_ping' => time()
                ];
                
                $restoredCount++;
                $this->logger->info("Restored missing game server registration", [
                    'fd' => $fd,
                    'server_id' => $clientData['server_id']
                ]);
            }
        }
        
        // 3. Remove stale gameServers entries
        $removedCount = 0;
        foreach ($this->gameServers as $fd => $serverData) {
            if (!isset($this->clients[$fd]) || !$this->server->isEstablished($fd)) {
                unset($this->gameServers[$fd]);
                $removedCount++;
                
                $this->logger->info("Removed stale game server entry", [
                    'fd' => $fd,
                    'server_id' => $serverData['server_id'] ?? 'unknown'
                ]);
            }
        }
        
        // Log detailed server status
        $this->logger->info("Game servers verification complete", [
            'initial_count' => $gameServersCount,
            'restored' => $restoredCount,
            'removed' => $removedCount,
            'final_count' => count($this->gameServers),
            'has_servers' => !empty($this->gameServers),
            'server_details' => array_map(function($server) {
                return [
                    'server_id' => $server['server_id'] ?? 'unknown',
                    'capacity' => $server['capacity'] ?? 'unknown',
                    'last_ping' => $server['last_ping'] ?? 'unknown'
                ];
            }, $this->gameServers)
        ]);
    }
    
    /**
     * Find a suitable existing room based on criteria
     */
    private function findSuitableRoom(int $size, bool $isPrivate, ?string $privateCode): ?string
    {
        $roomPrefix = $isPrivate ? 'private_' : 'public_';
        $roomKey = $roomPrefix . $size;
        if ($isPrivate && $privateCode) {
            $roomKey .= '_' . $privateCode;
        }

        // Look for existing room with available space
        $rooms = $this->storage->getAllRooms();
        foreach ($rooms as $id => $room) {
            if (
                strpos($id, $roomKey) === 0 &&
                $room['status'] === 'waiting' &&
                $room['current_players'] < $room['max_players']
            ) {
                return $id;
            }
        }
        
        return null;
    }
    
    /**
     * Get the game server responsible for a room
     */
    private function getGameServerForRoom(string $roomId): ?string
    {
        // First check our direct mapping
        if (isset($this->roomServers[$roomId])) {
            return $this->roomServers[$roomId];
        }
        
        // Otherwise check in the room data
        if ($this->storage->roomExists($roomId)) {
            $room = $this->storage->getRoom($roomId);
            $gameData = json_decode($room['game_data'], true);
            if (isset($gameData['server_id'])) {
                // Cache the mapping for future use
                $this->roomServers[$roomId] = $gameData['server_id'];
                return $gameData['server_id'];
            }
        }
        
        return null;
    }
    
    /**
     * Add a player to an existing room
     */
    private function addPlayerToRoom(int $fd, string $roomId, string $gameServerId): void
    {
        $player = $this->storage->getPlayer($fd);
        if (!$player) {
            $this->logger->warning("Player not found", ['fd' => $fd]);
            $this->sendError($fd, "Authentication error");
            return;
        }
        
        // Update player in storage with room info
        $this->storage->setPlayer($fd, [
            'user_id' => $player['user_id'],
            'username' => $player['username'],
            'room_id' => $roomId,
            'status' => 'not_ready',
            'cards' => '[]',
            'score' => $player['score'] ?? 0,
            'last_activity' => time()
        ]);
        
        // Update room player count in storage
        $room = $this->storage->getRoom($roomId);
        if ($room) {
            $gameData = json_decode($room['game_data'], true);
            if (!isset($gameData['players'])) {
                $gameData['players'] = [];
            }
            if (!in_array($fd, $gameData['players'])) {
                $gameData['players'][] = $fd;
            }
            
            $this->storage->updateRoom($roomId, [
                'name' => $room['name'],
                'status' => $room['status'],
                'max_players' => $room['max_players'],
                'current_players' => count($gameData['players']),
                'game_data' => json_encode($gameData),
                'created_at' => $room['created_at']
            ]);
        }
        
        // Find the game server connection
        $gameServerFd = $this->findGameServerFdById($gameServerId);
        if ($gameServerFd) {
            // Notify game server about player joining
            $this->sendMessage($gameServerFd, [
                'type' => 'add_player_to_room',
                'room_id' => $roomId,
                'player_id' => $player['user_id'],
                'player_fd' => $fd,
                'username' => $player['username']
            ]);
        }
        
        // Send room info to player
        $this->handleJoinRoom($fd, [
            'room_id' => $roomId,
            'user_id' => $player['user_id'],
            'username' => $player['username']
        ]);
    }
    
    /**
     * Find a game server connection FD by server ID
     */
    private function findGameServerFdById(string $serverId): ?int
    {
        foreach ($this->clients as $fd => $client) {
            if (
                isset($client['type']) && 
                $client['type'] === 'server' && 
                isset($client['authenticated']) && 
                $client['authenticated'] && 
                isset($client['server_id']) && 
                $client['server_id'] === $serverId
            ) {
                return $fd;
            }
        }
        return null;
    }
    
    /**
     * Create a new room on a game server and add player to it
     */
    private function createRoomAndAddPlayer(int $fd, int $size, bool $isPrivate, ?string $privateCode): void
    {
        // Find least loaded game server
        $gameServerFd = $this->findLeastLoadedGameServer();
        
        // Get player info
        $player = $this->storage->getPlayer($fd);
        if (!$player) {
            $this->logger->warning("Player not found", ['fd' => $fd]);
            $this->sendError($fd, "Authentication error");
            return;
        }
        
        if (!$gameServerFd) {
            $this->logger->error("No game servers available to create room");
            $this->sendError($fd, "No game servers available. Please try again later.");
            return;
        }
        
        $serverId = $this->clients[$gameServerFd]['server_id'];
        $roomPrefix = $isPrivate ? 'private_' : 'public_';
        $roomId = $roomPrefix . $size . '_' . uniqid();
        
        $this->logger->info("Creating new room on game server", [
            'room_id' => $roomId,
            'server_id' => $serverId,
            'server_fd' => $gameServerFd
        ]);
        
        // Store in our mapping
        $this->roomServers[$roomId] = $serverId;
        
        // Create room in our storage
        $gameData = [
            'players' => [$fd],  // Add player immediately
            'server_id' => $serverId,
            'last_updated' => time()
        ];
        
        $this->storage->createRoom($roomId, [
            'name' => $isPrivate ? 'Prywatny ' . $size : 'Publiczny ' . $size,
            'status' => 'waiting',
            'max_players' => $size,
            'current_players' => 1,  // Count player immediately
            'game_data' => json_encode($gameData),
            'created_at' => time()
        ]);

        // Update player in storage with room info
        $this->storage->setPlayer($fd, [
            'user_id' => $player['user_id'],
            'username' => $player['username'],
            'room_id' => $roomId,
            'status' => 'not_ready',
            'cards' => '[]',
            'score' => $player['score'] ?? 0,
            'last_activity' => time()
        ]);
        
        // Send create room request to game server
        $this->sendMessage($gameServerFd, [
            'type' => 'create_room',
            'room_id' => $roomId,
            'room_data' => [
                'name' => $isPrivate ? 'Prywatny ' . $size : 'Publiczny ' . $size,
                'status' => 'waiting',
                'max_players' => $size,
                'is_private' => $isPrivate,
                'private_code' => $privateCode,
                'creator_id' => $player['user_id'],
                'creator_username' => $player['username']
            ]
        ]);

        // Send room info to player immediately
        $this->sendMessage($fd, [
            'type' => 'room_joined',
            'room_id' => $roomId,
            'room_name' => $isPrivate ? 'Prywatny ' . $size : 'Publiczny ' . $size,
            'players' => [[
                'user_id' => $player['user_id'],
                'username' => $player['username'],
                'status' => 'not_ready',
                'ready' => false
            ]]
        ]);
    }
    
    /**
     * Find the least loaded game server
     */
    private function findLeastLoadedGameServer(): ?int
    {
        $leastLoadedFd = null;
        $lowestRoomCount = PHP_INT_MAX;
        
        // Verify and restore game servers first
        $this->verifyAndRestoreGameServers();
        
        // No need to continue if no game servers available
        if (empty($this->gameServers)) {
            $this->logger->warning("No game servers available after verification", [
                'clients_count' => count($this->clients),
                'clients_types' => array_map(function($client) {
                    return $client['type'] ?? 'unknown';
                }, $this->clients)
            ]);
            return null;
        }
        
        // Find the server with the lowest room count
        foreach ($this->gameServers as $fd => $server) {
            // Skip invalid server connections
            if (!isset($server['server_id']) || !$this->server->isEstablished($fd)) {
                $this->logger->warning("Skipping invalid server connection", [
                    'fd' => $fd,
                    'server_id' => $server['server_id'] ?? 'unknown',
                    'is_established' => $this->server->isEstablished($fd)
                ]);
                continue;
            }
            
            // Count rooms assigned to this server
            $roomCount = 0;
            foreach ($this->roomServers as $roomId => $serverId) {
                if ($serverId === $server['server_id']) {
                    $roomCount++;
                }
            }
            
            $this->logger->debug("Checking server load", [
                'fd' => $fd,
                'server_id' => $server['server_id'],
                'room_count' => $roomCount,
                'capacity' => $server['capacity'] ?? 'unknown'
            ]);
            
            if ($roomCount < $lowestRoomCount) {
                $lowestRoomCount = $roomCount;
                $leastLoadedFd = $fd;
            }
        }
        
        if ($leastLoadedFd !== null) {
            $this->logger->info("Selected least loaded game server", [
                'fd' => $leastLoadedFd,
                'server_id' => $this->gameServers[$leastLoadedFd]['server_id'] ?? 'unknown',
                'room_count' => $lowestRoomCount,
                'capacity' => $this->gameServers[$leastLoadedFd]['capacity'] ?? 'unknown'
            ]);
        } else {
            $this->logger->warning("No valid game server found after checking all servers", [
                'game_servers_count' => count($this->gameServers),
                'room_servers_count' => count($this->roomServers)
            ]);
        }
        
        return $leastLoadedFd;
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
                $this->addPlayerToRoom($fd, $roomId, $serverId);
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
