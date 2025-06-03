<?php

namespace App\Servers\Handlers;

use App\Servers\Storage\MemoryStorage;
use OpenSwoole\WebSocket\Server;

class AuthenticationHandler
{
    private MemoryStorage $storage;

    public function __construct(MemoryStorage $storage)
    {
        $this->storage = $storage;
    }

    public function handleAuthentication(Server $server, int $fd, array $data): void
    {
        if (!isset($data['user_id'], $data['username'], $data['token'])) {
            $server->push($fd, json_encode([
                'type' => 'auth_error',
                'message' => 'Missing authentication data'
            ]));
            return;
        }

        // In production, validate token here with Laravel Auth
        // For demo, we'll assume the token is valid

        $userId = (int) $data['user_id'];
        $username = $data['username'];

        // Update connection with user info
        $this->storage->setConnection($fd, [
            'fd' => $fd,
            'user_id' => $userId,
            'connected_at' => time()
        ]);

        // Check if user was in an active game room
        $this->reconnectToActiveRoom($server, $fd, $userId);

        // Create player record
        $this->storage->setPlayer($fd, [
            'user_id' => $userId,
            'username' => $username,
            'room_id' => '',
            'status' => 'online',
            'cards' => '[]',
            'score' => 0,
            'last_activity' => time()
        ]);

        $server->push($fd, json_encode([
            'type' => 'auth_success',
            'user_id' => $userId,
            'username' => $username
        ]));
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

                // Check if this room's game data contains this user
                if (isset($gameData['player_user_ids']) && in_array($userId, $gameData['player_user_ids'])) {
                    // User was in this room, reconnect them
                    echo "Reconnecting user {$userId} to room {$roomId}\n";

                    // Update game data to include this connection
                    $gameData['players'][] = $fd;
                    $room['game_data'] = json_encode($gameData);

                    // Update room in storage
                    $this->storage->updateRoom($roomId, $room);

                    // Update player record
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

                    // Notify the player they've been reconnected
                    $server->push($fd, json_encode([
                        'type' => 'room_reconnected',
                        'room_id' => $roomId,
                        'room_name' => $room['name'],
                        'game_status' => $room['status']
                    ]));

                    // If the game is in progress, send them their cards
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
            echo "Error reconnecting to room: " . $e->getMessage() . "\n";
        }
    }
}
