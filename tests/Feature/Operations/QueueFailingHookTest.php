<?php

namespace Tests\Feature\Operations;

use Illuminate\Contracts\Queue\Job as QueueJobContract;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class QueueFailingHookTest extends TestCase
{
    public function test_queue_failing_reports_via_exception_reporter_and_redacts_secrets(): void
    {
        Log::shouldReceive('error')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                return str_contains($message, 'queue boom')
                    && ($context['password'] ?? null) === '[REDACTED]'
                    && ($context['token'] ?? null) === '[REDACTED]'
                    && ($context['job'] ?? null) !== null;
            });

        Log::shouldReceive('warning')->zeroOrMoreTimes();
        Log::shouldReceive('channel')->andReturnSelf()->zeroOrMoreTimes();

        $job = Mockery::mock(QueueJobContract::class);
        $job->shouldReceive('getQueue')->andReturn('default');
        $job->shouldReceive('resolveName')->andReturn('App\\Jobs\\FakeJob');
        $job->shouldReceive('uuid')->andReturn('job-uuid-1');
        $job->shouldReceive('payload')->andReturn([
            'displayName' => 'App\\Jobs\\FakeJob',
            'data' => [
                'command' => serialize((object) [
                    'companyId' => 7,
                    'password' => 'hunter2',
                    'token' => 'secret-token',
                ]),
            ],
        ]);
        $job->shouldReceive('maxTries')->andReturn(3);
        $job->shouldReceive('attempts')->andReturn(3);

        event(new JobFailed(
            'database',
            $job,
            new RuntimeException('queue boom'),
        ));
    }

    public function test_queue_failing_listener_is_registered(): void
    {
        $this->assertTrue(
            Event::hasListeners(JobFailed::class),
            'Queue JobFailed must have a listener (Queue::failing hook)'
        );
    }
}
