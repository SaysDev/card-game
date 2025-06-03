<?php

namespace App\Game;

use App\Game\Core\Logger;
use Psr\Log\LogLevel;
use Exception;
use Symfony\Component\Process\Process;

class GameServerManager
{
    private Logger $logger;
    private ?LobbyServer $lobbyServer = null;
    private array $gameServers = [];
    private array $childPids = [];
    private bool $isShuttingDown = false;
    private int $mainPid;
    private int $lobbyPid;
    private int $lobbyPort = 5555;  // Lobby Server WebSocket port
    private int $lobbyTcpPort = 5556;  // Lobby Server TCP port
    private array $gameServerPids = [];
    private array $gamePorts = [5557, 5558, 5559];  // Game server ports

    public function __construct()
    {
        $this->logger = new Logger(storage_path('logs'), 'manager');
        $this->logger->setLogLevel(LogLevel::DEBUG);
        $this->mainPid = getmypid();
        
        // Register signal handlers
        if (extension_loaded('pcntl')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGINT, [$this, 'handleSignal']);
            pcntl_signal(SIGTERM, [$this, 'handleSignal']);
            pcntl_signal(SIGHUP, [$this, 'handleSignal']);
        }
        
        // Register shutdown function
        register_shutdown_function([$this, 'shutdown']);
    }

    public function handleSignal(int $signal): void
    {
        // Only allow the main manager process to handle signals
        if (!$this->isMainManager()) {
            return;
        }

        $this->logger->info("Received signal {$signal}, shutting down...");
        $this->shutdown();
        exit(0);
    }

    public function shutdown(): void
    {
        if ($this->isShuttingDown) {
            return;
        }
        
        $this->isShuttingDown = true;
        $this->logger->info("Stopping all Game Servers");
        
        // Stop all servers gracefully
        $this->stopAllServers();
        
        // Only kill processes if they're still running after graceful shutdown
        foreach ($this->childPids as $pid) {
            if (posix_kill($pid, 0)) {
                $this->logger->debug("Zatrzymywanie pozostałego procesu potomnego {$pid}");
                posix_kill($pid, SIGKILL);
            }
        }
        
        $this->logger->info("Wszystkie serwery zatrzymane");
    }

    private function isPortAvailable(int $port): bool
    {
        // First try to kill any process using this port
        $this->killProcessOnPort($port);
        
        // Wait a moment for the port to be released
        usleep(500000); // 500ms
        
        // Now check if port is available
        $socket = @socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($socket === false) {
            return false;
        }
        
        $result = @socket_bind($socket, '127.0.0.1', $port);
        socket_close($socket);
        
        return $result !== false;
    }

    private function killProcessOnPort(int $port): void
    {
        $command = "lsof -ti:{$port} | xargs kill -9 2>/dev/null || true";
        exec($command);
    }

    public function isLobbyServerRunning(): bool
    {
        // First try to use the PIDs file
        $pidsFile = storage_path('logs/game_manager_pids.json');
        if (file_exists($pidsFile)) {
            $pidsData = json_decode(file_get_contents($pidsFile), true);
            
            if (isset($pidsData['lobby'])) {
                $lobbyPid = $pidsData['lobby'];
                if (posix_kill($lobbyPid, 0)) {
                    return true;
                }
            }
        }
        
        // Fall back to checking PID files
        $pidFiles = [
            storage_path('logs/lobby_server.pid'),
            storage_path('logs/lobby.pid')
        ];
        
        foreach ($pidFiles as $pidFile) {
            if (file_exists($pidFile)) {
                $pid = (int)file_get_contents($pidFile);
                if ($pid && posix_kill($pid, 0)) {
                    return true;
                }
            }
        }
        
        // As a last resort, check if any process is listening on the lobby port
        return $this->isPortInUse(5555) || $this->isPortInUse(5556);
    }

    public function isGameServerRunning(int $serverId): bool
    {
        // First try to use the PIDs file
        $pidsFile = storage_path('logs/game_manager_pids.json');
        if (file_exists($pidsFile)) {
            $pidsData = json_decode(file_get_contents($pidsFile), true);
            
            if (isset($pidsData['game_servers']) && isset($pidsData['game_servers'][$serverId - 1])) {
                $gamePid = $pidsData['game_servers'][$serverId - 1];
                if (posix_kill($gamePid, 0)) {
                    return true;
                }
            }
        }
        
        // Fall back to checking PID files
        $pidFiles = [
            storage_path("logs/game_server_{$serverId}.pid"),
            storage_path("logs/game_{$serverId}.pid")
        ];
        
        foreach ($pidFiles as $pidFile) {
            if (file_exists($pidFile)) {
                $pid = (int)file_get_contents($pidFile);
                if ($pid && posix_kill($pid, 0)) {
                    return true;
                }
            }
        }
        
        // As a last resort, check if any process is listening on the game server port
        $port = 5556 + $serverId;
        return $this->isPortInUse($port);
    }

    private function waitForServerReady(string $type, int $serverId = null): bool
    {
        $this->logger->debug("Waiting for {$type} Server to be ready...");
        
        $startTime = time();
        $timeout = 30; // 30 seconds timeout
        $checkInterval = 1; // 1 second between checks
        
        while (time() - $startTime < $timeout) {
            // Check ready file
            $readyFile = $type === 'lobby' 
                ? storage_path('logs/lobby_server.ready')
                : storage_path("logs/game_server_{$serverId}.ready");
                
            if (!file_exists($readyFile)) {
                $this->logger->debug("{$type} Server ready file not found");
                sleep($checkInterval);
                continue;
            }
            
            // Check server connections
            if ($type === 'lobby') {
                // Check WebSocket port (5555)
                if (!$this->isPortInUse(5555)) {
                    $this->logger->debug("Lobby Server WebSocket port not ready");
                    sleep($checkInterval);
                    continue;
                }
                
                // Check TCP port (5556)
                if (!$this->isPortInUse(5556)) {
                    $this->logger->debug("Lobby Server TCP port not ready");
                    sleep($checkInterval);
                    continue;
                }
                
                $this->logger->info("Lobby Server is ready");
                return true;
            } else {
                // Check Game Server port
                $port = $this->gamePorts[$serverId - 1];
                if (!$this->isPortInUse($port)) {
                    $this->logger->debug("Game Server #{$serverId} port not ready");
                    sleep($checkInterval);
                    continue;
                }
                
                $this->logger->info("Game Server #{$serverId} is ready");
                return true;
            }
        }
        
        $this->logger->error("{$type} Server failed to start within {$timeout} seconds");
        return false;
    }

    public function startAllServers(): bool
    {
        try {
            // Start Lobby Server first
            if (!$this->startLobbyServer()) {
                $this->logger->error("Nie udało się uruchomić serwera lobby");
                return false;
            }

            // Wait for Lobby Server to be ready
            if (!$this->waitForServerReady('lobby')) {
                $this->logger->error("Serwer lobby nie uruchomił się poprawnie");
                $this->stopAllServers();
                return false;
            }

            // Start Game Servers one by one
            for ($i = 1; $i <= 3; $i++) {
                $this->logger->info("Uruchamianie serwera gry #{$i}...");
                
                if (!$this->startGameServer($i)) {
                    $this->logger->error("Nie udało się uruchomić serwera gry #{$i}");
                    $this->stopAllServers();
                    return false;
                }
                
                // Wait for each Game Server to be ready
                if (!$this->waitForServerReady('game', $i)) {
                    $this->logger->error("Serwer gry #{$i} nie uruchomił się poprawnie");
                    $this->stopAllServers();
                    return false;
                }
                
                // Wait a moment between starting servers
                usleep(1000000); // 1 second
            }

            $this->logger->info("Wszystkie serwery uruchomione pomyślnie");
            return true;
        } catch (Exception $e) {
            $this->logger->error("Błąd podczas uruchamiania serwerów: " . $e->getMessage());
            $this->stopAllServers();
            return false;
        }
    }

    public function startLobbyServer(): bool
    {
        $this->logger->info("Uruchamianie serwera lobby...");
        
        // Najpierw zatrzymaj wszystkie procesy na wymaganych portach
        $this->killProcessesOnPorts([5555, 5556]);
        
        // Poczekaj chwilę, aby porty zostały zwolnione
        usleep(500000); // 500ms
        
        // Sprawdź, czy porty są wolne
        $ports = [5555, 5556];
        foreach ($ports as $port) {
            if ($this->isPortInUse($port)) {
                $this->logger->error("Port {$port} jest nadal zajęty");
                return false;
            }
        }

        try {
            // Clean up old ready file
            $readyFile = storage_path('logs/lobby_server.ready');
            if (file_exists($readyFile)) {
                unlink($readyFile);
            }
            
            // Clean up old PID file
            $pidFile = storage_path('logs/lobby_server.pid');
            if (file_exists($pidFile)) {
                unlink($pidFile);
            }
            
            // Start lobby server in background
            $command = sprintf(
                'php %s game:start-lobby > %s 2>&1 & echo $!',
                base_path('artisan'),
                storage_path('logs/lobby_server.log')
            );
            
            $pid = exec($command);
            
            if (!$pid) {
                $this->logger->error("Nie udało się uruchomić serwera lobby");
                return false;
            }
            
            $this->lobbyPid = (int)$pid;
            $this->logger->info("Serwer lobby uruchomiony (PID: {$this->lobbyPid})");
            
            // Save PID to file
            file_put_contents($pidFile, $this->lobbyPid);
            chmod($pidFile, 0666);
            
            return true;
        } catch (Exception $e) {
            $this->logger->error("Błąd podczas uruchamiania serwera lobby: " . $e->getMessage());
            return false;
        }
    }

    private function isProcessRunning(int $pid): bool
    {
        return file_exists("/proc/{$pid}");
    }

    private function startGameServer(int $serverId): bool
    {
        try {
            $port = $this->gamePorts[$serverId - 1];
            $this->logger->debug("Uruchamianie serwera gry #{$serverId} na 127.0.0.1:{$port}");

            // Create ready file
            $readyFile = storage_path("logs/game_server_{$serverId}.ready");
            if (file_exists($readyFile)) {
                unlink($readyFile);
            }

            // Start the game server process
            $process = new Process([
                'php',
                'artisan',
                'game:start-server',
                $serverId
            ]);
            
            $process->setTimeout(null);
            $process->start();
            
            if (!$process->isRunning()) {
                $this->logger->error("Nie udało się uruchomić serwera gry #{$serverId}");
                return false;
            }

            $this->gameServerPids[$serverId] = $process->getPid();
            $this->childPids[] = $process->getPid();
            
            $this->logger->info("Serwer gry #{$serverId} uruchomiony na 127.0.0.1:{$port}");
            return true;
        } catch (Exception $e) {
            $this->logger->error("Błąd podczas uruchamiania serwera gry #{$serverId}: " . $e->getMessage());
            return false;
        }
    }

    private function stopLobbyServer(): bool
    {
        try {
            if (!isset($this->lobbyPid)) {
                return true;
            }

            $pid = $this->lobbyPid;
            
            // Try graceful shutdown first
            if (posix_kill($pid, 0)) {
                $this->logger->info("Zatrzymywanie serwera lobby (PID: {$pid})");
                posix_kill($pid, SIGTERM);
                
                // Wait for graceful shutdown
                $startTime = time();
                while (time() - $startTime < 5) {
                    if (!posix_kill($pid, 0)) {
                        $this->logger->info("Serwer lobby zatrzymany pomyślnie");
                        return true;
                    }
                    usleep(100000); // 100ms
                }
                
                // Force kill if still running
                $this->logger->warning("Wymuszone zatrzymanie serwera lobby (PID: {$pid})");
                posix_kill($pid, SIGKILL);
            }
            
            unset($this->lobbyPid);
            return true;
        } catch (Exception $e) {
            $this->logger->error("Błąd podczas zatrzymywania serwera lobby: " . $e->getMessage());
            return false;
        }
    }

    private function stopGameServer(int $serverId): bool
    {
        try {
            if (!isset($this->gameServerPids[$serverId])) {
                return true;
            }

            $pid = $this->gameServerPids[$serverId];
            
            // Try graceful shutdown first
            if (posix_kill($pid, 0)) {
                $this->logger->info("Zatrzymywanie serwera gry #{$serverId} (PID: {$pid})");
                posix_kill($pid, SIGTERM);
                
                // Wait for graceful shutdown
                $startTime = time();
                while (time() - $startTime < 5) {
                    if (!posix_kill($pid, 0)) {
                        $this->logger->info("Serwer gry #{$serverId} zatrzymany pomyślnie");
                        return true;
                    }
                    usleep(100000); // 100ms
                }
                
                // Force kill if still running
                $this->logger->warning("Wymuszone zatrzymanie serwera gry #{$serverId} (PID: {$pid})");
                posix_kill($pid, SIGKILL);
            }
            
            unset($this->gameServerPids[$serverId]);
            return true;
        } catch (Exception $e) {
            $this->logger->error("Błąd podczas zatrzymywania serwera gry #{$serverId}: " . $e->getMessage());
            return false;
        }
    }

    public function stopAllServers(): bool
    {
        $this->logger->info("Stopping all servers...");
        
        // Try to read PIDs from file
        $pidsFile = storage_path('logs/game_manager_pids.json');
        if (file_exists($pidsFile)) {
            $pidsData = json_decode(file_get_contents($pidsFile), true);
            
            // Stop lobby server
            if (isset($pidsData['lobby'])) {
                $lobbyPid = $pidsData['lobby'];
                $this->logger->info("Stopping Lobby Server (PID: {$lobbyPid})...");
                posix_kill($lobbyPid, SIGTERM);
            }
            
            // Stop game servers
            if (isset($pidsData['game_servers']) && is_array($pidsData['game_servers'])) {
                foreach ($pidsData['game_servers'] as $gamePid) {
                    $this->logger->info("Stopping Game Server (PID: {$gamePid})...");
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
        
        $this->logger->info("All servers stopped");
        return true;
    }

    private function isServerRunning(int $serverId): bool
    {
        $pidFile = storage_path("logs/game_{$serverId}.pid");
        if (!file_exists($pidFile)) {
            return false;
        }
        
        $pid = file_get_contents($pidFile);
        return posix_kill($pid, 0);
    }

    private function isPortInUse(int $port): bool
    {
        $socket = @socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($socket === false) {
            return false;
        }
        
        $result = @socket_bind($socket, '127.0.0.1', $port);
        socket_close($socket);
        
        return $result === false;
    }

    public function getServerStatus(int $serverId): array
    {
        $pidFile = storage_path("logs/game_{$serverId}.pid");
        $isRunning = false;
        $pid = null;
        $port = $this->gamePorts[$serverId - 1] ?? null;
        
        // Check PID file
        if (file_exists($pidFile)) {
            $pid = (int)file_get_contents($pidFile);
            
            // Check if process exists
            if ($pid && posix_kill($pid, 0)) {
                // Verify it's actually our server by checking port
                if ($this->isPortInUse($port)) {
                    $isRunning = true;
                } else {
                    // Process exists but port is not in use - likely a stale PID
                    $this->logger->warning("Found stale PID file for server #{$serverId}");
                    unlink($pidFile);
                }
            } else {
                // Process doesn't exist - clean up stale PID file
                $this->logger->warning("Found stale PID file for server #{$serverId}");
                unlink($pidFile);
            }
        }
        
        return [
            'server_id' => $serverId,
            'is_running' => $isRunning,
            'pid' => $pid,
            'port' => $port
        ];
    }

    public function getAllServersStatus(): array
    {
        $statuses = [];
        $pidFiles = glob(storage_path('logs/game_*.pid'));
        
        foreach ($pidFiles as $pidFile) {
            $serverId = basename($pidFile, '.pid');
            $serverId = str_replace('game_', '', $serverId);
            
            if (is_numeric($serverId)) {
                $statuses[] = $this->getServerStatus((int)$serverId);
            }
        }
        
        return $statuses;
    }

    private function isMainManager(): bool
    {
        return getmypid() === $this->mainPid;
    }

    private function killProcessesOnPorts(array $ports): void
    {
        foreach ($ports as $port) {
            try {
                // Find processes using the port
                $cmd = "lsof -ti:{$port}";
                $output = [];
                exec($cmd, $output);
                
                if (!empty($output)) {
                    $this->logger->debug("Found processes on port {$port}: " . implode(', ', $output));
                    
                    // Kill each process
                    foreach ($output as $pid) {
                        $pid = trim($pid);
                        if (is_numeric($pid)) {
                            $this->logger->debug("Killing process {$pid} on port {$port}");
                            posix_kill((int)$pid, SIGKILL);
                        }
                    }
                    
                    // Wait a moment for processes to be killed
                    usleep(500000); // 500ms
                    
                    // Verify port is free
                    $cmd = "lsof -ti:{$port}";
                    $output = [];
                    exec($cmd, $output);
                    
                    if (!empty($output)) {
                        $this->logger->warning("Port {$port} is still in use after kill attempt");
                    }
                }
            } catch (Exception $e) {
                $this->logger->warning("Error killing processes on port {$port}: " . $e->getMessage());
            }
        }
    }
} 