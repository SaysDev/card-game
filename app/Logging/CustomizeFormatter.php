<?php

namespace App\Logging;

use Monolog\Formatter\LineFormatter;
use Monolog\Handler\FormattableHandlerInterface;
use Monolog\Logger;
use Illuminate\Support\Facades\Log;

class CustomizeFormatter
{
    /**
     * Customize the given logger instance.
     *
     * @param  Logger  $logger
     * @return void
     */
    public function __invoke(Logger $logger)
    {
        $formatter = new LineFormatter(
            "[%datetime%] %level_name% %message% %context% %extra%\n",
            "Y-m-d H:i:s",
            true,
            true
        );

        foreach ($logger->getHandlers() as $handler) {
            if ($handler instanceof FormattableHandlerInterface) {
                try {
                    $handler->setFormatter($formatter);
                } catch (\Throwable $e) {
                    // Use Laravel's logger to log the error
                    Log::error('Failed to set formatter on handler: ' . $e->getMessage(), [
                        'exception' => $e,
                        'handler' => get_class($handler)
                    ]);
                }
            }
        }
    }
} 