
<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use App\Console\Commands\StartWebSocketServer;

/*
|--------------------------------------------------------------------------
| Console Routes
|--------------------------------------------------------------------------
|
| This file is where you may define all of your Closure based console
| commands. Each Closure is bound to a command instance allowing a
| simple approach to interacting with each command's IO methods.
|
*/

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// The websocket:start command is registered via the StartWebSocketServer class
// You can start the WebSocket server with: php artisan websocket:start
Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('websocket:start-inline {host=127.0.0.1} {port=9502}', function () {
    $host = $this->argument('host');
    $port = (int) $this->argument('port');

    $this->info("Starting WebSocket server on {$host}:{$port}");
    $this->info("Press Ctrl+C to stop the server");

    $server = new WebSocketServer($host, $port);
    $server->start();
})->purpose('Start the WebSocket server for the card game (inline version)');
