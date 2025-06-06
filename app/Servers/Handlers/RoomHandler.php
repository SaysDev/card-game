<?php

namespace App\Servers\Handlers;

use OpenSwoole\WebSocket\Server;
use App\Servers\Storage\MemoryStorage;
use App\Servers\Utilities\Logger;
use App\Servers\Utilities\GameUtilities;

class RoomHandler extends BaseMessageHandler
{
    private array $roomServers = [];
    private array $pendingRoomJoins = [];

    public function __construct(Server $server, MemoryStorage $storage, Logger $logger)
    {
        parent::__construct($server, $storage, $logger);
    }

    public function handleCreateRoom(int $fd, array $data): void
    {
        if (!isset($data['room_id'])) {
            $this->sendError($fd, "Missing room_id");
            return;
        }

        $roomId = $data['room_id'];
        $player = $this->validatePlayer($fd);
        if (!$player) {
            return;
        }

        $this->logger->info("Creating room", [
            'room_id' => $roomId,
            'user_id' => $player['user_id'],
            'username' => $player['username']
        ]);

        $room = [
            'name' => $data['name'] ?? 'Room ' . $roomId,
            'status' => 'waiting',
            'max_players' => $data['max_players'] ?? 4,
            'game_data' => json_encode([
                'players' => [],
                'created_by' => $player['user_id'],
                'created_at' => time()
            ])
        ];

        $this->storage->setRoom($roomId, $room);
        $this->handleJoinRoom($fd, ['room_id' => $roomId]);
    }

    public function handleJoinRoom(int $fd, array $data): void
    {
        if (!isset($data['room_id'])) {
            $this->sendError($fd, "Missing room_id");
            return;
        }

        $roomId = $data['room_id'];
        $player = $this->validatePlayer($fd);
        if (!$player) {
            return;
        }

        $room = $this->validateRoom($roomId);
        if (!$room) {
            $this->sendError($fd, "Room not found");
            return;
        }

        $gameData = json_decode($room['game_data'], true);
        if (count($gameData['players']) >= $room['max_players']) {
            $this->sendError($fd, "Room is full");
            return;
        }

        $this->logger->info("Player joining room", [
            'room_id' => $roomId,
            'user_id' => $player['user_id'],
            'username' => $player['username']
        ]);

        $gameData['players'][] = [
            'fd' => $fd,
            'user_id' => $player['user_id'],
            'username' => $player['username'],
            'ready' => false
        ];

        $room['game_data'] = json_encode($gameData);
        $this->storage->setRoom($roomId, $room);

        $this->broadcastRoomUpdate($roomId);
    }

    public function handleLeaveRoom(int $fd, array $data): void
    {
        if (!isset($data['room_id'])) {
            $this->sendError($fd, "Missing room_id");
            return;
        }

        $roomId = $data['room_id'];
        $player = $this->validatePlayer($fd);
        if (!$player) {
            return;
        }

        $room = $this->validateRoom($roomId);
        if (!$room) {
            return;
        }

        $this->logger->info("Player leaving room", [
            'room_id' => $roomId,
            'user_id' => $player['user_id'],
            'username' => $player['username']
        ]);

        $gameData = json_decode($room['game_data'], true);
        $gameData['players'] = array_filter($gameData['players'], function($p) use ($fd) {
            return $p['fd'] !== $fd;
        });

        if (empty($gameData['players'])) {
            $this->storage->removeRoom($roomId);
            $this->logger->info("Room removed - no players left", ['room_id' => $roomId]);
        } else {
            $room['game_data'] = json_encode($gameData);
            $this->storage->setRoom($roomId, $room);
            $this->broadcastRoomUpdate($roomId);
        }
    }

    public function broadcastRoomUpdate(string $roomId): void
    {
        $room = $this->validateRoom($roomId);
        if (!$room) {
            return;
        }

        $this->broadcastToRoom($roomId, [
            'type' => 'room_update',
            'room_id' => $roomId,
            'room' => $room
        ]);
    }

    public function findSuitableRoom(int $size, bool $isPrivate, ?string $privateCode): ?string
    {
        $rooms = $this->storage->getAllRooms();
        
        foreach ($rooms as $roomId => $room) {
            // Skip if room is full
            if (count($room['players']) >= $room['max_players']) {
                continue;
            }

            // Check if private room matches code
            if ($room['is_private'] && $room['private_code'] !== $privateCode) {
                continue;
            }

            // Check if room size matches
            if ($room['max_players'] !== $size) {
                continue;
            }

            // Check if game type matches
            if ($room['game_type'] !== $gameType) {
                continue;
            }

            return $roomId;
        }

        return null;
    }

    public function createRoomAndAddPlayer(int $fd, int $size, bool $isPrivate, ?string $privateCode): void
    {
        $roomId = GameUtilities::generateRoomId();
        $player = $this->storage->getPlayer($fd);
        
        $roomData = [
            'id' => $roomId,
            'max_players' => $size,
            'is_private' => $isPrivate,
            'private_code' => $privateCode,
            'players' => [$fd => $player],
            'game_type' => $player['game_type'] ?? 'default',
            'status' => 'waiting',
            'created_at' => time()
        ];

        $this->storage->createRoom($roomId, $roomData);
        $this->broadcastRoomUpdate($roomId);
        
        $this->sendMessage($fd, [
            'type' => 'room_created',
            'room_id' => $roomId
        ]);
    }

    public function addPlayerToRoom(int $fd, string $roomId, string $gameServerId): void
    {
        $room = $this->storage->getRoom($roomId);
        if (!$room) {
            $this->sendError($fd, 'Room not found');
            return;
        }

        $player = $this->storage->getPlayer($fd);
        if (!$player) {
            $this->sendError($fd, 'Player not found');
            return;
        }

        // Add player to room
        $room['players'][$fd] = $player;
        $this->storage->updateRoom($roomId, $room);

        // Notify game server
        $gameServerFd = $this->findGameServerFdById($gameServerId);
        if ($gameServerFd) {
            $this->server->push($gameServerFd, json_encode([
                'type' => 'player_join',
                'room_id' => $roomId,
                'player' => $player
            ]));
        }

        $this->broadcastRoomUpdate($roomId);
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

    public function broadcastToRoom(string $roomId, array $message): void
    {
        $room = $this->validateRoom($roomId);
        if (!$room) {
            return;
        }

        $gameData = json_decode($room['game_data'], true);
        if (!isset($gameData['players']) || !is_array($gameData['players'])) {
            return;
        }

        foreach ($gameData['players'] as $player) {
            if (isset($player['fd']) && $this->server->isEstablished($player['fd'])) {
                $this->sendMessage($player['fd'], $message);
            }
        }
    }

    public function broadcastToRoomExcept(Server $server, string $roomId, int $excludeFd, array $message): void
    {
        $room = $this->validateRoom($roomId);
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

    private function startRoomTimer(string $roomId): void
    {
        $timerId = $this->server->tick(1000, function() use ($roomId) {
            $room = $this->validateRoom($roomId);
            if (!$room) {
                $this->server->clearTimer($this->storage->getRoomTimer($roomId));
                return;
            }

            $gameData = json_decode($room['game_data'], true);
            if (!isset($gameData['start_time'])) {
                $gameData['start_time'] = time();
                $room['game_data'] = json_encode($gameData);
                $this->storage->setRoom($roomId, $room);
            }

            $elapsedTime = time() - $gameData['start_time'];
            if ($elapsedTime >= 60) {
                $this->server->clearTimer($this->storage->getRoomTimer($roomId));
                $this->handleRoomTimeout($roomId);
            }
        });

        $this->storage->setRoomTimer($roomId, $timerId);
    }

    private function handleRoomTimeout(string $roomId): void
    {
        $room = $this->validateRoom($roomId);
        if (!$room) {
            return;
        }

        $this->logger->info("Room timeout", ['room_id' => $roomId]);
        $this->broadcastToRoom($roomId, [
            'type' => 'room_timeout',
            'room_id' => $roomId,
            'message' => 'Room timed out due to inactivity'
        ]);

        $this->storage->removeRoom($roomId);
    }

    protected function validatePlayer(int $fd): ?array
    {
        if (!$this->storage->playerExists($fd)) {
            $this->logger->warning("Player fd $fd not found in storage", ['method' => 'validatePlayer']);
            return null;
        }

        $player = $this->storage->getPlayer($fd);
        if (!isset($player['user_id']) || !isset($player['username'])) {
            $this->logger->warning("Invalid player data", [
                'fd' => $fd,
                'player' => $player
            ]);
            return null;
        }

        return $player;
    }

    protected function validateRoom(string $roomId): ?array
    {
        $room = $this->storage->getRoom($roomId);
        if (!$room) {
            $this->logger->warning("Room not found", ['room_id' => $roomId]);
            return null;
        }

        return $room;
    }

    private function findGameServerFdById(string $serverId): ?int
    {
        $servers = $this->storage->getAllServers();
        foreach ($servers as $fd => $server) {
            if ($server['id'] === $serverId) {
                return $fd;
            }
        }
        return null;
    }
}
