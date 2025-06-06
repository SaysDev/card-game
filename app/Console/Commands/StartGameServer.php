<?php

namespace App\Console\Commands;

use App\ServersReworked\GameServer;
use App\ServersReworked\Utilities\Logger;
use Illuminate\Console\Command;

class StartGameServer extends Command
{
    protected $signature = 'game:start 
                          {--port=9503 : Port for the game server}
                          {--host=0.0.0.0 : Host for the game server}
                          {--capacity=1000 : Maximum number of players}';

    protected $description = 'Start the game server';

    public function __construct()
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $port = (int) $this->option('port');
        $host = $this->option('host');
        $capacity = (int) $this->option('capacity');

        $this->info("Starting game server on {$host}:{$port} with capacity {$capacity}");

        // Create logger instance
        $logger = new Logger('game_server');
        Logger::showPid(true);

        // Create and start the game server
        $server = new GameServer($host, $port, $logger, '', $capacity);
        
        try {
            $server->start();
        } catch (\Exception $e) {
            $this->error("Failed to start game server: " . $e->getMessage());
            return 1;
        }

        return 0;
    }
}
