<?php

namespace App\Services;

use App\Models\GameRoom;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * RoomDbService handles database operations for game rooms.
 *
 * This service bridges between the WebSocket server's in-memory storage
 * and the database persistence layer. It uses the GameRoom model, which
 * is a specialized version of the Game model focused on room management.
 *
 * Game vs GameRoom:
 * - Game is the base model that handles general game information and relationships
 * - GameRoom extends Game and adds room-specific functionality (room_id attribute,
 *   specialized methods for the WebSocket server)
 */

class RoomDbService
{
    /**
     * Save a room to the database
     *
     * @param string $roomId
     * @param array $roomData
     * @return void
     */
    public static function saveRoom(string $roomId, array $roomData): void
    {
        try {
            // Check if we're dealing with a numeric ID or a room name
            $conditions = is_numeric($roomId)
                ? ['id' => (int)$roomId]
                : ['name' => $roomData['name']];

            GameRoom::updateOrCreate($conditions, [
                'name' => $roomData['name'],
                'status' => $roomData['status'],
                'max_players' => $roomData['max_players'],
                'current_players' => $roomData['current_players'],
                'game_data' => json_decode($roomData['game_data'], true),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to save game room: ' . $e->getMessage(), [
                'roomId' => $roomId,
                'roomData' => $roomData
            ]);
            throw $e;
        }
    }

    /**
     * Get a room from the database
     *
     * @param string $roomId
     * @return array|null
     */
    public static function getRoom(string $roomId): ?array
    {
        try {
            if (is_numeric($roomId)) {
                $room = GameRoom::find((int)$roomId);
            } else {
                $room = GameRoom::where('name', $roomId)->first();
            }

            if (!$room) {
                Log::info("Room not found: {$roomId}");
                return null;
            }

            return [
                'name' => $room->name,
                'status' => $room->status,
                'max_players' => $room->max_players,
                'current_players' => $room->current_players,
                'game_data' => json_encode($room->game_data),
                'created_at' => $room->created_at->timestamp,
            ];
        } catch (\Exception $e) {
            Log::error('Error retrieving game room: ' . $e->getMessage(), ['roomId' => $roomId]);
            return null;
        }
    }

    /**
     * Delete a room from the database
     *
     * @param string $roomId
     * @return void
     */
    public static function deleteRoom(string $roomId): void
    {
        try {
            $deletedCount = 0;
            if (is_numeric($roomId)) {
                $deletedCount = GameRoom::where('id', (int)$roomId)->delete();
            } else {
                $deletedCount = GameRoom::where('name', $roomId)->delete();
            }

            Log::info("Deleted room {$roomId}", ['deletedCount' => $deletedCount]);
        } catch (\Exception $e) {
            Log::error('Error deleting game room: ' . $e->getMessage(), ['roomId' => $roomId]);
            throw $e;
        }
    }

    /**
     * Get all active rooms from the database
     *
     * @return array
     */
    public static function getAllRooms(): array
    {
        try {
            // Try to get from cache first (5 minute cache)
            return Cache::remember('active_game_rooms', 300, function() {
                $rooms = GameRoom::where('status', '!=', 'ended')
                    ->orderBy('created_at', 'desc')
                    ->get();
                $result = [];

                foreach ($rooms as $room) {
                    $result[(string)$room->id] = [
                        'name' => $room->name,
                        'status' => $room->status,
                        'max_players' => $room->max_players,
                        'current_players' => $room->current_players,
                        'game_data' => json_encode($room->game_data),
                        'created_at' => $room->created_at->timestamp,
                    ];
                }

                Log::info('Retrieved ' . count($result) . ' active game rooms');
                return $result;
            });
        } catch (\Exception $e) {
            Log::error('Error retrieving all game rooms: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Purge old game rooms from the database
     *
     * @param int $olderThanHours Hours threshold for old rooms
     * @return int Number of rooms purged
     */
    public static function purgeOldRooms(int $olderThanHours = 24): int
    {
        try {
            $cutoffDate = now()->subHours($olderThanHours);

            $count = GameRoom::where('status', 'ended')
                ->where('updated_at', '<', $cutoffDate)
                ->count();

            GameRoom::where('status', 'ended')
                ->where('updated_at', '<', $cutoffDate)
                ->delete();
            Cache::forget('active_game_rooms');

            Log::info("Purged {$count} old game rooms older than {$olderThanHours} hours");

            return $count;
        } catch (\Exception $e) {
            Log::error('Error purging old game rooms: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Clear the cache for a specific room or all rooms
     *
     * @param string|null $roomId Specific room ID or null for all rooms
     * @return void
     */
    public static function clearRoomCache(?string $roomId = null): void
    {
        try {
            if ($roomId) {
                Cache::forget('game_room_' . $roomId);
                Log::info("Cleared cache for room {$roomId}");
            }
            Cache::forget('active_game_rooms');
        } catch (\Exception $e) {
            Log::error('Error clearing room cache: ' . $e->getMessage(), [
                'roomId' => $roomId
            ]);
        }
    }

    /**
     * Get information about the room structure and model relationships
     *
     * This method provides documentation about how Game and GameRoom models
     * relate to each other, which can be useful for debugging or understanding
     * the system architecture.
     *
     * @return array
     */
    public static function getModelInfo(): array
    {
        return [
            'models' => [
                'Game' => [
                    'description' => 'Base model for all game types',
                    'table' => 'games',
                    'relationships' => ['players', 'users'],
                    'primary_key' => 'id'
                ],
                'GameRoom' => [
                    'description' => 'Specialized model extending Game for room management',
                    'table' => 'games (same as Game)',
                    'extends' => 'Game',
                    'virtual_attributes' => ['room_id'],
                    'websocket_compatible' => true
                ],
                'GamePlayer' => [
                    'description' => 'Pivot model for tracking players in games',
                    'table' => 'game_players',
                    'relationships' => ['game', 'user'],
                    'primary_key' => 'id',
                    'fields' => ['status', 'score', 'cards', 'last_action_at']
                ]
            ],
            'usage' => [
                'database_operations' => 'Use GameRoom for WebSocket server operations',
                'game_logic' => 'Use Game for general game state and relationships',
                'identifiers' => 'room_id in WebSocket server maps to id in database'
            ]
        ];
    }
}
