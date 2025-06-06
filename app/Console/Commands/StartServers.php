<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\ServersReworked\WebSocketServer;
use App\ServersReworked\GameServer;
use App\ServersReworked\Utilities\Logger;

class StartServers extends Command
{
    protected $signature = 'servers:start 
        {--ws-port=9502 : Port for WebSocket server}
        {--game-port=9503 : Port for Game server}
        {--ws-workers=4 : Number of WebSocket server workers}
        {--game-workers=2 : Number of Game server workers}
        {--game-capacity=1000 : Capacity for each game server}';

    protected $description = 'Start both WebSocket and Game servers';

    public function __construct()
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('Starting servers...');

        // Check if OpenSwoole extension is loaded
        if (!extension_loaded('openswoole')) {
            $this->error('The OpenSwoole extension is not installed or not loaded!');
            $this->error('Please install the extension using: pecl install openswoole');
            $this->error('And ensure it\'s enabled in your php.ini file.');
            return Command::FAILURE;
        }

        $wsPort = (int) $this->option('ws-port');
        $gamePort = (int) $this->option('game-port');
        $wsWorkers = (int) $this->option('ws-workers');
        $gameWorkers = (int) $this->option('game-workers');
        $gameCapacity = (int) $this->option('game-capacity');

        $this->info("WebSocket server will listen on 0.0.0.0:{$wsPort}");
        $this->info("Game server will listen on 0.0.0.0:{$gamePort}");
        $this->info("WebSocket workers: {$wsWorkers}");
        $this->info("Game workers: {$gameWorkers}");
        $this->info("Game server capacity: {$gameCapacity}");

        try {
            // Start WebSocket server
            $wsLogger = new Logger('websocket-server');
            $wsServer = new WebSocketServer('0.0.0.0', $wsPort, $wsLogger);
            $wsServer->start();

            // Start Game server
            $gameLogger = new Logger('game-server');
            $gameServer = new GameServer('0.0.0.0', $gamePort, $gameLogger, 'game-server-1', $gameCapacity);
            $gameServer->start();

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->error("Server failed to start: {$e->getMessage()}");
            $this->error($e->getTraceAsString());
            return Command::FAILURE;
        }
    }
} 