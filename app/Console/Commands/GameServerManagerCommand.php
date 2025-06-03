<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;
use App\Game\GameServer;
use App\Game\LobbyServer;
use App\Services\GameLogger;
use App\Game\GameServerManager;
use Exception;
use App\Game\Core\Logger;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

class GameServerManagerCommand extends Command
{
    protected $signature = 'game:manage {action=start : The action to perform (start|stop|restart|status)}';
    protected $description = 'Zarządzanie serwerami gry (Lobby i serwery gry)';

    private GameServerManager $manager;
    private Logger $logger;

    public function __construct(GameServerManager $manager)
    {
        parent::__construct();
        $this->manager = $manager;
        $this->logger = new Logger(storage_path('logs'), 'cmd');
        
        // Register signal handlers for graceful shutdown
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGINT, [$this, 'handleSignal']);
            pcntl_signal(SIGTERM, [$this, 'handleSignal']);
        }
    }

    public function handle(): int
    {
        try {
            $action = $this->argument('action');
            
            switch ($action) {
                case 'start':
                    return $this->startServers();
                    
                case 'stop':
                    $this->info('Zatrzymywanie wszystkich serwerów...');
                    $this->manager->stopAllServers();
                    $this->info('Wszystkie serwery zostały zatrzymane.');
                    break;
                    
                case 'restart':
                    $this->info('Ponowne uruchamianie wszystkich serwerów...');
                    $this->manager->stopAllServers();
                    sleep(2); // Give servers time to fully stop
                    return $this->startServers();
                    
                case 'status':
                    return $this->handleStatus();
                    
                default:
                    $this->error("Nieznana akcja: {$action}");
                    $this->showHelp();
                    return 1;
            }
            
            return 0;
        } catch (Exception $e) {
            $this->error("Błąd: " . $e->getMessage());
            $this->logger->error("Exception: " . $e->getMessage());
            $this->logger->error("Stack trace: " . $e->getTraceAsString());
            return 1;
        }
    }

    public function handleSignal(int $signal, $previousExitCode = 0): int|false
    {
        if ($signal === SIGINT || $signal === SIGTERM) {
            $this->info("\nReceived signal $signal, stopping all servers...");
            
            // Read PIDs from file
            $pidsFile = storage_path('logs/game_manager_pids.json');
            if (file_exists($pidsFile)) {
                $pidsData = json_decode(file_get_contents($pidsFile), true);
                
                // Stop lobby server
                if (isset($pidsData['lobby'])) {
                    $lobbyPid = $pidsData['lobby'];
                    $this->info("Stopping Lobby Server (PID: {$lobbyPid})...");
                    posix_kill($lobbyPid, SIGTERM);
                }
                
                // Stop game servers
                if (isset($pidsData['game_servers']) && is_array($pidsData['game_servers'])) {
                    foreach ($pidsData['game_servers'] as $gamePid) {
                        $this->info("Stopping Game Server (PID: {$gamePid})...");
                        posix_kill($gamePid, SIGTERM);
                    }
                }
                
                // Delete the PIDs file
                unlink($pidsFile);
            }
            
            // Also kill any processes on our ports as a fallback
            $this->killProcessesOnPorts([5555, 5556, 5557, 5558, 5559]);
            
            // Clean up PID files
            $pidFiles = [
                storage_path('logs/lobby_server.pid'),
                storage_path('logs/lobby.pid')
            ];
            
            for ($i = 1; $i <= 3; $i++) {
                $pidFiles[] = storage_path("logs/game_server_{$i}.pid");
                $pidFiles[] = storage_path("logs/game_{$i}.pid");
            }
            
            foreach ($pidFiles as $pidFile) {
                if (file_exists($pidFile)) {
                    unlink($pidFile);
                }
            }
            
            // Clean up ready files
            $readyFiles = [
                storage_path('logs/lobby_server.ready'),
                storage_path('logs/lobby.ready')
            ];
            
            for ($i = 1; $i <= 3; $i++) {
                $readyFiles[] = storage_path("logs/game_server_{$i}.ready");
                $readyFiles[] = storage_path("logs/game_{$i}.ready");
            }
            
            foreach ($readyFiles as $readyFile) {
                if (file_exists($readyFile)) {
                    unlink($readyFile);
                }
            }
            
            $this->info("All servers stopped.");
            exit(0); // Force exit after cleanup
        }
        
        return $previousExitCode;
    }

    private function startServers(): int
    {
        $this->info('Uruchamianie wszystkich serwerów...');
        
        // First kill any processes that might be using our ports
        $this->killProcessesOnPorts([5555, 5556, 5557, 5558, 5559]);
        
        // Verify all ports are free
        if (!$this->verifyPortsAreFree([5555, 5556, 5557, 5558, 5559])) {
            $this->error("Cannot start servers because ports are still in use after cleanup");
            return Command::FAILURE;
        }
        
        // Start Lobby Server in a separate process with nohup
        $this->info('Uruchamianie serwera lobby...');
        $lobbyCommand = sprintf(
            'nohup php %s/artisan game:start-lobby > /dev/null 2>&1 & echo $!',
            base_path()
        );
        
        $lobbyPid = exec($lobbyCommand);
        if (!$lobbyPid) {
            $this->error("Nie udało się uruchomić serwera lobby");
            return Command::FAILURE;
        }
        
        $this->info("Serwer lobby uruchomiony (PID: {$lobbyPid})");
        
        // Check if the ready file exists
        $lobbyReadyFile = storage_path('logs/lobby_server.ready');
        $startTime = time();
        $timeout = 15;
        
        $this->info('Oczekiwanie na gotowość serwera lobby...');
        while (!file_exists($lobbyReadyFile) && (time() - $startTime < $timeout)) {
            usleep(500000); // Sleep for half a second
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
        }
        
        if (!file_exists($lobbyReadyFile)) {
            $this->error("Nie udało się uruchomić serwera lobby w czasie {$timeout} sekund");
            return Command::FAILURE;
        }
        
        $this->info('Serwer lobby uruchomiony pomyślnie.');
        
        // Verify game server ports are free
        if (!$this->verifyPortsAreFree([5557, 5558, 5559])) {
            $this->error("Cannot start game servers because ports are still in use");
            return Command::FAILURE;
        }
        
        // Start Game Servers
        $this->info('Uruchamianie serwerów gry...');
        
        $basePort = 5557;
        $gameServerCount = 3;
        $gamePids = [];
        
        for ($i = 1; $i <= $gameServerCount; $i++) {
            $port = $basePort + ($i - 1);
            $this->info("Uruchamianie serwera gry #{$i} na porcie {$port}...");
            
            $gameCommand = sprintf(
                'nohup php %s/artisan game:start-server %d > /dev/null 2>&1 & echo $!',
                base_path(),
                $i
            );
            
            $gamePid = exec($gameCommand);
            if (!$gamePid) {
                $this->error("Nie udało się uruchomić serwera gry #{$i}");
                continue;
            }
            
            $gamePids[] = $gamePid;
            $this->info("Serwer gry #{$i} uruchomiony (PID: {$gamePid})");
            
            // Check if the ready file exists
            $gameReadyFile = storage_path("logs/game_server_{$i}.ready");
            $startTime = time();
            $timeout = 15;
            
            $this->info("Oczekiwanie na gotowość serwera gry #{$i}...");
            while (!file_exists($gameReadyFile) && (time() - $startTime < $timeout)) {
                usleep(500000); // Sleep for half a second
                if (function_exists('pcntl_signal_dispatch')) {
                    pcntl_signal_dispatch();
                }
            }
            
            if (!file_exists($gameReadyFile)) {
                $this->error("Serwer gry #{$i} nie jest gotowy w czasie {$timeout} sekund");
                continue;
            }
            
            $this->info("Serwer gry #{$i} gotowy.");
        }
        
        // Store PIDs in a file for management
        $pidsData = [
            'lobby' => $lobbyPid,
            'game_servers' => $gamePids,
            'started_at' => time()
        ];
        
        // Ensure directory exists
        $pidsDir = dirname(storage_path('logs/game_manager_pids.json'));
        if (!file_exists($pidsDir)) {
            mkdir($pidsDir, 0755, true);
        }
        
        // Write PIDs to file
        $result = file_put_contents(
            storage_path('logs/game_manager_pids.json'), 
            json_encode($pidsData, JSON_PRETTY_PRINT)
        );
        
        if ($result === false) {
            $this->error("Nie udało się zapisać PIDs do pliku");
            $this->logger->error("Failed to write PIDs file: " . storage_path('logs/game_manager_pids.json'));
        } else {
            $this->logger->info("PIDs saved to file: " . storage_path('logs/game_manager_pids.json'));
            $this->logger->info("PIDs data: " . json_encode($pidsData));
        }
        
        $this->info('Wszystkie serwery uruchomione pomyślnie.');
        
        // Keep the main process running and handle signals
        while (true) {
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
            usleep(100000); // Sleep for 100ms
        }
        
        return Command::SUCCESS;
    }

    private function handleStatus(): int
    {
        $this->info('Status serwerów:');
        
        // Check Lobby Server status
        $lobbyStatus = $this->isLobbyServerRunning() ? 'Uruchomiony' : 'Zatrzymany';
        $this->info("Serwer Lobby: {$lobbyStatus}");
        
        // Check Game Servers status
        for ($i = 1; $i <= 3; $i++) {
            $gameStatus = $this->isGameServerRunning($i) ? 'Uruchomiony' : 'Zatrzymany';
            $this->info("Serwer Gry #{$i}: {$gameStatus}");
        }
        
        return Command::SUCCESS;
    }
    
    private function isLobbyServerRunning(): bool
    {
        // Check PIDs file first
        $pidsFile = storage_path('logs/game_manager_pids.json');
        if (file_exists($pidsFile)) {
            $pidsData = json_decode(file_get_contents($pidsFile), true);
            if (isset($pidsData['lobby'])) {
                $pid = (int)$pidsData['lobby'];
                if ($pid > 0) {
                    try {
                        $result = posix_kill($pid, 0);
                        if ($result) {
                            return true;
                        }
                    } catch (\Exception $e) {
                        // Process doesn't exist
                    }
                }
            }
        }
        
        // Check PID files as fallback
        $pidFiles = [
            storage_path('logs/lobby_server.pid'),
            storage_path('logs/lobby.pid')
        ];
        
        foreach ($pidFiles as $pidFile) {
            if (file_exists($pidFile)) {
                $pid = (int)file_get_contents($pidFile);
                if ($pid > 0) {
                    try {
                        $result = posix_kill($pid, 0);
                        if ($result) {
                            return true;
                        }
                    } catch (\Exception $e) {
                        // Process doesn't exist
                    }
                }
            }
        }
        
        // Check for processes on the lobby ports
        exec("lsof -ti:5555", $output);
        if (!empty($output)) {
            return true;
        }
        
        exec("lsof -ti:5556", $output);
        if (!empty($output)) {
            return true;
        }
        
        return false;
    }
    
    private function isGameServerRunning(int $serverId): bool
    {
        // Check PIDs file first
        $pidsFile = storage_path('logs/game_manager_pids.json');
        if (file_exists($pidsFile)) {
            $pidsData = json_decode(file_get_contents($pidsFile), true);
            if (isset($pidsData['game_servers']) && isset($pidsData['game_servers'][$serverId - 1])) {
                $pid = (int)$pidsData['game_servers'][$serverId - 1];
                if ($pid > 0) {
                    try {
                        $result = posix_kill($pid, 0);
                        if ($result) {
                            return true;
                        }
                    } catch (\Exception $e) {
                        // Process doesn't exist
                    }
                }
            }
        }
        
        // Check PID files as fallback
        $pidFiles = [
            storage_path("logs/game_server_{$serverId}.pid"),
            storage_path("logs/game_{$serverId}.pid")
        ];
        
        foreach ($pidFiles as $pidFile) {
            if (file_exists($pidFile)) {
                $pid = (int)file_get_contents($pidFile);
                if ($pid > 0) {
                    try {
                        $result = posix_kill($pid, 0);
                        if ($result) {
                            return true;
                        }
                    } catch (\Exception $e) {
                        // Process doesn't exist
                    }
                }
            }
        }
        
        // Check for processes on the game server port
        $port = 5556 + $serverId;
        exec("lsof -ti:{$port}", $output);
        if (!empty($output)) {
            return true;
        }
        
        return false;
    }

    private function showHelp(): void
    {
        $this->line('Dostępne akcje:');
        $this->line('  start   - Uruchom wszystkie serwery');
        $this->line('  stop    - Zatrzymaj wszystkie serwery');
        $this->line('  restart - Ponownie uruchom wszystkie serwery');
        $this->line('  status  - Pokaż status serwerów');
    }

    private function killProcessesOnPorts(array $ports): void
    {
        foreach ($ports as $port) {
            $this->line("Sprawdzanie portu {$port}...");
            $cmd = "lsof -ti:{$port}";
            exec($cmd, $output);
            
            if (!empty($output)) {
                $this->logger->debug("Found processes on port {$port}: " . implode(', ', $output));
                foreach ($output as $pid) {
                    if (is_numeric($pid)) {
                        $this->logger->debug("Killing process {$pid} on port {$port}");
                        posix_kill((int)$pid, SIGKILL);
                    }
                }
                
                // Wait and verify the port is actually free now
                sleep(1);
                $checkCmd = "lsof -ti:{$port}";
                $checkOutput = [];
                exec($checkCmd, $checkOutput);
                
                if (!empty($checkOutput)) {
                    $this->logger->warning("Port {$port} is still in use after SIGKILL, retrying with SIGTERM...");
                    foreach ($checkOutput as $pid) {
                        if (is_numeric($pid)) {
                            posix_kill((int)$pid, SIGTERM);
                        }
                    }
                    
                    // One final check and force kill if needed
                    sleep(1);
                    $finalCheck = [];
                    exec($checkCmd, $finalCheck);
                    
                    if (!empty($finalCheck)) {
                        $this->logger->warning("Port {$port} is still in use after SIGTERM, trying SIGKILL one more time");
                        foreach ($finalCheck as $pid) {
                            if (is_numeric($pid)) {
                                posix_kill((int)$pid, SIGKILL);
                            }
                        }
                    }
                }
            } else {
                $this->logger->debug("No processes found on port {$port}");
            }
        }
    }

    private function verifyPortsAreFree(array $ports): bool
    {
        $allFree = true;
        foreach ($ports as $port) {
            $connection = @fsockopen('127.0.0.1', $port, $errno, $errstr, 1);
            if (is_resource($connection)) {
                fclose($connection);
                $this->logger->error("Port {$port} is still in use");
                $this->error("Port {$port} is still in use, cannot start servers");
                $allFree = false;
            }
        }
        
        if (!$allFree) {
            // One more attempt to clean up ports
            $this->logger->warning("Some ports are still in use, attempting to force clean...");
            $this->killProcessesOnPorts($ports);
            sleep(2);
            
            // Check again
            $allFree = true;
            foreach ($ports as $port) {
                $connection = @fsockopen('127.0.0.1', $port, $errno, $errstr, 1);
                if (is_resource($connection)) {
                    fclose($connection);
                    $this->logger->error("Port {$port} is still in use after forced cleanup");
                    $this->error("Port {$port} is still in use, cannot start servers");
                    $allFree = false;
                }
            }
        }
        
        return $allFree;
    }
} 