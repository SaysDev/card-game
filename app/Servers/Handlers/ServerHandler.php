<?php

namespace App\Servers\Handlers;

class ServerHandler extends BaseMessageHandler
{
    private array $gameServers = [];
    private array $roomServers = [];

    public function handleRegisterServer(int $fd, array $data): void
    {
        try {
            $serverId = $data['server_id'] ?? null;
            $capacity = $data['capacity'] ?? null;

            if (!$serverId || !$capacity) {
                $this->logger->warning("Missing server registration data", ['fd' => $fd]);
                $this->sendError($fd, "Missing server_id or capacity");
                return;
            }

            // Store server info
            $this->gameServers[$fd] = [
                'server_id' => $serverId,
                'capacity' => $capacity,
                'last_ping' => time()
            ];

            $this->logger->info("Game server registered", [
                'fd' => $fd,
                'server_id' => $serverId,
                'capacity' => $capacity
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

    public function handleRegisterRoom(array $data): void
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

    public function handleRoomCreatedAck(array $data): void
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

        // Store the mapping
        $this->roomServers[$roomId] = $serverId;
    }

    public function handlePlayerAddedToRoomAck(array $data): void
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
    }

    public function findLeastLoadedGameServer(): ?int
    {
        $leastLoadedFd = null;
        $lowestRoomCount = PHP_INT_MAX;
        
        // No need to continue if no game servers available
        if (empty($this->gameServers)) {
            $this->logger->warning("No game servers available");
            return null;
        }
        
        // Find the server with the lowest room count
        foreach ($this->gameServers as $fd => $server) {
            // Skip invalid server connections
            if (!isset($server['server_id']) || !$this->server->isEstablished($fd)) {
                continue;
            }
            
            // Count rooms assigned to this server
            $roomCount = 0;
            foreach ($this->roomServers as $roomId => $serverId) {
                if ($serverId === $server['server_id']) {
                    $roomCount++;
                }
            }
            
            if ($roomCount < $lowestRoomCount) {
                $lowestRoomCount = $roomCount;
                $leastLoadedFd = $fd;
            }
        }
        
        if ($leastLoadedFd !== null) {
            $this->logger->info("Selected least loaded game server", [
                'fd' => $leastLoadedFd,
                'server_id' => $this->gameServers[$leastLoadedFd]['server_id'] ?? 'unknown',
                'room_count' => $lowestRoomCount
            ]);
        }
        
        return $leastLoadedFd;
    }

    public function getGameServerForRoom(string $roomId): ?string
    {
        return $this->roomServers[$roomId] ?? null;
    }

    public function findGameServerFdById(string $serverId): ?int
    {
        foreach ($this->gameServers as $fd => $server) {
            if (
                isset($server['server_id']) && 
                $server['server_id'] === $serverId &&
                $this->server->isEstablished($fd)
            ) {
                return $fd;
            }
        }
        return null;
    }

    public function removeGameServer(int $fd): void
    {
        if (isset($this->gameServers[$fd])) {
            $serverId = $this->gameServers[$fd]['server_id'] ?? null;
            unset($this->gameServers[$fd]);
            
            if ($serverId) {
                // Update all rooms that were on this server
                foreach ($this->roomServers as $roomId => $roomServerId) {
                    if ($roomServerId === $serverId) {
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
        }
    }

    public function verifyAndRestoreGameServers(): void
    {
        $this->logger->info("Verifying game servers", [
            'current_servers' => $this->gameServers
        ]);
        
        foreach ($this->gameServers as $fd => $server) {
            if (!$this->server->isEstablished($fd)) {
                $this->logger->warning("Game server connection lost", [
                    'fd' => $fd,
                    'server_id' => $server['id']
                ]);
                
                // Remove server
                unset($this->gameServers[$fd]);
                
                // Remove all room mappings for this server
                foreach ($server['rooms'] as $roomId) {
                    unset($this->roomServers[$roomId]);
                }
            }
        }

        $this->logger->info("Game server verification complete", [
            'active_servers' => count($this->gameServers),
            'servers' => $this->gameServers
        ]);
    }

    public function getGameServers(): array
    {
        $this->logger->debug("Getting game servers", [
            'servers' => $this->gameServers
        ]);
        return $this->gameServers;
    }
} 