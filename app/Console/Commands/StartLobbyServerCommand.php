<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Game\LobbyServer;
use App\Game\Core\Logger;
use Exception;
use Psr\Log\LogLevel;

class StartLobbyServerCommand extends Command
{
    protected $signature = 'game:start-lobby';
    protected $description = 'Start the Lobby Server';

    private Logger $logger;

    public function __construct()
    {
        parent::__construct();
        $this->logger = new Logger(storage_path('logs'), 'lobby');
        $this->logger->setLogLevel(LogLevel::DEBUG);
    }

    public function handle(): int
    {
        try {
            $this->info("Starting Lobby Server...");
            
            // Check if server is already running
            if (file_exists(storage_path('logs/lobby_server.pid'))) {
                $pid = file_get_contents(storage_path('logs/lobby_server.pid'));
                if (posix_kill($pid, 0)) {
                    $this->error("Lobby Server is already running (PID: {$pid})");
                    return Command::FAILURE;
                }
                // Clean up stale PID file
                unlink(storage_path('logs/lobby_server.pid'));
            }
            
            // Also check alternate PID file
            if (file_exists(storage_path('logs/lobby.pid'))) {
                $pid = file_get_contents(storage_path('logs/lobby.pid'));
                if (posix_kill($pid, 0)) {
                    $this->error("Lobby Server is already running (PID: {$pid})");
                    return Command::FAILURE;
                }
                // Clean up stale PID file
                unlink(storage_path('logs/lobby.pid'));
            }
            
            // Kill any processes on our ports
            $this->killProcessesOnPorts([5555, 5556]);
            
            // Clean up old ready files
            $readyFiles = [
                storage_path('logs/lobby_server.ready'),
                storage_path('logs/lobby.ready')
            ];
            
            foreach ($readyFiles as $file) {
                if (file_exists($file)) {
                    unlink($file);
                }
            }
            
            // Create PID file
            $pidFile = storage_path('logs/lobby_server.pid');
            file_put_contents($pidFile, getmypid());
            chmod($pidFile, 0666);
            
            // Create ready file
            $readyFile = storage_path('logs/lobby_server.ready');
            file_put_contents($readyFile, time());
            chmod($readyFile, 0666);
            
            $this->info("Starting LobbyServer...");
            
            // Disable signal handling in child process
            if (function_exists('pcntl_signal')) {
                // Remove parent's signal handlers to avoid conflicts
                pcntl_signal(SIGINT, SIG_DFL);
                pcntl_signal(SIGTERM, SIG_DFL);
            }
            
            // Run server in the current process (this will block)
            $server = new LobbyServer('127.0.0.1', 5555, $this->logger);
            $server->start();
            
            return Command::SUCCESS;
        } catch (Exception $e) {
            $this->error("Failed to start Lobby Server: " . $e->getMessage());
            $this->logger->error("Failed to start Lobby Server: " . $e->getMessage());
            $this->logger->error("Stack trace: " . $e->getTraceAsString());
            return Command::FAILURE;
        }
    }
    
    private function killProcessesOnPorts(array $ports): void
    {
        foreach ($ports as $port) {
            $cmd = "lsof -ti:{$port}";
            exec($cmd, $output);
            
            foreach ($output as $pid) {
                if (is_numeric($pid)) {
                    $this->line("Killing process {$pid} on port {$port}");
                    posix_kill((int)$pid, SIGKILL);
                }
            }
        }
    }
} 