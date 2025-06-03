<?php

namespace App\Services;

use App\Models\Game;
use App\Models\GameRoom;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Service to handle player management between database and WebSocket server
 */
class GamePlayerService
{
    /**
     * Synchronize player information between database and memory storage
     *
     * @param string $roomId Room ID
     * @param array $gameData Game data from memory
     * @return array Updated game data with synchronized player information
     */
    public static function syncPlayers(string $roomId, array $gameData): array
    {
        try {
            $dbRoom = GameRoom::with('users')->find($roomId);
            if (!$dbRoom) {
                echo "[GamePlayerService] Room {$roomId} not found in database\n";
                return $gameData;
            }

            // Initialize arrays if they don't exist
            if (!isset($gameData['player_user_ids'])) {
                $gameData['player_user_ids'] = [];
            }

            // Make sure all IDs are integers for consistent comparison
            foreach ($gameData['player_user_ids'] as $key => $id) {
                $gameData['player_user_ids'][$key] = (int)$id;
            }

            // Get user IDs from database
            $dbUserIds = $dbRoom->users->pluck('id')->map(function ($id) {
                return (int)$id;
            })->toArray();

            echo "[GamePlayerService] Database has user IDs: " . implode(", ", $dbUserIds) . "\n";
            echo "[GamePlayerService] Memory has user IDs: " . implode(", ", $gameData['player_user_ids']) . "\n";

            // Add database users to memory if not already there
            $syncedPlayerIds = $gameData['player_user_ids'];
            foreach ($dbUserIds as $dbUserId) {
                if (!in_array($dbUserId, $syncedPlayerIds, true)) {
                    echo "[GamePlayerService] Adding user ID {$dbUserId} from database to memory\n";
                    $syncedPlayerIds[] = $dbUserId;
                }
            }

            // Add memory users to database if not already there
            foreach ($gameData['player_user_ids'] as $memUserId) {
                if (!in_array($memUserId, $dbUserIds, true)) {
                    echo "[GamePlayerService] Adding user ID {$memUserId} from memory to database\n";
                    try {
                        $user = User::find($memUserId);
                        if ($user) {
                            $dbRoom->users()->syncWithoutDetaching([
                                $memUserId => [
                                    'status' => 'waiting',
                                    'score' => 0,
                                    'cards' => json_encode([])
                                ]
                            ]);
                        } else {
                            echo "[GamePlayerService] User ID {$memUserId} not found in database\n";
                        }
                    } catch (\Exception $e) {
                        echo "[GamePlayerService] Error adding user to database: " . $e->getMessage() . "\n";
                    }
                }
            }

            // Update game data with the merged player IDs
            $gameData['player_user_ids'] = array_values(array_unique($syncedPlayerIds, SORT_NUMERIC));

            // Update current_players in the database
            $dbRoom->update([
                'current_players' => count($gameData['player_user_ids'])
            ]);

            echo "[GamePlayerService] Synchronized player_user_ids: " . implode(", ", $gameData['player_user_ids']) . "\n";

            return $gameData;
        } catch (\Exception $e) {
            echo "[GamePlayerService] Error synchronizing players: " . $e->getMessage() . "\n";
            Log::error('Error synchronizing players: ' . $e->getMessage(), [
                'roomId' => $roomId,
                'gameData' => $gameData
            ]);
            return $gameData;
        }
    }

    /**
     * Get a list of all players in a room with complete information
     *
     * @param string $roomId Room ID
     * @param array $gameData Game data from memory
     * @return array List of player information
     */
    public static function getPlayersList(string $roomId, array $gameData): array
    {
        try {
            // First sync players to ensure database and memory are in agreement
            $gameData = self::syncPlayers($roomId, $gameData);

            $playersList = [];
            $addedUserIds = [];

            // Get complete room data with users from database
            $dbRoom = GameRoom::with('users')->find($roomId);
            if ($dbRoom) {
                // First add all database users
                foreach ($dbRoom->users as $dbUser) {
                    $userId = (int)$dbUser->id;
                    if (!in_array($userId, $addedUserIds)) {
                        $pivot = $dbUser->pivot;
                        $playersList[] = [
                            'username' => $dbUser->name,
                            'user_id' => $userId,
                            'score' => $pivot->score ?? 0,
                            'status' => $pivot->status ?? 'waiting',
                            'ready' => ($pivot->status ?? '') === 'ready',
                            'cards_count' => !empty($pivot->cards) ? count(json_decode($pivot->cards, true)) : 0
                        ];
                        $addedUserIds[] = $userId;
                        echo "[GamePlayerService] Added player from database: user_id={$userId}, username={$dbUser->name}\n";
                    }
                }
            }

            // Now add any players from game_data that aren't in the database yet
            if (isset($gameData['player_user_ids'])) {
                foreach ($gameData['player_user_ids'] as $userId) {
                    $userId = (int)$userId;
                    if (!in_array($userId, $addedUserIds)) {
                        $user = User::find($userId);
                        if ($user) {
                            $playersList[] = [
                                'username' => $user->name,
                                'user_id' => $userId,
                                'score' => 0,
                                'status' => 'waiting',
                                'ready' => false,
                                'cards_count' => 0
                            ];
                            $addedUserIds[] = $userId;
                            echo "[GamePlayerService] Added player from lookup: user_id={$userId}, username={$user->name}\n";
                        }
                    }
                }
            }

            echo "[GamePlayerService] Final player list has " . count($playersList) . " players\n";
            return $playersList;
        } catch (\Exception $e) {
            echo "[GamePlayerService] Error getting players list: " . $e->getMessage() . "\n";
            Log::error('Error getting players list: ' . $e->getMessage(), [
                'roomId' => $roomId
            ]);
            return [];
        }
    }
}
