<?php

namespace App\Servers\Storage;

class RoomStorage
{
    private array $rooms = [];

    public function createRoom(string $roomId, string $gameServerId): void
    {
        $this->rooms[$roomId] = [
            'game_server_id' => $gameServerId,
            'created_at' => time(),
            'players' => []
        ];
    }

    public function getRoom(string $roomId): ?array
    {
        return $this->rooms[$roomId] ?? null;
    }

    public function deleteRoom(string $roomId): void
    {
        unset($this->rooms[$roomId]);
    }

    public function addPlayerToRoom(string $roomId, int $playerFd): bool
    {
        if (!isset($this->rooms[$roomId])) {
            return false;
        }

        $this->rooms[$roomId]['players'][] = $playerFd;
        return true;
    }

    public function removePlayerFromRoom(string $roomId, int $playerFd): bool
    {
        if (!isset($this->rooms[$roomId])) {
            return false;
        }

        $this->rooms[$roomId]['players'] = array_filter(
            $this->rooms[$roomId]['players'],
            fn($fd) => $fd !== $playerFd
        );

        return true;
    }

    public function getRoomsByGameServer(string $gameServerId): array
    {
        return array_filter(
            $this->rooms,
            fn($room) => $room['game_server_id'] === $gameServerId
        );
    }

    public function getAllRooms(): array
    {
        return $this->rooms;
    }
} 