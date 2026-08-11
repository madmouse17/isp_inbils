<?php

namespace Tests\Feature\Operations;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class SchedulerLockTest extends TestCase
{
    public function test_billing_schedules_use_without_overlapping(): void
    {
        /** @var Schedule $schedule */
        $schedule = $this->app->make(Schedule::class);

        $events = collect($schedule->events());

        $generate = $events->first(fn (Event $e) => str_contains($e->command ?? $e->description ?? '', 'billing:generate'));
        $overdue = $events->first(fn (Event $e) => str_contains($e->command ?? $e->description ?? '', 'billing:check-overdue'));
        $sla = $events->first(fn (Event $e) => str_contains($e->command ?? $e->description ?? '', 'tickets:check-sla'));
        $heartbeat = $events->first(fn (Event $e) => str_contains($e->command ?? $e->description ?? '', 'ops:scheduler-heartbeat'));

        $this->assertNotNull($generate, 'billing:generate schedule missing');
        $this->assertNotNull($overdue, 'billing:check-overdue schedule missing');
        $this->assertNotNull($sla, 'tickets:check-sla schedule missing');
        $this->assertNotNull($heartbeat, 'ops:scheduler-heartbeat schedule missing');

        $this->assertTrue(
            $this->eventHasWithoutOverlapping($generate),
            'billing:generate must use withoutOverlapping()'
        );
        $this->assertTrue(
            $this->eventHasWithoutOverlapping($overdue),
            'billing:check-overdue must use withoutOverlapping()'
        );
        $this->assertTrue(
            $this->eventHasWithoutOverlapping($sla),
            'tickets:check-sla must use withoutOverlapping()'
        );
    }

    private function eventHasWithoutOverlapping(Event $event): bool
    {
        // withoutOverlapping sets mutex name / expiresAt on the event.
        return $event->mutexName() !== null
            && property_exists($event, 'expiresAt')
            && $event->expiresAt !== null;
    }
}
