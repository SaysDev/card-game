<?php

namespace App\Servers\Handlers;

use App\Servers\Storage\MemoryStorage;
use App\Servers\Utilities\Logger;
use OpenSwoole\WebSocket\Server;

abstract class BaseMessageHandler
{
    protected Server $server;
    protected MemoryStorage $storage;
    protected Logger $logger;

    public function __construct(Server $server, MemoryStorage $storage, Logger $logger)
    {
        $this->server = $server;
        $this->storage = $storage;
        $this->logger = $logger;
    }

    protected function sendMessage(int $fd, array $data): void
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

    protected function sendError(int $fd, string $message): void
    {
        $this->sendMessage($fd, [
            'type' => 'error',
            'message' => $message
        ]);
    }

    protected function validatePlayer(int $fd): ?array
    {
        $player = $this->storage->getPlayer($fd);
        if (!$player) {
            $this->logger->warning("Player not found", ['fd' => $fd]);
            $this->sendError($fd, "Authentication required");
            return null;
        }

        $isAuthenticated = isset($player['authenticated']) ? $player['authenticated'] : 
                          (isset($player['user_id']) && !empty($player['user_id']));
        
        if (!$isAuthenticated) {
            $this->logger->warning("Unauthenticated message", ['fd' => $fd]);
            $this->sendError($fd, "Authentication required");
            return null;
        }

        return $player;
    }

    protected function validateRoom(string $roomId): ?array
    {
        if (!$this->storage->roomExists($roomId)) {
            $this->logger->warning("Room not found", ['room_id' => $roomId]);
            return null;
        }

        return $this->storage->getRoom($roomId);
    }

    protected function broadcastToRoom(string $roomId, array $message): void
    {
        $room = $this->validateRoom($roomId);
        if (!$room) {
            return;
        }

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
} 