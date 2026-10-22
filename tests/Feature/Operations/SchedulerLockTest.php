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
        $sla = $events->first(fn (Event $e) => str_contains($e->command ?? $e->description ?? '', 'tickets:sla'));
        $heartbeat = $events->first(fn (Event $e) => str_contains($e->command ?? $e->description ?? '', 'ops:scheduler-heartbeat'));

        $this->assertNotNull($generate, 'billing:generate schedule missing');
        $this->assertNotNull($overdue, 'billing:check-overdue schedule missing');
        $this->assertNotNull($sla, 'tickets:sla schedule missing');
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
            'tickets:sla must use withoutOverlapping()'
        );
    }

    public function test_every_overlap_lock_expires_before_it_can_skip_a_run(): void
    {
        $expected = [
            'tickets:sla' => 10,
            'midtrans:reconcile' => 10,
            'network:sync' => 10,
            'announcements:publish-scheduled' => 10,
            'customers:document-expiry' => 120,
            'inbils:platform:exports:prune' => 50,
            'billing:generate' => 120,
            'billing:check-overdue' => 120,
            'billing:suspend-overdue' => 120,
            'billing:whatsapp-reminders' => 120,
            'promo-banners:cleanup-images' => 120,
            'inbils:activity-log:prune' => 120,
            'inventory:send-digest' => 120,
        ];

        $events = collect($this->app->make(Schedule::class)->events());
        $locked = [];

        foreach ($events->filter(fn (Event $e): bool => $e->withoutOverlapping) as $event) {
            $name = collect(array_keys($expected))->first(fn (string $command): bool => str_contains((string) $event->command, " {$command}"));
            $this->assertNotNull($name, "Unexpected overlap lock on: {$event->command}");
            $locked[$name] = $event->expiresAt;
        }

        ksort($expected);
        ksort($locked);
        $this->assertSame($expected, $locked, 'Overlap lock TTL (minutes) per scheduled command');
    }

    public function test_the_activity_log_prune_runs_at_02_30_jakarta(): void
    {
        // The scheduler runs in UTC, so 19:30 is 02:30 the next morning in Jakarta: nobody is using the audit page.
        $this->assertSame('UTC', config('app.timezone'));
        $this->assertSame('30 19 * * *', $this->scheduled('inbils:activity-log:prune')->expression);
    }

    public function test_failed_jobs_are_pruned_after_30_days(): void
    {
        $event = $this->scheduled('queue:prune-failed');

        $this->assertStringEndsWith(' queue:prune-failed --hours=720', (string) $event->command, '720 hours are 30 days');
        $this->assertSame('0 0 * * *', $event->expression);
    }

    public function test_the_scheduler_container_clears_stale_locks_on_boot(): void
    {
        $entrypoint = (string) file_get_contents(base_path('docker/app/entrypoint.sh'));

        $this->assertMatchesRegularExpression(
            '/\[ "\$\{3:-\}" = "schedule:work" \]; then\s+php artisan schedule:clear-cache\s+fi/',
            $entrypoint,
        );
        $this->assertLessThan(
            strpos($entrypoint, 'exec "$@"'),
            strpos($entrypoint, 'schedule:clear-cache'),
            'schedule:clear-cache must run before the scheduler starts',
        );
    }

    private function eventHasWithoutOverlapping(Event $event): bool
    {
        // withoutOverlapping sets mutex name / expiresAt on the event.
        return $event->mutexName() !== null
            && property_exists($event, 'expiresAt')
            && $event->expiresAt !== null;
    }

    private function scheduled(string $command): Event
    {
        $event = collect($this->app->make(Schedule::class)->events())
            ->first(fn (Event $e): bool => str_contains((string) $e->command, " {$command}"));

        $this->assertNotNull($event, "{$command} is not scheduled");

        return $event;
    }
}
