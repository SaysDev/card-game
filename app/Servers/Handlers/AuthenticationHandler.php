<?php

namespace App\Servers\Handlers;

use App\Servers\Storage\MemoryStorage;
use OpenSwoole\WebSocket\Server;
use App\Servers\Utilities\Logger;

class AuthenticationHandler
{
    private MemoryStorage $storage;
    private Logger $logger;

    public function __construct(MemoryStorage $storage)
    {
        $this->storage = $storage;
        $this->logger = new Logger('AuthHandler');
    }

    public function handleAuthentication(Server $server, int $fd, array $data): void
    {
        try {
            $token = $data['token'] ?? null;
            $authType = $data['auth_type'] ?? null;
            
            if (!$token) {
                $this->logger->warning('Missing authentication token', ['data' => $data]);
                $server->push($fd, json_encode([
                    'type' => 'auth_error',
                    'message' => 'Missing authentication token'
                ]));
                return;
            }

            // Handle server authentication
            if ($authType === 'server') {
                $serverId = $data['server_id'] ?? null;
                $capacity = $data['capacity'] ?? null;
                
                if (!$serverId || !$capacity) {
                    $this->logger->warning('Missing server data for authentication', [
                        'fd' => $fd,
                        'server_id' => $serverId,
                        'capacity' => $capacity
                    ]);
                    $server->push($fd, json_encode([
                        'type' => 'auth_error',
                        'message' => 'Missing server data'
                    ]));
                    return;
                }

                // Validate server token
                $auth = new \App\Services\WebSocketAuthService();
                if ($auth->validateServerToken($token)) {
                    $this->logger->info('Server authenticated', [
                        'fd' => $fd,
                        'server_id' => $serverId
                    ]);
                    
                    // Set player data for server
                    $this->storage->setPlayer($fd, [
                        'type' => 'server',
                        'server_id' => $serverId,
                        'capacity' => $capacity,
                        'authenticated' => true,
                        'last_activity' => time()
                    ]);
                    
                    $server->push($fd, json_encode([
                        'type' => 'auth_success',
                        'message' => 'Server authenticated successfully',
                        'server_id' => $serverId
                    ]));
                    return;
                } else {
                    $this->logger->warning('Invalid server token', [
                        'fd' => $fd,
                        'server_id' => $serverId,
                        'token_length' => strlen($token)
                    ]);
                    $server->push($fd, json_encode([
                        'type' => 'auth_error',
                        'message' => 'Invalid server token'
                    ]));
                    return;
                }
            }

            // Handle user authentication
            if (!isset($data['user_id']) || !isset($data['username'])) {
                $this->logger->warning('Authentication data received without required fields', ['data' => $data]);
                $server->push($fd, json_encode([
                    'type' => 'auth_error',
                    'message' => 'Missing authentication data'
                ]));
                return;
            }
            
            $this->logger->info('Authentication data received', ['user_id' => $data['user_id'], 'username' => $data['username']]);
            
            $userId = $data['user_id'];
            $username = $data['username'];
            
            // Set player data
            $this->storage->setPlayer($fd, [
                'user_id' => $userId,
                'username' => $username,
                'room_id' => '',
                'status' => 'online',
                'cards' => '[]',
                'score' => 0,
                'last_activity' => time(),
                'authenticated' => true
            ]);
            
            $server->push($fd, json_encode([
                'type' => 'auth_success',
                'user' => [
                    'id' => $userId,
                    'username' => $username
                ],
                'connected_users' => $this->storage->getOnlineCount()
            ]));
            
            // Check if user was in a room before and try to reconnect
            $this->reconnectToActiveRoom($server, $fd, $userId);
        } catch (\Exception $e) {
            $this->logger->error('Authentication error', ['message' => $e->getMessage()]);
            $server->push($fd, json_encode([
                'type' => 'auth_error',
                'message' => 'Authentication error: ' . $e->getMessage()
            ]));
        }
    }
    
    /**
     * Attempt to reconnect a user to their active game room
     *
     * @param Server $server
     * @param int $fd
     * @param int $userId
     * @return void
     */
    private function reconnectToActiveRoom(Server $server, int $fd, int $userId): void
    {
        try {
            // Get all active rooms
            $rooms = $this->storage->getAllRooms();

            foreach ($rooms as $roomId => $room) {
                // Skip rooms that are not in 'waiting' or 'playing' status
                if (!in_array($room['status'], ['waiting', 'playing'])) {
                    continue;
                }

                $gameData = json_decode($room['game_data'], true);

                if (isset($gameData['player_user_ids']) && in_array($userId, $gameData['player_user_ids'])) {
                    $this->logger->info("Reconnecting user to room", [
                        'user_id' => $userId,
                        'room_id' => $roomId
                    ]);

                    $gameData['players'][] = $fd;
                    $room['game_data'] = json_encode($gameData);

                    $this->storage->updateRoom($roomId, $room);

                    $player = $this->storage->getPlayer($fd);
                    $this->storage->setPlayer($fd, [
                        'user_id' => $player['user_id'],
                        'username' => $player['username'],
                        'room_id' => $roomId,
                        'status' => 'playing',
                        'cards' => $player['cards'],
                        'score' => $player['score'],
                        'last_activity' => time()
                    ]);

                    $server->push($fd, json_encode([
                        'type' => 'room_reconnected',
                        'room_id' => $roomId,
                        'room_name' => $room['name'],
                        'game_status' => $room['status']
                    ]));

                    if ($room['status'] === 'playing' && isset($gameData['player_cards'][$userId])) {
                        $server->push($fd, json_encode([
                            'type' => 'your_cards',
                            'cards' => $gameData['player_cards'][$userId]
                        ]));
                    }

                    break;
                }
            }
        } catch (\Exception $e) {
            $this->logger->error("Error reconnecting to room", [
                'error' => $e->getMessage()
            ]);
        }
    }
}
