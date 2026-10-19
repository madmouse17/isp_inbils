<?php

namespace Tests\Unit\Support\Observability;

use App\Support\Observability\RedactLogContext;
use Illuminate\Log\Logger;
use Monolog\Handler\TestHandler;
use Monolog\Logger as Monolog;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

class RedactLogContextTest extends TestCase
{
    public function test_the_tap_redacts_context_and_extra(): void
    {
        $handler = new TestHandler();
        $logger = new Logger(new Monolog('test', [$handler]));

        (new RedactLogContext())($logger);

        // Pushed after the tap, so it runs first, as LogManager's ContextLogProcessor does: the tap must see what it adds.
        $logger->pushProcessor(fn (LogRecord $record): LogRecord => $record->with(extra: [
            ...$record->extra,
            'request_id' => 'req-1',
            'cookie' => 'inbils_session=abc',
        ]));

        $logger->info('something happened', [
            'token' => 'tok-123',
            'status' => 'ok',
            'meta' => ['phone' => '0812', 'count' => 2],
        ]);

        $record = $handler->getRecords()[0];

        $this->assertSame('something happened', $record->message);
        $this->assertSame(['token' => '[REDACTED]', 'status' => 'ok', 'meta' => ['phone' => '[REDACTED]', 'count' => 2]], $record->context);
        $this->assertSame(['request_id' => 'req-1', 'cookie' => '[REDACTED]'], $record->extra);
    }
}
