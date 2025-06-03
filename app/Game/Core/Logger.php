<?php

namespace App\Game\Core;

use Monolog\Logger as MonologLogger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Formatter\LineFormatter;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Illuminate\Support\Facades\Log as LaravelLog;

class Logger implements LoggerInterface
{
    private MonologLogger $monolog;
    private string $context;
    private array $handlers = [];

    public function __construct(string $logPath, string $context)
    {
        $this->context = $context;
        
        // Create logger instance
        $this->monolog = new MonologLogger($context);
        
        // Create rotating file handler (keeps last 7 days of logs)
        $handler = new RotatingFileHandler(
            $logPath . "/{$context}.log",
            7,
            MonologLogger::DEBUG
        );
        
        // Set custom format
        $formatter = new LineFormatter(
            "[%datetime%] [%channel%] %level_name%: %message% %context%\n",
            "Y-m-d H:i:s"
        );
        $handler->setFormatter($formatter);
        
        // Add handler to logger
        $this->monolog->pushHandler($handler);
        $this->handlers[] = $handler;
    }

    public function setLogLevel(string $level): void
    {
        $monologLevel = $this->getMonologLevel($level);
        foreach ($this->handlers as $handler) {
            $handler->setLevel($monologLevel);
        }
    }

    private function getMonologLevel(string $level): int
    {
        return match ($level) {
            LogLevel::EMERGENCY => MonologLogger::EMERGENCY,
            LogLevel::ALERT => MonologLogger::ALERT,
            LogLevel::CRITICAL => MonologLogger::CRITICAL,
            LogLevel::ERROR => MonologLogger::ERROR,
            LogLevel::WARNING => MonologLogger::WARNING,
            LogLevel::NOTICE => MonologLogger::NOTICE,
            LogLevel::INFO => MonologLogger::INFO,
            LogLevel::DEBUG => MonologLogger::DEBUG,
            default => MonologLogger::DEBUG,
        };
    }

    public function log($level, $message, array $context = []): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $source = $this->context;
        $formattedMessage = "[{$source}] {$message}";
        
        // Log to Laravel's logging system
        $laravelLevel = strtolower($level);
        LaravelLog::channel('stack')->log($laravelLevel, $formattedMessage, $context);
        
        // Also log to monolog for file-based logging
        $this->monolog->log($this->getMonologLevel($level), $formattedMessage, $context);
    }

    public function emergency($message, array $context = []): void
    {
        $this->log(LogLevel::EMERGENCY, $message, $context);
    }

    public function alert($message, array $context = []): void
    {
        $this->log(LogLevel::ALERT, $message, $context);
    }

    public function critical($message, array $context = []): void
    {
        $this->log(LogLevel::CRITICAL, $message, $context);
    }

    public function error($message, array $context = []): void
    {
        $this->log(LogLevel::ERROR, $message, $context);
    }

    public function warning($message, array $context = []): void
    {
        $this->log(LogLevel::WARNING, $message, $context);
    }

    public function notice($message, array $context = []): void
    {
        $this->log(LogLevel::NOTICE, $message, $context);
    }

    public function info($message, array $context = []): void
    {
        $this->log(LogLevel::INFO, $message, $context);
    }

    public function debug($message, array $context = []): void
    {
        $this->log(LogLevel::DEBUG, $message, $context);
    }
} 