<?php

namespace Tests\Feature\Operations;

use App\Support\PublicId;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * `inbils:activity-log:prune` (log-audit LA-OPS, contract C6): business rows live 365 days, the security, platform
 * and support trail 730, in 5 000-row DELETEs, with one `activity_log.pruned` line per retention group.
 */
class PruneActivityLogCommandTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<MessageLogged> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        // phpunit.xml logs to stderr: capture the command's lines instead of printing them.
        config(['logging.default' => 'null']);
        Log::listen(function (MessageLogged $event): void {
            $this->logged[] = $event;
        });
    }

    public function test_business_rows_past_365_days_go_and_security_platform_support_keep_730(): void
    {
        $this->insertRows('customer', 'business-old', 366);
        $this->insertRows('customer', 'business-recent', 364);

        foreach (['security', 'platform', 'support'] as $log) {
            $this->insertRows($log, "{$log}-old", 731);
            $this->insertRows($log, "{$log}-recent", 400);
        }

        $this->artisan('inbils:activity-log:prune')->assertSuccessful();

        $this->assertSame(
            ['business-recent', 'platform-recent', 'security-recent', 'support-recent'],
            DB::table('activity_log')->orderBy('description')->pluck('description')->all(),
        );
    }

    public function test_rows_without_a_log_name_follow_the_default_retention(): void
    {
        // NULL NOT IN (...) is NULL, not true: a row with no log name must be picked up on purpose.
        $this->insertRows(null, 'unnamed-old', 366);
        $this->insertRows(null, 'unnamed-recent', 364);
        $this->insertRows('default', 'default-old', 366);

        $this->artisan('inbils:activity-log:prune')->assertSuccessful();

        $this->assertSame(['unnamed-recent'], DB::table('activity_log')->pluck('description')->all());
    }

    public function test_it_deletes_in_chunks_until_nothing_is_left(): void
    {
        $this->insertRows('customer', 'old', 400, 5001);
        $this->insertRows('customer', 'recent', 1);

        $deletes = [];
        DB::listen(function (QueryExecuted $query) use (&$deletes): void {
            if (str_starts_with($query->sql, 'delete from `activity_log`')) {
                $deletes[] = $query->sql;
            }
        });

        $this->artisan('inbils:activity-log:prune')->assertSuccessful();

        $this->assertSame(['recent'], DB::table('activity_log')->pluck('description')->all());
        $this->assertGreaterThanOrEqual(2, count($deletes), '5 001 rows do not fit in one 5 000-row statement');

        foreach ($deletes as $sql) {
            $this->assertStringContainsString('limit 5000', $sql);
        }
    }

    public function test_it_logs_one_pruned_line_per_retention_group(): void
    {
        $this->insertRows('customer', 'business-old', 366, 2);
        $this->insertRows('security', 'security-old', 731);
        $this->insertRows('platform', 'platform-recent', 400);

        $this->artisan('inbils:activity-log:prune')->assertSuccessful();

        $lines = collect($this->logged)->filter(fn (MessageLogged $event): bool => $event->message === 'activity_log.pruned');

        $this->assertCount(4, $lines, 'the default group and the three longer-lived ones, each once, empty or not');
        $this->assertSame(['info'], $lines->pluck('level')->unique()->values()->all());
        $this->assertSame([
            '*' => ['log' => '*', 'days' => 365, 'deleted' => 2],
            'platform' => ['log' => 'platform', 'days' => 730, 'deleted' => 0],
            'security' => ['log' => 'security', 'days' => 730, 'deleted' => 1],
            'support' => ['log' => 'support', 'days' => 730, 'deleted' => 0],
        ], $lines->mapWithKeys(fn (MessageLogged $event): array => [$event->context['log'] => $event->context])->sortKeys()->all());
    }

    /** One bulk insert per thousand rows: the 5 001-row case would take minutes through the model. */
    private function insertRows(?string $log, string $description, int $daysOld, int $count = 1): void
    {
        $at = now()->subDays($daysOld);

        foreach (array_chunk(range(1, $count), 1000) as $chunk) {
            DB::table('activity_log')->insert(array_map(fn (int $n): array => [
                'public_id' => PublicId::new(),
                'log_name' => $log,
                'description' => $description,
                'created_at' => $at,
                'updated_at' => $at,
            ], $chunk));
        }
    }
}
