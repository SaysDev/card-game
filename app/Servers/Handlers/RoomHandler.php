<?php

namespace App\Servers\Handlers;

use App\Servers\Storage\MemoryStorage;
use App\Servers\Utilities\GameUtilities;
use OpenSwoole\WebSocket\Server;

class RoomHandler
{
    private MemoryStorage $storage;

    public function __construct(MemoryStorage $storage)
    {
        $this->storage = $storage;
    }

    public function handleCreateRoom(Server $server, int $fd, array $data): void
    {
        if (!$this->storage->playerExists($fd)) {
            $server->push($fd, json_encode([
                'type' => 'error',
                'message' => 'You must authenticate first'
            ]));
            return;
        }

        if (!isset($data['room_name']) || !isset($data['max_players'])) {
            $server->push($fd, json_encode([
                'type' => 'error',
                'message' => 'Room name and max players are required'
            ]));
            return;
        }

        $roomId = uniqid('room_');
        $roomName = $data['room_name'];
        $maxPlayers = min(max((int) $data['max_players'], 2), 8); // Between 2 and 8 players

        // Create the game room
        $this->storage->createRoom($roomId, [
            'name' => $roomName,
            'status' => 'waiting',
            'max_players' => $maxPlayers,
            'current_players' => 1,
            'game_data' => json_encode([
                'players' => [$fd],
                'deck' => GameUtilities::createNewDeck(),
                'current_turn' => -1,
                'game_started' => false,
                'last_updated' => time()
            ]),
            'created_at' => time()
        ]);

        // Update player status
        $player = $this->storage->getPlayer($fd);
        $this->storage->setPlayer($fd, [
            'user_id' => $player['user_id'],
            'username' => $player['username'],
            'room_id' => $roomId,
            'status' => 'playing',
            'cards' => '[]',
            'score' => $player['score'],
            'last_activity' => time()
        ]);

        $server->push($fd, json_encode([
            'type' => 'room_created',
            'room_id' => $roomId,
            'room_name' => $roomName,
            'max_players' => $maxPlayers
        ]));
    }

    public function handleJoinRoom(Server $server, int $fd, array $data): void
    {
        echo 'Joining room' . PHP_EOL;

        if (!$this->storage->playerExists($fd)) {
            echo 'Player not found' . PHP_EOL;
            $server->push($fd, json_encode([
                'type' => 'error',
                'message' => 'You must authenticate first'
            ]));
            return;
        }

        if (!isset($data['room_id'])) {
            echo 'Room ID not found' . PHP_EOL;
            $server->push($fd, json_encode([
                'type' => 'error',
                'message' => 'Room ID is required'
            ]));
            return;
        }

        $roomId = $data['room_id'];

        // Try to find room with the provided ID
        $room = $this->storage->getRoom($roomId);

        // If not found, try with 'room_' prefix if it doesn't have one already
        if (!$room && !str_starts_with($roomId, 'room_')) {
            $prefixedRoomId = 'room_' . $roomId;
            $room = $this->storage->getRoom($prefixedRoomId);
            if ($room) {
                $roomId = $prefixedRoomId; // Update roomId to the prefixed version
            }
        }

        // If still not found, try without 'room_' prefix if it has one
        if (!$room && str_starts_with($roomId, 'room_')) {
            $unprefixedRoomId = substr($roomId, 5); // Remove 'room_' prefix
            $room = $this->storage->getRoom($unprefixedRoomId);
            if ($room) {
                $roomId = $unprefixedRoomId; // Update roomId to the unprefixed version
            }
        }

        if (!$room) {
            echo 'Room not found' . PHP_EOL;
            $server->push($fd, json_encode([
                'type' => 'error',
                'message' => 'Room not found'
            ]));
            return;
        }

        if ($room['status'] !== 'waiting') {
            $server->push($fd, json_encode([
                'type' => 'error',
                'message' => 'Cannot join a game that has already started or ended'
            ]));
            return;
        }

        if ($room['current_players'] >= $room['max_players']) {
            $server->push($fd, json_encode([
                'type' => 'error',
                'message' => 'Room is full'
            ]));
            return;
        }

        // Update game data to include the new player
        $gameData = json_decode($room['game_data'], true);

        // Get the current player's user_id
        $player = $this->storage->getPlayer($fd);
        $currentUserId = $player['user_id'];

        // Check if this user already has a connection in the room
        $existingPlayerFds = [];
        foreach ($gameData['players'] as $playerFd) {
            if ($this->storage->playerExists($playerFd)) {
                $existingPlayerData = $this->storage->getPlayer($playerFd);

                // If we find a connection with the same user_id
                if ($existingPlayerData['user_id'] === $currentUserId && $playerFd !== $fd) {
                    echo "Found existing connection for user {$currentUserId}: {$playerFd}" . PHP_EOL;
                    $existingPlayerFds[] = $playerFd;
                }
            }
        }

        // Remove all existing connections for this user
        foreach ($existingPlayerFds as $existingFd) {
            // Remove player from room
            echo "Removing player connection {$existingFd} from room" . PHP_EOL;
            $gameData['players'] = array_values(array_filter($gameData['players'], function($p) use ($existingFd) {
                return $p !== $existingFd;
            }));

            // Update the old connection to show it's no longer in a room
            if ($this->storage->playerExists($existingFd)) {
                $oldPlayer = $this->storage->getPlayer($existingFd);
                $this->storage->setPlayer($existingFd, [
                    'user_id' => $oldPlayer['user_id'],
                    'username' => $oldPlayer['username'],
                    'room_id' => '', // Clear room_id
                    'status' => 'online',
                    'cards' => '[]',
                    'score' => $oldPlayer['score'],
                    'last_activity' => time()
                ]);

                // Tell this old connection it's been disconnected from room
                $server->push($existingFd, json_encode([
                    'type' => 'left_room',
                    'message' => 'You have connected from another location and been removed from this room'
                ]));
            }
        }

        // Now add the current connection
        if (!in_array($fd, $gameData['players'])) {
            $gameData['players'][] = $fd;
        }

        $gameData['last_updated'] = time();

        // Update room data
        $this->storage->updateRoom($roomId, [
            'name' => $room['name'],
            'status' => $room['status'],
            'max_players' => $room['max_players'],
            'current_players' => $room['current_players'] + 1,
            'game_data' => json_encode($gameData),
            'created_at' => $room['created_at']
        ]);

        // Update player data
        $player = $this->storage->getPlayer($fd);
        $this->storage->setPlayer($fd, [
            'user_id' => $player['user_id'],
            'username' => $player['username'],
            'room_id' => $roomId,
            'status' => 'playing',
            'cards' => '[]',
            'score' => $player['score'],
            'last_activity' => time()
        ]);

        // Notify all players in the room
        $this->broadcastToRoom($server, $roomId, [
            'type' => 'player_joined',
            'player' => [
                'username' => $player['username'],
                'user_id' => $player['user_id'],
                'status' => $player['status'],
                'ready' => $player['status'] === 'ready',
                'score' => $player['score'],
                'cards_count' => 0
            ],
            'current_players' => $room['current_players'] + 1,
            'max_players' => $room['max_players']
        ]);

        // Send room details to the joining player
        $playersList = [];
        $addedUserIds = []; // Track added user IDs to prevent duplicates

        foreach ($gameData['players'] as $playerFd) {
            if ($this->storage->playerExists($playerFd)) {
                $playerData = $this->storage->getPlayer($playerFd);
                $userId = $playerData['user_id'];

                // Skip if we've already added this user
                if (in_array($userId, $addedUserIds)) {
                    continue;
                }

                $addedUserIds[] = $userId;
                $playersList[] = [
                    'username' => $playerData['username'],
                    'user_id' => $userId,
                    'score' => $playerData['score'],
                    'status' => $playerData['status'],
                    'ready' => $playerData['status'] === 'ready',
                    'cards_count' => 0
                ];
            }
        }

        $server->push($fd, json_encode([
            'type' => 'room_joined',
            'room_id' => $roomId,
            'room_name' => $room['name'],
            'players' => $playersList,
            'current_players' => $room['current_players'] + 1,
            'max_players' => $room['max_players']
        ]));

        // Start the game if the room is full
        if ($room['current_players'] + 1 >= $room['max_players']) {
            $gameHandler = new GameHandler($this->storage);
            $gameHandler->startGame($server, $roomId);
        }
    }

    /**
     * Handle player ready status change
     */
    public function handleSetReadyStatus(Server $server, int $fd, array $data): void
    {
        if (!$this->storage->playerExists($fd)) {
            $server->push($fd, json_encode([
                'type' => 'error',
                'message' => 'You are not authenticated'
            ]));
            return;
        }

        $player = $this->storage->getPlayer($fd);
        $roomId = $player['room_id'];

        if (!$roomId || !$this->storage->roomExists($roomId)) {
            $server->push($fd, json_encode([
                'type' => 'error',
                'message' => 'You are not in a game room'
            ]));
            return;
        }

        $room = $this->storage->getRoom($roomId);
        $isReady = isset($data['ready']) && $data['ready'] === true;
        $newStatus = $isReady ? 'ready' : 'waiting';

        // Update player status
        $this->storage->setPlayer($fd, [
            'user_id' => $player['user_id'],
            'username' => $player['username'],
            'room_id' => $roomId,
            'status' => $newStatus,
            'cards' => $player['cards'],
            'score' => $player['score'],
            'last_activity' => time()
        ]);

        // Notify the player that their status was updated
        $server->push($fd, json_encode([
            'type' => 'ready_status_updated',
            'ready' => $isReady,
            'status' => $newStatus
        ]));

        // Notify all players in the room about this player's status change
        $this->broadcastToRoomExcept($server, $roomId, $fd, [
            'type' => 'player_status_changed',
            'player_id' => $player['user_id'],
            'username' => $player['username'],
            'status' => $newStatus,
            'ready' => $isReady
        ]);

        // Check if all players are ready and we can start the game
        $this->checkAllPlayersReady($server, $roomId);
    }

    /**
     * Check if all players in a room are ready to start the game
     */
    private function checkAllPlayersReady(Server $server, string $roomId): void
    {
        if (!$this->storage->roomExists($roomId)) {
            return;
        }

        $room = $this->storage->getRoom($roomId);
        $gameData = json_decode($room['game_data'], true);

        // Ensure we have at least 2 players
        if (count($gameData['players']) < 2) {
            return;
        }

        // Check if all players are ready
        $allReady = true;
        foreach ($gameData['players'] as $playerFd) {
            if (!$this->storage->playerExists($playerFd)) {
                continue;
            }

            $player = $this->storage->getPlayer($playerFd);
            if ($player['status'] !== 'ready') {
                $allReady = false;
                break;
            }
        }

        // If all players are ready, start the game
        if ($allReady && $room['status'] === 'waiting') {
            $gameHandler = new GameHandler($this->storage);
            $gameHandler->startGame($server, $roomId);
        }
    }

    public function handleLeaveRoom(Server $server, int $fd): void
    {
        if (!$this->storage->playerExists($fd)) {
            return;
        }

        $player = $this->storage->getPlayer($fd);
        $roomId = $player['room_id'];

        if (!$roomId || !$this->storage->roomExists($roomId)) {
            // Player wasn't in a room
            return;
        }

        $room = $this->storage->getRoom($roomId);
        $gameData = json_decode($room['game_data'], true);

        // Remove player from the game data
        $gameData['players'] = array_values(array_filter($gameData['players'], function ($playerFd) use ($fd) {
            return $playerFd != $fd;
        }));
        $gameData['last_updated'] = time();

        // Update player status
        $this->storage->setPlayer($fd, [
            'user_id' => $player['user_id'],
            'username' => $player['username'],
            'room_id' => '',
            'status' => 'online',
            'cards' => '[]',
            'score' => $player['score'],
            'last_activity' => time()
        ]);

        // If no players left, delete the room
        if (empty($gameData['players'])) {
            $this->storage->removeRoom($roomId);
        } else {
            // Otherwise update room data
            $this->storage->updateRoom($roomId, [
                'name' => $room['name'],
                'status' => $room['status'],
                'max_players' => $room['max_players'],
                'current_players' => $room['current_players'] - 1,
                'game_data' => json_encode($gameData),
                'created_at' => $room['created_at']
            ]);

            // Notify remaining players
            $this->broadcastToRoom($server, $roomId, [
                'type' => 'player_left',
                'username' => $player['username'],
                'user_id' => $player['user_id'],
                'current_players' => $room['current_players'] - 1
            ]);

            // If the game was in progress, handle the player leaving mid-game
            if ($room['status'] === 'playing') {
                $gameHandler = new GameHandler($this->storage);
                $gameHandler->handlePlayerLeavingGame($server, $roomId, $fd, $gameData);
            }
        }

        // Notify the player who left
        if ($server->isEstablished($fd)) {
            $server->push($fd, json_encode([
                'type' => 'left_room',
                'room_id' => $roomId
            ]));
        }
    }

    public function handleListRooms(Server $server, int $fd): void
    {
        if (!$this->storage->playerExists($fd)) {
            $server->push($fd, json_encode([
                'type' => 'error',
                'message' => 'You must authenticate first'
            ]));
            return;
        }

        $rooms = [];
        foreach ($this->storage->getAllRooms() as $id => $room) {
            $rooms[] = [
                'room_id' => $id,
                'name' => $room['name'],
                'status' => $room['status'],
                'current_players' => $room['current_players'],
                'max_players' => $room['max_players']
            ];
        }

        $server->push($fd, json_encode([
            'type' => 'room_list',
            'rooms' => $rooms
        ]));
    }

    public function broadcastToRoom(Server $server, string $roomId, array $message): void
    {
        $room = $this->storage->getRoom($roomId);
        if (!$room) {
            return;
        }

        $gameData = json_decode($room['game_data'], true);
        $messageJson = json_encode($message);

        foreach ($gameData['players'] as $playerFd) {
            if ($server->isEstablished($playerFd)) {
                $server->push($playerFd, $messageJson);
            }
        }
    }

    public function broadcastToRoomExcept(Server $server, string $roomId, int $excludeFd, array $message): void
    {
        $room = $this->storage->getRoom($roomId);
        if (!$room) {
            return;
        }

        $gameData = json_decode($room['game_data'], true);
        $messageJson = json_encode($message);

        foreach ($gameData['players'] as $playerFd) {
            if ($playerFd != $excludeFd && $server->isEstablished($playerFd)) {
                $server->push($playerFd, $messageJson);
            }
        }
    }
}
