<?php

namespace Tests\Feature\Odn;

use App\Models\Core\Company;
use App\Services\Core\CompanyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\NetworkAsset\Models\OdnPort;
use Modules\NetworkAsset\Models\OdnSplitter;
use Modules\NetworkAsset\Services\OdnService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Traits\BuildsIpamOdnFixtures;
use Tests\Traits\BuildsOdnTopology;
use Tests\Traits\RunsConcurrentWorkers;

/**
 * Independent processes race for the same drop port; the port row lock and the unique indexes must leave
 * exactly one winner. The fixture is committed for the workers and purged afterwards.
 */
class OdnConcurrencyTest extends TestCase
{
    use BuildsIpamOdnFixtures;
    use BuildsOdnTopology;
    use RefreshDatabase;
    use RunsConcurrentWorkers;

    private const ASSIGN = <<<'PHP'
        $port = Modules\NetworkAsset\Models\OdnPort::query()->findOrFail($args['port_id']);
        $subscription = App\Models\Core\ServiceSubscription::query()->findOrFail($args['subscription_id']);

        return Modules\NetworkAsset\Services\OdnService::assignDrop($port, $subscription)->id;
    PHP;

    /** Both workers rewire at once: one connects A to B's output, the other B to A's output. */
    private const REWIRE = <<<'PHP'
        if (isset($args['cache_store'])) {
            config(['cache.default' => $args['cache_store']]);
        }

        return Modules\NetworkAsset\Services\OdnService::connectSplitter($args['splitter_id'], $args['port_id'])->id;
    PHP;

    /**
     * The slow worker fixes its snapshot before the fast one commits, as a rewire that started earlier would,
     * and only then runs: its loop check must still see what the fast one committed.
     */
    private const REWIRE_AFTER_STALE_SNAPSHOT = <<<'PHP'
        $wait = function (string $file): void {
            $deadline = microtime(true) + 20;
            while (! is_file($file)) {
                if (microtime(true) > $deadline) {
                    throw new RuntimeException('timed out waiting for '.$file);
                }
                usleep(2000);
            }
        };

        if ($args['role'] === 'fast') {
            $wait($args['snapshot']);
            $id = Modules\NetworkAsset\Services\OdnService::connectSplitter($args['splitter_id'], $args['port_id'])->id;
            file_put_contents($args['committed'], '1');

            return $id;
        }

        Illuminate\Support\Facades\DB::beginTransaction();
        Modules\NetworkAsset\Models\OdnSplitter::query()->count(); // the first plain read fixes the snapshot
        file_put_contents($args['snapshot'], '1');
        $wait($args['committed']);

        try {
            $id = Modules\NetworkAsset\Services\OdnService::connectSplitter($args['splitter_id'], $args['port_id'])->id;
            Illuminate\Support\Facades\DB::commit();

            return $id;
        } catch (Throwable $e) {
            Illuminate\Support\Facades\DB::rollBack();

            throw $e;
        }
    PHP;

    public function test_two_drops_racing_for_the_last_free_port_have_exactly_one_winner(): void
    {
        $company = Company::factory()->create(['is_active' => true]);
        $net = $this->topology($company);
        $holder = $this->makeSubscription($company);
        $first = $this->makeSubscription($company);
        $second = $this->makeSubscription($company);
        $lastPort = CompanyService::runAs($company->id, function () use ($net, $holder): OdnPort {
            [, $outputs] = $this->splitter($net['odp'], 2);
            OdnService::assignDrop($outputs[0], $holder);

            return $outputs[1];
        });
        $this->commitFixture();

        try {
            $results = $this->race([
                ['company_id' => $company->id, 'port_id' => $lastPort->id, 'subscription_id' => $first->id],
                ['company_id' => $company->id, 'port_id' => $lastPort->id, 'subscription_id' => $second->id],
            ], self::ASSIGN);

            $winners = array_values(array_filter($results, fn (array $result): bool => $result['ok']));
            $losers = array_values(array_filter($results, fn (array $result): bool => ! $result['ok']));

            $this->assertCount(1, $winners, json_encode($results));
            $this->assertCount(1, $losers, json_encode($results));
            $this->assertSame($lastPort->id, $winners[0]['result']);
            $this->assertSame(422, $losers[0]['status']);
            $this->assertArrayHasKey('port', $losers[0]['errors']);

            $winnerSubscriptionIds = OdnPort::withoutCompany()->where('id', $lastPort->id)->pluck('subscription_id')->all();
            $this->assertCount(1, $winnerSubscriptionIds);
            $this->assertContains($winnerSubscriptionIds[0], [$first->id, $second->id]);
            $this->assertSame(2, OdnPort::withoutCompany()->where('company_id', $company->id)->whereNotNull('subscription_id')->count(), 'the holder and one winner');
        } finally {
            $this->purgeCompany($company->id);
        }
    }

    public function test_one_subscription_sent_to_two_ports_at_once_ends_up_on_exactly_one(): void
    {
        $company = Company::factory()->create(['is_active' => true]);
        $net = $this->topology($company);
        $subscription = $this->makeSubscription($company);
        $outputs = CompanyService::runAs($company->id, fn () => $this->splitter($net['odp'], 4)[1]);
        $this->commitFixture();

        try {
            $results = $this->race([
                ['company_id' => $company->id, 'port_id' => $outputs[0]->id, 'subscription_id' => $subscription->id],
                ['company_id' => $company->id, 'port_id' => $outputs[1]->id, 'subscription_id' => $subscription->id],
            ], self::ASSIGN);

            $this->assertTrue($results[0]['ok'], json_encode($results));
            $this->assertTrue($results[1]['ok'], json_encode($results));
            $this->assertSame(1, OdnPort::withoutCompany()->where('subscription_id', $subscription->id)->count(), 'a subscription never holds two ports');
        } finally {
            $this->purgeCompany($company->id);
        }
    }

    public function test_a_rewire_that_began_before_another_committed_still_sees_the_loop_it_would_close(): void
    {
        [$company, $a, $b, $aOut, $bOut] = $this->twoFreeSplitters();
        $signals = $this->signalFiles();

        try {
            $results = $this->race([
                ['company_id' => $company->id, 'role' => 'fast', 'splitter_id' => $a->id, 'port_id' => $bOut->id, ...$signals],
                ['company_id' => $company->id, 'role' => 'stale', 'splitter_id' => $b->id, 'port_id' => $aOut->id, ...$signals],
            ], self::REWIRE_AFTER_STALE_SNAPSHOT);

            $this->assertTrue($results[0]['ok'], json_encode($results));
            $this->assertFalse($results[1]['ok'], 'the stale rewire closed a loop: '.json_encode($results));
            $this->assertSame(422, $results[1]['status']);
            $this->assertArrayHasKey('input_port_id', $results[1]['errors']);
            $this->assertWiredOnce($company->id, $a->id, $bOut->id, $b->id);
        } finally {
            array_map(fn (string $file): bool => @unlink($file), [$signals['snapshot'], $signals['committed']]);
            $this->purgeCompany($company->id);
        }
    }

    /** @return array<string, array{0: string|null}> */
    public static function lockModes(): array
    {
        return [
            'topology lock not shared between processes (array cache): the row locks decide' => [null],
            'topology lock shared between processes (database cache): the writers queue up' => ['database'],
        ];
    }

    #[DataProvider('lockModes')]
    public function test_two_opposite_rewires_at_once_close_no_loop(?string $cacheStore): void
    {
        [$company, $a, $b, $aOut, $bOut] = $this->twoFreeSplitters();

        try {
            $results = $this->race([
                ['company_id' => $company->id, 'splitter_id' => $a->id, 'port_id' => $bOut->id, 'cache_store' => $cacheStore],
                ['company_id' => $company->id, 'splitter_id' => $b->id, 'port_id' => $aOut->id, 'cache_store' => $cacheStore],
            ], self::REWIRE);

            $winners = array_values(array_filter($results, fn (array $result): bool => $result['ok']));
            $losers = array_values(array_filter($results, fn (array $result): bool => ! $result['ok']));

            $this->assertCount(1, $winners, json_encode($results));
            $this->assertCount(1, $losers, json_encode($results));
            $this->assertSame(422, $losers[0]['status']);
            $this->assertArrayHasKey('input_port_id', $losers[0]['errors']);
            $this->assertSame(1, OdnSplitter::withoutCompany()->where('company_id', $company->id)->whereNotNull('input_port_id')->count(), 'exactly one of the two is wired: no loop');
        } finally {
            $this->purgeCompany($company->id);
        }
    }

    /**
     * Two splitters in one company with a free output each, committed for the workers.
     *
     * @return array{0: Company, 1: OdnSplitter, 2: OdnSplitter, 3: OdnPort, 4: OdnPort} company, A (in the ODC), B (in the ODP), A's first output, B's first output
     */
    private function twoFreeSplitters(): array
    {
        $company = Company::factory()->create(['is_active' => true]);
        $net = $this->topology($company);
        [$a, $aOutputs, $b, $bOutputs] = CompanyService::runAs($company->id, function () use ($net): array {
            [$a, $aOutputs] = $this->splitter($net['odc'], 4);
            [$b, $bOutputs] = $this->splitter($net['odp'], 4);

            return [$a, $aOutputs, $b, $bOutputs];
        });
        $this->commitFixture();

        return [$company, $a, $b, $aOutputs[0], $bOutputs[0]];
    }

    /** @return array{snapshot: string, committed: string} unused temp paths the two workers use to take turns */
    private function signalFiles(): array
    {
        $base = tempnam(sys_get_temp_dir(), 'odn-rewire-');
        $this->assertNotFalse($base);
        unlink($base);

        return ['snapshot' => $base.'.snapshot', 'committed' => $base.'.committed'];
    }

    private function assertWiredOnce(int $companyId, int $wiredSplitterId, int $viaPortId, int $unwiredSplitterId): void
    {
        $this->assertSame(1, OdnSplitter::withoutCompany()->where('company_id', $companyId)->whereNotNull('input_port_id')->count(), 'exactly one of the two is wired: no loop');
        $this->assertSame($viaPortId, OdnSplitter::withoutCompany()->findOrFail($wiredSplitterId)->input_port_id);
        $this->assertNull(OdnSplitter::withoutCompany()->findOrFail($unwiredSplitterId)->input_port_id);
    }
}
