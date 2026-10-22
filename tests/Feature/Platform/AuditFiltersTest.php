<?php

namespace Tests\Feature\Platform;

use App\Services\Platform\PlatformAuditService;
use App\Support\AuditEventText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/** F11-D09, F11-D11, F11-U08, F1-D03: the operator's audit page lists an action once, in the server's words. */
class AuditFiltersTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    private array $alpha;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alpha = $this->buildTenant('ALP', 'Alpha Net');
    }

    private function suspend(): void
    {
        $audit = app(PlatformAuditService::class);
        $audit->platform('tenant.suspended', ['reason' => 'Tagihan menunggak'], $this->alpha['root'], $this->operator);
        $audit->support('tenant.suspended', ['reason' => 'Tagihan menunggak'], $this->alpha['root'], $this->operator);
    }

    public function test_the_support_copy_of_an_action_joins_the_batch_of_its_platform_row(): void
    {
        $this->suspend();

        $rows = Activity::query()->where('description', 'tenant.suspended')->orderBy('id')->get();

        $this->assertSame(['platform', 'support'], $rows->pluck('log_name')->all());
        $this->assertNotNull($rows[0]->batch_uuid);
        $this->assertSame($rows[0]->batch_uuid, $rows[1]->batch_uuid);
    }

    public function test_an_action_is_listed_once_by_default_and_all_leaves_out_the_tenants_copy(): void
    {
        $this->suspend();

        foreach ([[[], 'platform'], [['source' => 'all'], 'all'], [['source' => 'support'], 'support']] as [$query, $source]) {
            $this->asOperator(false)->get(route('platform.audit.index', $query))
                ->assertInertia(fn (Assert $page) => $page
                    ->where('filters.source', $source)
                    ->where('activity.data', fn ($rows): bool => collect($rows)->where('description', 'tenant.suspended')->pluck('log_name')->all() === [$source === 'support' ? 'support' : 'platform']));
        }
    }

    public function test_a_row_carries_the_sentence_the_server_words(): void
    {
        $this->suspend();

        $this->asOperator(false)->get(route('platform.audit.index', ['event' => 'tenant.suspended']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('activity.data.0.text', AuditEventText::describe('platform', 'tenant.suspended', ['reason' => 'Tagihan menunggak']))
                ->where('events', fn ($events): bool => collect($events)->contains([
                    'value' => 'tenant.suspended',
                    'label' => AuditEventText::describe('platform', 'tenant.suspended'),
                ])));
    }

    public function test_a_search_finds_the_words_the_page_shows(): void
    {
        $this->suspend();
        $word = mb_strtolower(explode(' ', AuditEventText::describe('platform', 'tenant.suspended'))[0]);

        $this->asOperator(false)->get(route('platform.audit.index', ['search' => $word]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('activity.data', fn ($rows): bool => collect($rows)->contains('description', 'tenant.suspended')));
    }

    public function test_a_page_past_the_last_one_shows_the_last_page(): void
    {
        $this->suspend();

        $this->asOperator(false)->get(route('platform.audit.index', ['page' => 99]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('activity.meta.current_page', 1)
                ->where('filterErrors', []));
    }

    public function test_a_refused_date_range_is_named_beside_the_filter_and_the_other_filters_still_apply(): void
    {
        $this->suspend();

        $this->asOperator(false)->get(route('platform.audit.index', ['date_from' => '2020-01-01', 'date_to' => '2019-01-01', 'event' => 'tenant.suspended']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filterErrors.date_to', 'Tanggal akhir tidak boleh sebelum tanggal mulai.')
                ->where('filters.date_to', null)
                ->where('filters.event', 'tenant.suspended')
                ->has('activity.data', 1));
    }
}
