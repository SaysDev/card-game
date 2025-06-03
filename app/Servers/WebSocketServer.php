<?php

namespace App\Servers;

use App\Servers\Handlers\AuthenticationHandler;
use App\Servers\Handlers\GameHandler;
use App\Servers\Handlers\RoomHandler;
use App\Servers\Storage\MemoryStorage;
use OpenSwoole\Http\Request;
use OpenSwoole\Http\Response;
use OpenSwoole\WebSocket\Frame;
use OpenSwoole\WebSocket\Server;

class WebSocketServer
{
    private Server $server;
    private MemoryStorage $storage;
    private AuthenticationHandler $authHandler;
    private RoomHandler $roomHandler;
    private GameHandler $gameHandler;

    public function __construct(string $host = '0.0.0.0', int $port = 9502)
    {
        // Initialize server
        $this->server = new Server($host, $port);

        // Configure server settings
        $this->server->set([
            'worker_num' => 4,            // Number of worker processes
            'task_worker_num' => 2,       // Number of task worker processes
            'max_request' => 1000,        // Max number of requests before worker restarts
            'heartbeat_check_interval' => 60,  // Check for dead connections
            'heartbeat_idle_time' => 120,      // Connection idle time before closing
        ]);

        // Initialize storage
        $this->storage = new MemoryStorage();

        // Initialize handlers
        $this->authHandler = new AuthenticationHandler($this->storage);
        $this->roomHandler = new RoomHandler($this->storage);
        $this->gameHandler = new GameHandler($this->storage);

        // Register event callbacks
        $this->registerEventHandlers();
    }

    private function registerEventHandlers(): void
    {
        // Handle WebSocket open connection
        $this->server->on('open', function (Server $server, Request $request) {
            $fd = $request->fd;
            echo "Connection opened: {$fd}\n";

            // Initialize connection
            $this->storage->setConnection($fd, [
                'fd' => $fd,
                'user_id' => 0,
                'connected_at' => time()
            ]);

            // Send welcome message
            $server->push($fd, json_encode([
                'type' => 'connection',
                'status' => 'success',
                'message' => 'Connected to Card Game WebSocket server'
            ]));
        });

        // Handle WebSocket messages
        $this->server->on('message', function (Server $server, Frame $frame) {
            $fd = $frame->fd;
            $data = json_decode($frame->data, true);

            if (!$data || !isset($data['action'])) {
                $server->push($fd, json_encode([
                    'type' => 'error',
                    'message' => 'Invalid message format'
                ]));
                return;
            }
            $action = $data['action'];
            $room = $data['room_id'] ?? '';

            // Debug log with readable format
            $debugData = [];
            foreach ($data as $key => $value) {
                if (is_scalar($value)) {
                    $debugData[] = "{$key}: {$value}";
                } else {
                    $debugData[] = "{$key}: " . json_encode($value);
                }
            }
            $debug = implode(', ', $debugData);
            echo "[{$data['action']}]Received message: {$debug} \n";
            // Route messages to appropriate handlers
            switch ($data['action']) {

                // Authentication actions
                case 'authenticate':
                    $this->authHandler->handleAuthentication($server, $fd, $data);
                    break;

                // Room management actions
                case 'create_room':
                    $this->roomHandler->handleCreateRoom($server, $fd, $data);
                    break;

                case 'join_room':
                    $this->roomHandler->handleJoinRoom($server, $fd, $data);
                    break;

                case 'leave_room':
                    $this->roomHandler->handleLeaveRoom($server, $fd);
                    break;

                case 'list_rooms':
                    $this->roomHandler->handleListRooms($server, $fd);
                    break;

                // Player ready status
                case 'set_ready_status':
                    if (method_exists($this->roomHandler, 'handleSetReadyStatus')) {
                        $this->roomHandler->handleSetReadyStatus($server, $fd, $data);
                    } else {
                        // Fallback if method doesn't exist yet
                        $server->push($fd, json_encode([
                            'type' => 'error',
                            'message' => 'Set ready status handler not implemented'
                        ]));
                    }
                    break;

                // Game actions
                case 'game_action':
                    $this->gameHandler->handleGameAction($server, $fd, $data);
                    break;

                default:
                    $server->push($fd, json_encode([
                        'type' => 'error',
                        'message' => 'Unknown action: ' . $data['action']
                    ]));
            }
        });

        // Handle WebSocket close
        $this->server->on('close', function (Server $server, int $fd) {
            echo "Connection closed: {$fd}\n";

            // Clean up player data
            $player = $this->storage->getPlayer($fd);
            if ($player) {
                $userId = $player['user_id'];
                $roomId = $player['room_id'];

                echo "Closing connection for user ID: {$userId}, room ID: {$roomId}\n";

                // If player was in a room, update room data
                if ($roomId && $this->storage->roomExists($roomId)) {
                    // Check if this user has any other active connections in this room
                    $otherConnectionsInRoom = false;

                    $connections = $this->storage->getConnectionsByUserId($userId);
                    foreach ($connections as $connectionFd => $connectionData) {
                        if ($connectionFd !== $fd && $connectionData['room_id'] === $roomId) {
                            $otherConnectionsInRoom = true;
                            break;
                        }
                    }

                    // Only remove from room if no other connections exist
                    if (!$otherConnectionsInRoom) {
                        echo "No other connections for user {$userId} in room {$roomId}, removing from room\n";
                        $this->roomHandler->handleLeaveRoom($server, $fd);
                    } else {
                        echo "User {$userId} has other active connections in room {$roomId}, not removing from room\n";
                    }
                }

                // Remove player data
                $this->storage->removePlayer($fd);
            }

            // Remove connection record
            $this->storage->removeConnection($fd);
        });

        // Handle HTTP requests
        $this->server->on('request', function (Request $request, Response $response) {
            $response->header('Content-Type', 'application/json');
            $response->end(json_encode([
                'status' => 'error',
                'message' => 'This is a WebSocket server, please connect using WebSocket protocol'
            ]));
        });

        // Handle tasks
        $this->server->on('task', function (Server $server, int $taskId, int $workerId, $data) {
            // Process background tasks
            if (isset($data['type'])) {
                switch ($data['type']) {
                    case 'room_cleanup':
                        $this->storage->cleanupInactiveRooms();
                        break;

                    case 'game_update':
                        $this->gameHandler->processGameUpdate($server, $data['room_id'] ?? '');
                        break;

                    case 'start_new_game':
                        if (isset($data['room_id']) && $data['delay'] > 0) {
                            sleep($data['delay']);
                            $this->gameHandler->startNewGameInRoom($server, $data['room_id']);
                        }
                        break;
                }
            }

            return true;
        });

        // Handle task completion
        $this->server->on('finish', function (Server $server, int $taskId, string $data) {
            echo "Task finished: {$taskId}\n";
        });
    }

    public function start(): void
    {
        echo "Starting WebSocket server on {$this->server->host}:{$this->server->port}\n";

        // Check if Xdebug is enabled and warn the user
        if (extension_loaded('xdebug')) {
            echo "\033[33mWARNING: Xdebug is enabled. This may cause instability with OpenSwoole.\033[0m\n";
            echo "Consider disabling Xdebug when running the WebSocket server.\n";
            echo "You can temporarily disable Xdebug with: php -n (to disable PHP extensions)\n";
            echo "or by setting xdebug.mode=off in your php.ini\n";
            echo "Attempting to disable Xdebug for this session...\n";

            // Try to disable Xdebug functions that cause issues with coroutines
            if (function_exists('xdebug_disable')) {
                xdebug_disable();
                echo "Xdebug disabled for this session.\n";
            }
        }

        // Start the server
        $this->server->start();
    }
}
