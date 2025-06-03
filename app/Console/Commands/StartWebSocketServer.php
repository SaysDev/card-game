<?php

namespace App\Console\Commands;

use App\Servers\WebSocketServer;
use Illuminate\Console\Command;

class StartWebSocketServer extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'websocket:start {--host=0.0.0.0} {--port=9502}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Start the OpenSwoole WebSocket server for the card game';

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

        $host = $this->option('host');
        $port = (int) $this->option('port');

        $this->info("Server will listen on {$host}:{$port}");

        try {
            $server = new WebSocketServer($host, $port);
            $server->start();
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->error("Server failed to start: {$e->getMessage()}");
            $this->error($e->getTraceAsString());
            return Command::FAILURE;
        }
    }
}
