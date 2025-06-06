<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Servers\GameServer;
use App\Servers\Utilities\Logger;

class StartGameServer extends Command
{
    protected $signature = 'game:start 
        {--ws-url=ws://localhost:9502} 
        {--server-id=game-server-1} 
        {--capacity=100}';
    
    protected $description = 'Start a game server instance';

    private Logger $logger;

    public function __construct()
    {
        parent::__construct();
    }


    public function handle()
    {
        $wsUrl = $this->option('ws-url');
        $serverId = $this->option('server-id');
        $capacity = (int) $this->option('capacity');
        
        // Initialize logger here where options are available
        $this->logger = new Logger($serverId);
        $this->info("Starting game server {$serverId} with capacity {$capacity}");
        $this->info("Connecting to WebSocket server at {$wsUrl}");

        $this->logger->info("Starting game server command", [
            'ws_url' => $wsUrl,
            'server_id' => $serverId,
            'capacity' => $capacity
        ]);

        try {
            $server = new GameServer($wsUrl, $serverId, $capacity);
            $server->start();
        } catch (\Exception $e) {
            $this->logger->error("Failed to start game server", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $this->error("Failed to start game server: " . $e->getMessage());
            return 1;
        }

        return 0;
    }
}
