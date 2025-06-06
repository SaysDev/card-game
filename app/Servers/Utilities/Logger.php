<?php

namespace App\Servers\Utilities;

class Logger
{
    private string $context;
    private bool $enableConsole;
    private bool $enableFileLog;
    private int $pid;
    private static bool $pidEnabled = true;
    private static array $instances = [];
    
    /**
     * Create a new logger instance or return existing one
     * 
     * @param string $context The context name (e.g., "WebSocket", "GameServer", "RoomHandler", etc.)
     * @param array $options Configuration options
     */
    public function __construct(string $context, array $options = [])
    {
        // Use singleton pattern to prevent multiple logger instances for the same context
        $instanceKey = $context . '_' . getmypid();
        if (isset(self::$instances[$instanceKey])) {
            $instance = self::$instances[$instanceKey];
            $this->context = $instance->context;
            $this->enableConsole = $instance->enableConsole;
            $this->enableFileLog = $instance->enableFileLog;
            $this->pid = $instance->pid;
            return;
        }
        
        $this->context = $context;
        $this->enableConsole = $options['console'] ?? true;
        $this->enableFileLog = $options['file'] ?? false; // Default to false to prevent duplication with script redirection
        $this->pid = getmypid();
        
        // Store this instance
        self::$instances[$instanceKey] = $this;
    }
    
    /**
     * Enable or disable showing PID in logs
     */
    public static function showPid(bool $show): void
    {
        self::$pidEnabled = $show;
    }
    
    /**
     * Log an info message
     */
    public function info(string $message, array $data = []): void
    {
        $this->log('INFO', $message, $data);
    }
    
    /**
     * Log a debug message
     */
    public function debug(string $message, array $data = []): void
    {
        $this->log('DEBUG', $message, $data);
    }
    
    /**
     * Log a warning message
     */
    public function warning(string $message, array $data = []): void
    {
        $this->log('WARNING', $message, $data);
    }
    
    /**
     * Log an error message
     */
    public function error(string $message, array $data = []): void
    {
        $this->log('ERROR', $message, $data);
    }
    
    /**
     * Control whether to output to console for this specific logger
     */
    public function setConsoleOutput(bool $enabled): void
    {
        $this->enableConsole = $enabled;
    }
    
    /**
     * Control whether to output to file for this specific logger
     */
    public function setFileOutput(bool $enabled): void
    {
        $this->enableFileLog = $enabled;
    }
    
    /**
     * Log a message with specified level
     */
    private function log(string $level, string $message, array $data = []): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $formattedData = empty($data) ? '' : ' ' . json_encode($data);
        $pidInfo = self::$pidEnabled ? " [PID:{$this->pid}]" : "";
        $logMessage = "{$pidInfo}[$this->context][$level][$timestamp] $message $formattedData";
        
        // In CLI mode, output to console
        if ($this->enableConsole && php_sapi_name() === 'cli') {
            echo $logMessage . PHP_EOL;
        }
        
        // Only log to file if explicitly enabled
        if ($this->enableFileLog) {
            error_log($logMessage);
        }
    }
} 