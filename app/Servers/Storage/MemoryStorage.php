<?php

namespace App\Servers\Storage;

use App\Services\RoomDbService;
use OpenSwoole\Table;

class MemoryStorage
{
    private Table $gameRooms;
    private Table $players;
    private Table $connections;
    private bool $isInitialized = false;

    public function __construct()
    {
        $this->initializeTables();
        $this->loadRoomsFromDatabase();
    }

    private function initializeTables(): void
    {
        // Table for game rooms
        $this->gameRooms = new Table(1024);
        $this->gameRooms->column('name', Table::TYPE_STRING, 64);       // Room name
        $this->gameRooms->column('status', Table::TYPE_STRING, 16);     // 'waiting', 'playing', 'ended'
        $this->gameRooms->column('max_players', Table::TYPE_INT, 4);    // Max players in room
        $this->gameRooms->column('current_players', Table::TYPE_INT, 4); // Current player count
        $this->gameRooms->column('game_data', Table::TYPE_STRING, 8192); // JSON encoded game state
        $this->gameRooms->column('created_at', Table::TYPE_INT, 8);     // Unix timestamp
        $this->gameRooms->create();

        // Table for players
        $this->players = new Table(4096);
        $this->players->column('user_id', Table::TYPE_INT, 8);           // User ID from database
        $this->players->column('username', Table::TYPE_STRING, 64);      // Player username
        $this->players->column('room_id', Table::TYPE_STRING, 64);       // Current room ID
        $this->players->column('status', Table::TYPE_STRING, 16);        // 'online', 'playing', 'spectating'
        $this->players->column('cards', Table::TYPE_STRING, 1024);       // JSON encoded cards data
        $this->players->column('score', Table::TYPE_INT, 4);             // Player score
        $this->players->column('last_activity', Table::TYPE_INT, 8);     // Last activity timestamp
        $this->players->create();

        // Table for mapping connections to players
        $this->connections = new Table(4096);
        $this->connections->column('fd', Table::TYPE_INT, 8);            // Connection file descriptor
        $this->connections->column('user_id', Table::TYPE_INT, 8);       // User ID
        $this->connections->column('connected_at', Table::TYPE_INT, 8);  // Connection time
        $this->connections->create();
    }

    // Room methods

    public function createRoom(string $roomId, array $roomData): void
    {
        $this->gameRooms->set($roomId, $roomData);

        // Save to database
        try {
            RoomDbService::saveRoom($roomId, $roomData);
        } catch (\Exception $e) {
            echo "Error saving room to database: " . $e->getMessage() . "\n";
        }
    }

    public function getRoom(string $roomId): ?array
    {
        if ($this->gameRooms->exists($roomId)) {
            return $this->gameRooms->get($roomId);
        }

        // Try to get from database if not in memory
        try {
            $roomData = RoomDbService::getRoom($roomId);
            if ($roomData) {
                // Add to memory storage
                $this->gameRooms->set($roomId, $roomData);
                return $roomData;
            }
        } catch (\Exception $e) {
            echo "Error fetching room from database: " . $e->getMessage() . "\n";
        }

        return null;
    }

    public function updateRoom(string $roomId, array $roomData): void
    {
        if ($this->gameRooms->exists($roomId)) {
            $this->gameRooms->set($roomId, $roomData);

            // Save to database
            try {
                RoomDbService::saveRoom($roomId, $roomData);
            } catch (\Exception $e) {
                echo "Error updating room in database: " . $e->getMessage() . "\n";
            }
        }
    }

    public function removeRoom(string $roomId): void
    {
        if ($this->gameRooms->exists($roomId)) {
            $this->gameRooms->del($roomId);

            // Remove from database
            try {
                RoomDbService::deleteRoom($roomId);
            } catch (\Exception $e) {
                echo "Error removing room from database: " . $e->getMessage() . "\n";
            }
        }
    }

    public function roomExists(string $roomId): bool
    {
        return $this->gameRooms->exists($roomId);
    }

    public function getAllRooms(): array
    {
        $rooms = [];
        foreach ($this->gameRooms as $id => $room) {
            $rooms[$id] = $room;
        }
        return $rooms;
    }

    // Player methods

    public function setPlayer(int $fd, array $playerData): void
    {
        $this->players->set($fd, $playerData);
    }

    public function getPlayer(int $fd): ?array
    {
        if ($this->players->exists($fd)) {
            return $this->players->get($fd);
        }
        return null;
    }

    public function updatePlayer(int $fd, array $playerData): void
    {
        if ($this->players->exists($fd)) {
            $this->players->set($fd, $playerData);
        }
    }

    public function removePlayer(int $fd): void
    {
        if ($this->players->exists($fd)) {
            $this->players->del($fd);
        }
    }

    public function playerExists(int $fd): bool
    {
        return $this->players->exists($fd);
    }

    public function getPlayersInRoom(string $roomId): array
    {
        $players = [];
        foreach ($this->players as $fd => $player) {
            if ($player['room_id'] === $roomId) {
                $players[$fd] = $player;
            }
        }
        return $players;
    }

    /**
     * Find all connections for a specific user ID
     */
    public function getConnectionsByUserId(int $userId): array
    {
        $connections = [];
        foreach ($this->players as $fd => $player) {
            if ($player['user_id'] === $userId) {
                $connections[$fd] = $player;
            }
        }
        return $connections;
    }

    // Connection methods

    public function setConnection(int $fd, array $connectionData): void
    {
        $this->connections->set($fd, $connectionData);
    }

    public function getConnection(int $fd): ?array
    {
        if ($this->connections->exists($fd)) {
            return $this->connections->get($fd);
        }
        return null;
    }

    public function removeConnection(int $fd): void
    {
        if ($this->connections->exists($fd)) {
            $this->connections->del($fd);
        }
    }

    public function connectionExists(int $fd): bool
    {
        return $this->connections->exists($fd);
    }

    // Cleanup methods

    public function cleanupInactiveRooms(): void
    {
        $currentTime = time();
        $inactiveThreshold = 3600; // 1 hour

        foreach ($this->gameRooms as $roomId => $room) {
            $gameData = json_decode($room['game_data'], true);

            // Check if room has been inactive
            if (isset($gameData['last_updated']) && ($currentTime - $gameData['last_updated']) > $inactiveThreshold) {
                // Remove the room
                $this->removeRoom($roomId);

                // Update player records to remove them from this room
                foreach ($this->players as $fd => $player) {
                    if ($player['room_id'] === $roomId) {
                        $this->players->set($fd, [
                            'user_id' => $player['user_id'],
                            'username' => $player['username'],
                            'room_id' => '',
                            'status' => 'online',
                            'cards' => '[]',
                            'score' => $player['score'],
                            'last_activity' => $player['last_activity']
                        ]);
                    }
                }
            }
        }
    }

    /**
     * Load rooms from database into memory storage
     */
    private function loadRoomsFromDatabase(): void
    {
        if ($this->isInitialized) {
            return;
        }

        try {
            // Get all active rooms from database
            $rooms = RoomDbService::getAllRooms();

            // Load each room into memory
            foreach ($rooms as $roomId => $roomData) {
                $this->gameRooms->set($roomId, $roomData);
            }

            echo "Loaded " . count($rooms) . " rooms from database\n";
            $this->isInitialized = true;
        } catch (\Exception $e) {
            echo "Error loading rooms from database: " . $e->getMessage() . "\n";
        }
    }
}
