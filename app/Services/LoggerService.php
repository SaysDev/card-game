<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class LoggerService
{
    private string $logFile;
    private bool $consoleOutput;
    private bool $fileOutput;

    public function __construct(string $logFile = 'game_server.log', bool $consoleOutput = true, bool $fileOutput = true)
    {
        $this->logFile = $logFile;
        $this->consoleOutput = $consoleOutput;
        $this->fileOutput = $fileOutput;
    }

    /**
     * Log an informational message
     */
    public function info(string $message, array $context = []): void
    {
        $this->log('INFO', $message, $context);
    }

    /**
     * Log a warning message
     */
    public function warning(string $message, array $context = []): void
    {
        $this->log('WARNING', $message, $context);
    }

    /**
     * Log an error message
     */
    public function error(string $message, array $context = []): void
    {
        $this->log('ERROR', $message, $context);
    }

    /**
     * Log a debug message
     */
    public function debug(string $message, array $context = []): void
    {
        $this->log('DEBUG', $message, $context);
    }

    /**
     * Internal logging method that handles both console and file output
     */
    private function log(string $level, string $message, array $context = []): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $formattedMessage = $this->formatMessage($timestamp, $level, $message, $context);

        // Console output
        if ($this->consoleOutput) {
            $this->consoleOutput($level, $formattedMessage);
        }

        // File output
        if ($this->fileOutput) {
            $this->fileOutput($formattedMessage);
        }

        // Laravel logging
        Log::channel('stack')->log(strtolower($level), $message, $context);
    }

    /**
     * Format the log message with timestamp, level, and context
     */
    private function formatMessage(string $timestamp, string $level, string $message, array $context): string
    {
        $contextStr = !empty($context) ? ' ' . json_encode($context) : '';
        return "[{$timestamp}] [{$level}] {$message}{$contextStr}";
    }

    /**
     * Output to console with appropriate colors
     */
    private function consoleOutput(string $level, string $message): void
    {
        $color = match ($level) {
            'ERROR' => "\033[31m", // Red
            'WARNING' => "\033[33m", // Yellow
            'INFO' => "\033[32m", // Green
            'DEBUG' => "\033[36m", // Cyan
            default => "\033[0m", // Reset
        };

        echo $color . $message . "\033[0m" . PHP_EOL;
    }

    /**
     * Write to log file
     */
    private function fileOutput(string $message): void
    {
        $logPath = storage_path('logs/' . $this->logFile);
        file_put_contents($logPath, $message . PHP_EOL, FILE_APPEND);
    }

    /**
     * Clear the log file
     */
    public function clearLog(): void
    {
        if ($this->fileOutput) {
            $logPath = storage_path('logs/' . $this->logFile);
            file_put_contents($logPath, '');
        }
    }
} 