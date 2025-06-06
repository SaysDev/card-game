<?php

namespace App\Servers\Storage;

class GameServerStorage
{
    private array $servers = [];

    public function registerServer(string $serverId, int $fd, int $capacity): void
    {
        $this->servers[$serverId] = [
            'fd' => $fd,
            'capacity' => $capacity,
            'registered_at' => time(),
            'rooms' => []
        ];
    }

    public function unregisterServer(string $serverId): void
    {
        unset($this->servers[$serverId]);
    }

    public function getServer(string $serverId): ?array
    {
        return $this->servers[$serverId] ?? null;
    }

    public function addRoomToServer(string $serverId, string $roomId): bool
    {
        if (!isset($this->servers[$serverId])) {
            return false;
        }

        $this->servers[$serverId]['rooms'][] = $roomId;
        return true;
    }

    public function removeRoomFromServer(string $serverId, string $roomId): bool
    {
        if (!isset($this->servers[$serverId])) {
            return false;
        }

        $this->servers[$serverId]['rooms'] = array_filter(
            $this->servers[$serverId]['rooms'],
            fn($id) => $id !== $roomId
        );

        return true;
    }

    public function getServerByFd(int $fd): ?string
    {
        foreach ($this->servers as $serverId => $server) {
            if ($server['fd'] === $fd) {
                return $serverId;
            }
        }
        return null;
    }

    public function getAllServers(): array
    {
        return $this->servers;
    }

    public function getAvailableServer(): ?string
    {
        foreach ($this->servers as $serverId => $server) {
            if (count($server['rooms']) < $server['capacity']) {
                return $serverId;
            }
        }
        return null;
    }
} 