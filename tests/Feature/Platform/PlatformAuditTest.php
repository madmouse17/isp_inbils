<?php

namespace Tests\Feature\Platform;

use App\Models\PlatformAdmin;
use App\Services\Core\AuditService;
use App\Services\Platform\PlatformAuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PlatformAuditTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    private array $alpha;

    private array $beta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-20 12:00:00');
        $this->alpha = $this->buildTenant('ALP', 'Alpha Net');
        $this->beta = $this->buildTenant('BET', 'Beta Link');
    }

    public function test_only_a_verified_platform_admin_reaches_the_audit_page(): void
    {
        $this->get(route('platform.audit.index'))->assertRedirect(route('platform.login'));
        $this->actingAs($this->alpha['admin'])->get(route('platform.audit.index'))->assertRedirect(route('platform.login'));
        $this->signInPlatformAdmin($this->operator, ['platform.two_factor_verified_at' => null])
            ->get(route('platform.audit.index'))
            ->assertRedirect(route('platform.login'));
    }

    public function test_page_lists_only_platform_and_support_rows_with_ids_only_payloads(): void
    {
        $audit = app(PlatformAuditService::class);
        $audit->platform('tenant.checked', ['user_id' => $this->alpha['staff']->id], $this->alpha['root'], $this->operator);
        $audit->support('user.login_unlocked', ['user_id' => $this->alpha['staff']->id], $this->alpha['staff'], $this->operator);
        AuditService::log('security', 'two_factor.challenge_failed', ['user_id' => $this->alpha['staff']->id], $this->alpha['staff']);

        $this->asOperator(false)->get(route('platform.audit.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Platform/Audit/Index')
                ->where('filters', [
                    'company_id' => null,
                    'platform_admin_id' => null,
                    'event' => null,
                    'date_from' => null,
                    'date_to' => null,
                    'search' => null,
                    'page' => 1,
                    'per_page' => 10,
                ])
                ->has('activity.data', 4)
                ->where('activity.data.0.description', 'user.login_unlocked')
                ->where('activity.data.0.causer', [
                    'type' => 'platform_admin',
                    'id' => $this->operator->id,
                    'name' => $this->operator->name,
                ])
                ->where('activity.data.0.subject', ['type' => 'User', 'id' => $this->alpha['staff']->id])
                ->missing('activity.data.0.causer.email')
                ->where('activity.meta.total', 4));
    }

    public function test_filters_by_tenant_including_subsidiaries_admin_event_and_utc_date_range(): void
    {
        $other = PlatformAdmin::factory()->create(['name' => 'Operator Dua']);
        $audit = app(PlatformAuditService::class);

        $this->travelTo('2026-10-19 23:59:59');
        $audit->platform('tenant.before', [], $this->alpha['root'], $this->operator);
        $this->travelTo('2026-10-20 00:00:00');
        $audit->platform('tenant.target', [], $this->alpha['child'], $this->operator);
        $audit->support('tenant.target', [], $this->alpha['root'], $other);
        $audit->platform('tenant.target', [], $this->beta['root'], $this->operator);
        $this->travelTo('2026-10-21 00:00:00');
        $audit->platform('tenant.after', [], $this->alpha['root'], $this->operator);

        $this->asOperator(false)->get(route('platform.audit.index', [
            'company_id' => $this->alpha['root']->id,
            'platform_admin_id' => $this->operator->id,
            'event' => 'tenant.target',
            'date_from' => '2026-10-20',
            'date_to' => '2026-10-20',
        ]))->assertInertia(fn (Assert $page) => $page
            ->has('activity.data', 1)
            ->where('activity.data.0.company.id', $this->alpha['child']->id)
            ->where('activity.data.0.description', 'tenant.target'));
    }

    public function test_searches_description_causer_and_tenant_including_a_purged_snapshot(): void
    {
        $audit = app(PlatformAuditService::class);
        $audit->platform('tenant.lookup', [], $this->alpha['root'], $this->operator);
        DB::table('activity_log')->insert([
            'company_id' => null,
            'log_name' => 'platform',
            'description' => 'tenant.purged',
            'properties' => json_encode(['tenant_code' => 'OLD', 'tenant_name' => 'Old Fiber']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $expected = ['lookup' => 1, $this->operator->email => 3, 'Alpha Net' => 1, 'Old Fiber' => 1];

        foreach ($expected as $search => $total) {
            $this->asOperator(false)->get(route('platform.audit.index', ['search' => $search]))
                ->assertInertia(fn (Assert $page) => $page->where('activity.meta.total', $total));
        }

        $this->asOperator(false)->get(route('platform.audit.index', ['search' => 'Old Fiber']))
            ->assertInertia(fn (Assert $page) => $page->where('activity.data.0.company', [
                'id' => null,
                'name' => 'Old Fiber',
                'code' => 'OLD',
            ]));
    }

    public function test_paginates_and_validates_filters_without_querying_per_causer(): void
    {
        $audit = app(PlatformAuditService::class);
        $audit->platform('tenant.first', [], $this->alpha['root'], $this->operator);

        $queries = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->asOperator(false)->get(route('platform.audit.index', ['per_page' => 25]))->assertOk();

            return count(DB::getQueryLog());
        };

        $few = $queries();

        foreach (range(1, 30) as $n) {
            $audit->platform("tenant.bulk.{$n}", [], $this->alpha['root'], $this->operator);
        }

        $this->assertSame($few, $queries());
        $this->asOperator(false)->get(route('platform.audit.index', ['per_page' => 25, 'page' => 2]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('activity.meta.total', 33)
                ->where('activity.meta.per_page', 25)
                ->has('activity.data', 8));

        foreach (['company_id' => $this->alpha['child']->id, 'platform_admin_id' => 999999, 'page' => 0, 'per_page' => 1000, 'date_to' => '2026-10-19'] as $key => $value) {
            $query = [$key => $value];

            if ($key === 'date_to') {
                $query['date_from'] = '2026-10-20';
            }

            $this->asOperator(false)->get(route('platform.audit.index', $query))->assertSessionHasErrors($key);
        }
    }
}
