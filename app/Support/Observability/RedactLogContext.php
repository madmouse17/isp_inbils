<?php

namespace App\Support\Observability;

use Illuminate\Log\Logger;
use Monolog\LogRecord;

/**
 * Logging tap (config/logging.php): LogRedactor runs over the context and extra of every record before a handler
 * sees it, so a secret that reaches a log call by accident is masked on its way out. A stack takes the processors of
 * its channels, so each channel names the tap, not the stack.
 */
final class RedactLogContext
{
    public function __invoke(Logger $logger): void
    {
        $logger->pushProcessor(fn (LogRecord $record): LogRecord => $record->with(
            context: LogRedactor::redact($record->context),
            extra: LogRedactor::redact($record->extra),
        ));
    }
}
