<?php

namespace Tests\Feature\Reporting;

use App\Models\Core\Company;
use App\Models\Core\EmployeeEvaluation;
use App\Models\Core\EmployeeProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Reporting\Queries\TechnicianPerformanceQuery;
use Modules\Ticketing\Models\TicketCategory;
use Tests\TestCase;
use Tests\Traits\BuildsReportingWorld;
use Tests\Traits\CreatesReportingUsers;
use Tests\Traits\WithoutAppOnlyRedirect;

/**
 * F9-U02 and F9-D04: the Technician report lists every technician of the viewer's scope, ranked by what they finished,
 * until one is picked. Each row is the figures of that technician's own page, and the figures of all of them cost the
 * same few queries however many they are.
 */
class TechnicianRankingTest extends TestCase
{
    use BuildsReportingWorld;
    use CreatesReportingUsers;
    use RefreshDatabase;
    use WithoutAppOnlyRedirect;

    private const JANUARY = ['date_from' => '2026-01-01', 'date_to' => '2026-01-31'];

    private User $admin;

    private int $companyId;

    private TicketCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->userWithRole('admin');
        $this->companyId = (int) $this->admin->company_id;
        Company::query()->whereKey($this->companyId)->update(['timezone' => 'UTC']);
        $this->category = $this->worldCategory($this->companyId, 'Support', 'SUP');
        $this->actingAs($this->admin);
    }

    public function test_every_technician_is_ranked_by_the_work_they_finished_and_ties_share_a_rank(): void
    {
        [$agus, $budi, $citra, $dewi] = [$this->technician('Agus'), $this->technician('Budi'), $this->technician('Citra'), $this->technician('Dewi')];
        $this->work($agus, spk: 3, tickets: 2);
        $this->work($budi, spk: 2, tickets: 3);
        $this->work($citra, spk: 1, tickets: 0);
        // Outside the period: February's SPK and December's ticket are nobody's January.
        $this->worldWorkOrder($this->companyId, 'maintenance', 'completed', '2026-02-03 10:00:00', $agus->id);
        $this->worldTicket($this->companyId, $this->category, '2025-12-20 08:00:00', null, '2025-12-20 09:00:00', '2025-12-20 10:00:00', 'closed', $agus->id);
        // Not in the ranking: an inactive technician, one of another company, and a user who is no technician.
        $this->technician('Eko', active: false);
        $this->foreignTechnician();

        $props = $this->get(route('admin.reports.technician', self::JANUARY))->assertOk()->inertiaProps();
        $ranking = $props['ranking'];

        $this->assertNull($props['data'], 'no technician is picked, so there is no single page');
        $this->assertSame(['Agus', 'Budi', 'Citra', 'Dewi'], array_column($ranking, 'name'), 'most finished first, equal totals by name');
        $this->assertSame([1, 1, 3, 4], array_column($ranking, 'rank'), 'a tie shares its rank, the next is skipped');
        $this->assertSame([3, 2, 1, 0], array_column($ranking, 'spk_completed'));
        $this->assertSame([2, 3, 0, 0], array_column($ranking, 'tickets_resolved'));
        $this->assertSame([$agus->public_id, $budi->public_id, $citra->public_id, $dewi->public_id], array_column($ranking, 'id'), 'ids are public ids');
        $this->assertSame(['id', 'name', 'rank', 'spk_completed', 'tickets_resolved', 'sla_compliance_pct', 'avg_frt_minutes', 'avg_score', 'active_workload'], array_keys($ranking[0]));
    }

    public function test_a_row_is_the_figures_of_that_technicians_own_page(): void
    {
        $agus = $this->technician('Agus');
        $budi = $this->technician('Budi');
        $this->work($agus, spk: 2, tickets: 2, answeredAfter: 30);
        $this->work($budi, spk: 1, tickets: 0);
        // One of Agus's tickets missed its deadline; open work counts for the workload.
        $this->worldTicket($this->companyId, $this->category, '2026-01-20 08:00:00', '2026-01-20 09:00:00', '2026-01-20 12:00:00', '2026-01-20 10:00:00', 'closed', $agus->id);
        $this->worldWorkOrder($this->companyId, 'installation', 'in_progress', null, $agus->id, '2026-01-25 08:00:00');
        $this->worldTicket($this->companyId, $this->category, '2026-01-26 08:00:00', null, null, '2026-01-27 08:00:00', 'assigned', $agus->id);
        foreach ([4.0, 3.0] as $score) {
            EmployeeEvaluation::factory()->create(['company_id' => $this->companyId, 'employee_id' => $agus->id, 'evaluator_id' => $this->admin->id, 'score' => $score, 'evaluated_at' => '2026-01-15 10:00:00']);
        }

        $ranking = collect($this->get(route('admin.reports.technician', self::JANUARY))->assertOk()->inertiaProps('ranking'))->keyBy('name');

        foreach (['Agus' => $agus, 'Budi' => $budi] as $name => $technician) {
            $own = $this->get(route('admin.reports.technician', ['technician_id' => $technician->public_id, ...self::JANUARY]))->assertOk()->inertiaProps('data');
            $row = $ranking[$name];

            $this->assertSame($own['spk_completed'], $row['spk_completed'], "{$name}: SPK");
            $this->assertSame($own['tickets_resolved'], $row['tickets_resolved'], "{$name}: tickets");
            $this->assertSame($own['active_workload'], $row['active_workload'], "{$name}: workload");
            $this->assertEquals($own['avg_frt_minutes'], $row['avg_frt_minutes'], "{$name}: first response");
            $this->assertEquals($own['avg_score'], $row['avg_score'], "{$name}: score");
            if ($row['tickets_resolved'] > 0) {
                $this->assertEquals($own['sla_compliance_pct'], $row['sla_compliance_pct'], "{$name}: SLA");
            }
        }

        // 2 of Agus's 3 tickets were on time; the average of 4.0 and 3.0 is 3.5; the first answers took 30, 30 and 60 minutes.
        $this->assertEquals(66.7, $ranking['Agus']['sla_compliance_pct']);
        $this->assertEquals(3.5, $ranking['Agus']['avg_score']);
        $this->assertEquals(40, $ranking['Agus']['avg_frt_minutes']);
        $this->assertSame(2, $ranking['Agus']['active_workload']);
        $this->assertNull($ranking['Budi']['sla_compliance_pct'], 'no resolved ticket, no rate (not a 0)');
        $this->assertNull($ranking['Budi']['avg_frt_minutes']);
        $this->assertNull($ranking['Budi']['avg_score']);
    }

    public function test_the_queries_do_not_grow_with_the_number_of_technicians(): void
    {
        foreach (range(1, 2) as $n) {
            $this->work($this->technician("Teknisi {$n}"), spk: 1, tickets: 1);
        }
        $two = $this->queriesOfRanking();

        foreach (range(3, 9) as $n) {
            $this->work($this->technician("Teknisi {$n}"), spk: 2, tickets: 2);
        }
        $nine = $this->queriesOfRanking();

        $this->assertCount(9, TechnicianPerformanceQuery::ranking($this->admin, '2026-01-01', '2026-01-31'));
        $this->assertSame($two, $nine, 'one query per measure, never one per technician');
        $this->assertLessThanOrEqual(10, $nine);
    }

    public function test_without_a_period_everything_counts_and_with_one_only_its_days(): void
    {
        $agus = $this->technician('Agus');
        $this->worldWorkOrder($this->companyId, 'installation', 'completed', '2025-11-03 10:00:00', $agus->id);
        $this->worldWorkOrder($this->companyId, 'installation', 'completed', '2026-01-03 10:00:00', $agus->id);

        $all = $this->get(route('admin.reports.technician'))->assertOk()->inertiaProps('ranking');
        $january = $this->get(route('admin.reports.technician', self::JANUARY))->assertOk()->inertiaProps('ranking');
        $none = $this->get(route('admin.reports.technician', ['date_from' => '2024-01-01', 'date_to' => '2024-01-31']))->assertOk()->inertiaProps('ranking');

        $this->assertSame([2], array_column($all, 'spk_completed'));
        $this->assertSame([1], array_column($january, 'spk_completed'));
        $this->assertSame([0], array_column($none, 'spk_completed'), 'a technician with no work still has a row');
    }

    public function test_a_technician_is_shown_their_own_page_not_a_ranking_and_no_technicians_is_an_empty_one(): void
    {
        $this->get(route('admin.reports.technician'))->assertOk()
            ->assertInertia(fn ($page) => $page->where('ranking', [])->where('hasTechnicians', false));

        $agus = $this->technician('Agus');
        $this->technician('Budi');
        $this->actingAs($agus);

        $props = $this->get(route('admin.reports.technician'))->assertOk()->inertiaProps();

        $this->assertNull($props['ranking'], 'a technician sees only themself, as before');
        $this->assertSame($agus->public_id, $props['data']['technician_id']);
    }

    /** @return int how many queries the ranking took */
    private function queriesOfRanking(): int
    {
        // The permission checks of the actor are cached after the first call; count a warm one.
        TechnicianPerformanceQuery::ranking($this->admin, '2026-01-01', '2026-01-31');
        DB::flushQueryLog();
        DB::enableQueryLog();
        TechnicianPerformanceQuery::ranking($this->admin, '2026-01-01', '2026-01-31');
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    private function technician(string $name, bool $active = true): User
    {
        $technician = User::factory()->create(['company_id' => $this->companyId, 'is_active' => $active, 'email_verified_at' => now(), 'name' => $name]);
        $this->grantRole($technician, 'technician');
        EmployeeProfile::factory()->create(['company_id' => $this->companyId, 'user_id' => $technician->id, 'status' => 'active']);

        return $technician;
    }

    private function foreignTechnician(): User
    {
        $company = Company::factory()->create(['is_active' => true]);
        $technician = User::factory()->create(['company_id' => $company->id, 'is_active' => true, 'email_verified_at' => now()]);
        $this->grantRole($technician, 'technician');
        EmployeeProfile::factory()->create(['company_id' => $company->id, 'user_id' => $technician->id, 'status' => 'active']);
        $this->worldWorkOrder($company->id, 'installation', 'completed', '2026-01-10 10:00:00', $technician->id);

        return $technician;
    }

    /** $spk SPK completed and $tickets tickets resolved in January, all on time, the tickets answered after $answeredAfter minutes. */
    private function work(User $technician, int $spk, int $tickets, int $answeredAfter = 60): void
    {
        for ($n = 1; $n <= $spk; $n++) {
            $this->worldWorkOrder($this->companyId, 'installation', 'completed', sprintf('2026-01-%02d 10:00:00', $n + 4), $technician->id, sprintf('2026-01-%02d 08:00:00', $n + 4));
        }

        for ($n = 1; $n <= $tickets; $n++) {
            $day = sprintf('2026-01-%02d', $n + 9);
            $answered = date('Y-m-d H:i:s', strtotime("{$day} 08:00:00 +{$answeredAfter} minutes"));
            $this->worldTicket($this->companyId, $this->category, "{$day} 08:00:00", $answered, "{$day} 12:00:00", "{$day} 18:00:00", 'closed', $technician->id);
        }
    }
}
