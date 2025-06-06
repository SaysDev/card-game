<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Servers\WebSocketServer;
use App\Servers\Utilities\Logger;

class StartWebSocketServer extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'websocket:start {--port=9502} {--host=0.0.0.0} {--server-id=websocket-server-1}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Start the WebSocket server';

    /**
     * Create a new command instance.
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Starting WebSocket Server...');

        // Check if OpenSwoole extension is loaded
        if (!extension_loaded('openswoole')) {
            $this->error('The OpenSwoole extension is not installed or not loaded!');
            $this->error('Please install the extension using: pecl install openswoole');
            $this->error('And ensure it\'s enabled in your php.ini file.');
            return Command::FAILURE;
        }

        $port = $this->option('port');
        $host = $this->option('host');
        $serverId = $this->option('server-id');

        $this->info("Server will listen on {$host}:{$port}");

        try {
            $logger = new \App\Servers\Utilities\Logger($serverId);
            $server = new \App\Servers\WebSocketServer($host, $port, $logger);
            $server->start();
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->error("Server failed to start: {$e->getMessage()}");
            $this->error($e->getTraceAsString());
            return Command::FAILURE;
        }
    }
}
