<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Game\GameServer;
use App\Game\Core\Logger;
use Psr\Log\LogLevel;

class StartGameServerCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'game:start-server {server_id} {--host=127.0.0.1} {--port=5557}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Start a Game Server instance';

    private Logger $logger;

    public function __construct()
    {
        parent::__construct();
        $this->logger = new Logger(storage_path('logs'), 'game');
        $this->logger->setLogLevel(LogLevel::DEBUG);
    }

    public function handle(): int
    {
        try {
            $serverId = $this->argument('server_id');
            $host = $this->option('host') ?? '127.0.0.1';
            $basePort = $this->option('port') ?? 5557;
            
            $port = $basePort + ($serverId - 1);
            
            $this->info("Starting Game Server #{$serverId} on {$host}:{$port}...");
            
            // Check if server is already running
            $pidFile = storage_path("logs/game_server_{$serverId}.pid");
            if (file_exists($pidFile)) {
                $pid = file_get_contents($pidFile);
                if (posix_kill((int)$pid, 0)) {
                    $this->error("Game Server #{$serverId} is already running (PID: {$pid})");
                    return Command::FAILURE;
                }
                // Clean up stale PID file
                unlink($pidFile);
            }
            
            // Also check alternate PID file
            $altPidFile = storage_path("logs/game_{$serverId}.pid");
            if (file_exists($altPidFile)) {
                $pid = file_get_contents($altPidFile);
                if (posix_kill((int)$pid, 0)) {
                    $this->error("Game Server #{$serverId} is already running (PID: {$pid})");
                    return Command::FAILURE;
                }
                // Clean up stale PID file
                unlink($altPidFile);
            }
            
            // Kill any processes on our port
            $this->killProcessOnPort($port);
            
            // Clean up old ready files
            $readyFiles = [
                storage_path("logs/game_server_{$serverId}.ready"),
                storage_path("logs/game_{$serverId}.ready")
            ];
            
            foreach ($readyFiles as $file) {
                if (file_exists($file)) {
                    unlink($file);
                }
            }
            
            // Create PID file
            file_put_contents($pidFile, getmypid());
            chmod($pidFile, 0666);
            
            // Create ready file
            $readyFile = storage_path("logs/game_server_{$serverId}.ready");
            file_put_contents($readyFile, time());
            chmod($readyFile, 0666);
            
            $this->info("Starting Game Server process...");
            
            // Disable signal handling in child process
            if (function_exists('pcntl_signal')) {
                // Remove parent's signal handlers to avoid conflicts
                pcntl_signal(SIGINT, SIG_DFL);
                pcntl_signal(SIGTERM, SIG_DFL);
            }
            
            // Run server in the current process (this will block)
            $server = new GameServer($serverId, $host, $port);
            $server->start();
            
            return Command::SUCCESS;
        } catch (Exception $e) {
            $this->error("Failed to start Game Server: " . $e->getMessage());
            $this->logger->error("Failed to start Game Server: " . $e->getMessage());
            $this->logger->error("Stack trace: " . $e->getTraceAsString());
            return Command::FAILURE;
        }
    }

    private function isPortInUse(int $port): bool
    {
        $connection = @fsockopen('127.0.0.1', $port);
        if (is_resource($connection)) {
            fclose($connection);
            return true;
        }
        return false;
    }

    private function killProcessOnPort(int $port): void
    {
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