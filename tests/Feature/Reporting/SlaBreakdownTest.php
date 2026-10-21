<?php

namespace Tests\Feature\Reporting;

use App\Models\Core\Company;
use App\Models\Core\EmployeeProfile;
use App\Models\Core\OrganizationUnit;
use App\Models\Core\ServiceSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Modules\Service\Models\ServicePackageVersion;
use Modules\Service\Models\SLATier;
use Modules\Ticketing\Models\Ticket;
use Modules\Ticketing\Models\TicketCategory;
use Tests\TestCase;
use Tests\Traits\BuildsReportingWorld;
use Tests\Traits\CreatesReportingUsers;
use Tests\Traits\ParsesCsvDownloads;

/**
 * F6-U16: the SLA report answers who and why: the tickets resolved in the period split by handler, priority and the
 * source of their target, the worst late ones, and the customers' own rating (CSAT). Every split reads the set the cards
 * read, so its rows add up to "Tiket selesai". Figures are worked out by hand from the tickets below.
 */
class SlaBreakdownTest extends TestCase
{
    use BuildsReportingWorld;
    use CreatesReportingUsers;
    use ParsesCsvDownloads;
    use RefreshDatabase;

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

    public function test_the_tickets_split_by_handler_priority_and_target_source_and_add_up_to_the_cards(): void
    {
        $agus = $this->handler('Agus');
        $budi = $this->handler('Budi');
        $subscription = $this->worldSubscription($this->companyId, 'active', 100000, '2026-01-01 00:00:00');
        $this->nameTierOf($subscription, 'Gold 4 jam');

        // Agus: urgent, on time, answered in 30 minutes; no subscription, so the category holds the target.
        $this->resolved('2026-01-12 08:00:00', '2026-01-12 08:30:00', '2026-01-12 09:00:00', '2026-01-12 10:00:00', 'urgent', $agus->id);
        // Agus: two hours late, answered in 90, held to the tier of the subscription's package.
        $this->resolved('2026-01-13 08:00:00', '2026-01-13 09:30:00', '2026-01-13 12:00:00', '2026-01-13 10:00:00', 'medium', $agus->id, subscriptionId: $subscription->id);
        // Budi: half an hour late, never answered.
        $this->resolved('2026-01-14 08:00:00', null, '2026-01-14 10:30:00', '2026-01-14 10:00:00', 'medium', $budi->id);
        // Nobody's: on time.
        $this->resolved('2026-01-15 08:00:00', null, '2026-01-15 09:00:00', '2026-01-15 10:00:00', 'low', null);
        // Outside the period, and a ticket of another company: in no split.
        $this->resolved('2025-12-20 08:00:00', null, '2025-12-20 09:00:00', '2025-12-20 10:00:00', 'urgent', $agus->id);
        $other = $this->userWithRole('admin');
        $this->worldTicket((int) $other->company_id, $this->worldCategory((int) $other->company_id, 'Support', 'SUP-B'), '2026-01-12 08:00:00', null, '2026-01-12 09:00:00', '2026-01-12 10:00:00');

        $data = $this->get(route('admin.reports.sla', self::JANUARY))->assertOk()->inertiaProps('data');
        $byName = fn (array $rows): array => collect($rows)->keyBy(fn (array $row): string => $row['name'] ?? '-')->all();

        $this->assertSame(4, $data['total_resolved']);
        foreach (['by_technician', 'by_priority', 'by_source'] as $split) {
            $this->assertSame(4, array_sum(array_column($data[$split], 'total')), "{$split} adds up to the tickets resolved");
        }

        $technicians = $byName($data['by_technician']);
        $this->assertEquals(['name' => 'Agus', 'total' => 2, 'compliant' => 1, 'breached' => 1, 'rate' => 50, 'avg_frt_minutes' => 60], $technicians['Agus']);
        $this->assertEquals(['name' => 'Budi', 'total' => 1, 'compliant' => 0, 'breached' => 1, 'rate' => 0, 'avg_frt_minutes' => null], $technicians['Budi']);
        $this->assertEquals(['name' => null, 'total' => 1, 'compliant' => 1, 'breached' => 0, 'rate' => 100, 'avg_frt_minutes' => null], $technicians['-'], 'a ticket nobody handled is a row of its own');

        $this->assertSame(['urgent', 'medium', 'low'], array_column($data['by_priority'], 'name'), 'most urgent first');
        $priorities = $byName($data['by_priority']);
        $this->assertEquals([1, 0], [$priorities['urgent']['total'], $priorities['urgent']['breached']]);
        $this->assertEquals([2, 0, 2], [$priorities['medium']['total'], $priorities['medium']['compliant'], $priorities['medium']['breached']]);

        $sources = $byName($data['by_source']);
        $this->assertEquals(['name' => 'Gold 4 jam', 'total' => 1, 'compliant' => 0, 'breached' => 1, 'rate' => 0, 'avg_frt_minutes' => 90], $sources['Gold 4 jam']);
        $this->assertEquals([3, 2], [$sources['-']['total'], $sources['-']['compliant']], 'without a subscription the category holds the target');
    }

    public function test_the_category_filter_narrows_every_split(): void
    {
        $agus = $this->handler('Agus');
        $other = $this->worldCategory($this->companyId, 'Outage', 'OUT');
        $this->resolved('2026-01-12 08:00:00', null, '2026-01-12 09:00:00', '2026-01-12 10:00:00', 'medium', $agus->id);
        $this->resolved('2026-01-13 08:00:00', null, '2026-01-13 09:00:00', '2026-01-13 10:00:00', 'medium', $agus->id, category: $other);

        $data = $this->get(route('admin.reports.sla', [...self::JANUARY, 'category_id' => $other->public_id]))->assertOk()->inertiaProps('data');

        $this->assertSame([1], array_column($data['by_technician'], 'total'));
        $this->assertSame([1], array_column($data['by_priority'], 'total'));
    }

    public function test_a_rating_counts_on_the_day_it_was_given_and_an_unrated_ticket_is_no_zero(): void
    {
        // Resolved in December and rated in January after a reopen: it belongs to January's satisfaction.
        $this->resolved('2025-12-20 08:00:00', null, '2025-12-20 09:00:00', '2025-12-20 10:00:00', 'medium', null, rating: 1, ratedAt: '2026-01-25 10:00:00');
        $this->resolved('2026-01-12 08:00:00', null, '2026-01-12 09:00:00', '2026-01-12 10:00:00', 'medium', null, rating: 5, ratedAt: '2026-01-15 10:00:00');
        $this->resolved('2026-01-13 08:00:00', null, '2026-01-13 09:00:00', '2026-01-13 10:00:00', 'medium', null, rating: 3, ratedAt: '2026-01-20 10:00:00');
        // Rated after the period, and never rated.
        $this->resolved('2026-01-14 08:00:00', null, '2026-01-14 09:00:00', '2026-01-14 10:00:00', 'medium', null, rating: 4, ratedAt: '2026-02-02 10:00:00');
        $this->resolved('2026-01-15 08:00:00', null, '2026-01-15 09:00:00', '2026-01-15 10:00:00', 'medium', null);

        $csat = fn (array $query): array => Arr::only($this->get(route('admin.reports.sla', $query))->assertOk()->inertiaProps('data'), ['csat_average', 'csat_count']);

        $this->assertEquals(['csat_average' => 3.0, 'csat_count' => 3], $csat(self::JANUARY));
        $this->assertEquals(['csat_average' => 3.3, 'csat_count' => 4], $csat([]), '(1 + 5 + 3 + 4) / 4 = 3.25, written to one decimal');
        $this->assertSame(['csat_average' => null, 'csat_count' => 0], $csat(['date_from' => '2024-01-01', 'date_to' => '2024-01-31']), 'no rating is no figure, not a zero');
    }

    public function test_the_worst_late_tickets_are_listed_ten_at_most_and_only_for_who_may_list_tickets(): void
    {
        foreach (range(1, 12) as $late) {
            $deadline = Carbon::parse('2026-01-10 12:00:00')->subMinutes($late * 10)->toDateTimeString();
            $this->resolved('2026-01-10 08:00:00', null, '2026-01-10 12:00:00', $deadline, 'high', null);
        }
        // On time: never a violator.
        $this->resolved('2026-01-10 08:00:00', null, '2026-01-10 09:00:00', '2026-01-10 18:00:00', 'high', null);

        $violators = $this->get(route('admin.reports.sla', self::JANUARY))->assertOk()->inertiaProps('violators');

        $this->assertSame([120, 110, 100, 90, 80, 70, 60, 50, 40, 30], array_column($violators, 'late_minutes'), 'the ten latest, the most late first');
        $worst = Ticket::query()->where('sla_deadline', '2026-01-10 10:00:00')->sole();
        $this->assertSame(
            ['id' => $worst->public_id, 'code' => $worst->code, 'title' => $worst->title, 'category' => 'Support', 'assignee' => null, 'priority' => 'high', 'late_minutes' => 120, 'url' => route('admin.tickets.show', $worst, false)],
            $violators[0],
        );

        // report.sla.view alone opens the figures, never the rows of the ticket module.
        $reader = $this->employee(['app.web', 'report.sla.view'], 'sla-reader');
        $this->actingAs($reader);
        $props = $this->get(route('admin.reports.sla', self::JANUARY))->assertOk()->inertiaProps();
        $this->assertNull($props['violators']);
        $this->assertSame(13, array_sum(array_column($props['data']['by_priority'], 'total')), 'the figures are still there');
    }

    public function test_a_handler_sees_only_their_own_late_tickets(): void
    {
        $handler = $this->employee(['app.web', 'report.sla.view', 'ticket.view', 'ticket.start', 'ticket.resolve'], 'sla-handler');
        $this->resolved('2026-01-10 08:00:00', null, '2026-01-10 12:00:00', '2026-01-10 10:00:00', 'high', $handler->id);
        $this->resolved('2026-01-11 08:00:00', null, '2026-01-11 12:00:00', '2026-01-11 10:00:00', 'high', $this->handler('Budi')->id);
        $this->resolved('2026-01-12 08:00:00', null, '2026-01-12 12:00:00', '2026-01-12 10:00:00', 'high', null);

        $this->actingAs($handler);
        $violators = $this->get(route('admin.reports.sla', self::JANUARY))->assertOk()->inertiaProps('violators');

        $this->assertCount(1, $violators);
        $this->assertSame($handler->name, $violators[0]['assignee']);
    }

    public function test_a_manager_of_one_unit_names_only_the_technicians_of_that_unit_and_the_other_handlers_are_one_row(): void
    {
        $ops = $this->unit('OPS');
        $agus = $this->member('technician', 'Agus', $this->unit('OPS > FIELD', $ops));
        $budi = $this->member('technician', 'Budi', $this->unit('SALES'));
        $citra = $this->handler('Citra');
        $manager = $this->member('manager', 'Manajer OPS', $ops);

        $this->resolved('2026-01-12 08:00:00', null, '2026-01-12 09:00:00', '2026-01-12 10:00:00', 'medium', $agus->id);
        $this->resolved('2026-01-13 08:00:00', null, '2026-01-13 12:00:00', '2026-01-13 10:00:00', 'medium', $agus->id);
        $this->resolved('2026-01-14 08:00:00', null, '2026-01-14 12:00:00', '2026-01-14 10:00:00', 'medium', $budi->id);
        $this->resolved('2026-01-15 08:00:00', null, '2026-01-15 09:00:00', '2026-01-15 10:00:00', 'medium', $citra->id);
        $this->resolved('2026-01-16 08:00:00', null, '2026-01-16 09:00:00', '2026-01-16 10:00:00', 'medium', null);

        $this->actingAs($manager);
        $data = $this->get(route('admin.reports.sla', self::JANUARY))->assertOk()->inertiaProps('data');
        $rows = collect($data['by_technician'])->keyBy(fn (array $row): string => $row['name'] ?? '-');

        $this->assertEqualsCanonicalizing(['Agus', 'Penangan lain', '-'], $rows->keys()->all(), 'Budi works in another unit and Citra in none');
        $this->assertEquals(['name' => 'Agus', 'total' => 2, 'compliant' => 1, 'breached' => 1, 'rate' => 50, 'avg_frt_minutes' => null], $rows['Agus']);
        $this->assertEquals(['name' => 'Penangan lain', 'total' => 2, 'compliant' => 1, 'breached' => 1, 'rate' => 50, 'avg_frt_minutes' => null], $rows['Penangan lain']);
        $this->assertSame(5, array_sum(array_column($data['by_technician'], 'total')), 'the rows still add up to the cards');
        $this->assertSame(5, $data['total_resolved']);
        $this->assertStringNotContainsString('Budi', (string) json_encode($data));
        $this->assertStringNotContainsString('Citra', (string) json_encode($data));

        $figures = $this->csvFigures($this->get(route('admin.reports.sla.export', self::JANUARY)));
        $this->assertEquals(2, $figures['Tiket selesai per teknisi / Agus: total']);
        $this->assertEquals(2, $figures['Tiket selesai per teknisi / Penangan lain: total'], 'the file folds them like the screen');
        $this->assertArrayNotHasKey('Tiket selesai per teknisi / Budi: total', $figures);
        $this->assertArrayNotHasKey('Tiket selesai per teknisi / Citra: total', $figures);
    }

    public function test_a_noc_user_who_cannot_open_the_technician_report_sees_no_handler_by_name(): void
    {
        $agus = $this->handler('Agus');
        $this->resolved('2026-01-12 08:00:00', null, '2026-01-12 09:00:00', '2026-01-12 10:00:00', 'medium', $agus->id);
        $this->resolved('2026-01-13 08:00:00', null, '2026-01-13 12:00:00', '2026-01-13 10:00:00', 'medium', $agus->id);
        $this->resolved('2026-01-16 08:00:00', null, '2026-01-16 09:00:00', '2026-01-16 10:00:00', 'medium', null);

        $this->actingAs($this->member('noc', 'Petugas NOC', null));
        $data = $this->get(route('admin.reports.sla', self::JANUARY))->assertOk()->inertiaProps('data');
        $rows = collect($data['by_technician'])->keyBy(fn (array $row): string => $row['name'] ?? '-');

        $this->assertEqualsCanonicalizing(['Penangan lain', '-'], $rows->keys()->all());
        $this->assertSame([2, 1], [$rows['Penangan lain']['total'], $rows['-']['total']], 'a ticket nobody handled has no name to hide');
        $this->assertStringNotContainsString('Agus', (string) json_encode($data));
        $this->assertSame(3, $data['total_resolved']);
    }

    public function test_the_file_carries_the_splits_and_the_satisfaction_like_the_screen(): void
    {
        $agus = $this->handler('Agus');
        $this->resolved('2026-01-12 08:00:00', '2026-01-12 08:30:00', '2026-01-12 09:00:00', '2026-01-12 10:00:00', 'urgent', $agus->id, rating: 4, ratedAt: '2026-01-15 10:00:00');
        $this->resolved('2026-01-13 08:00:00', null, '2026-01-13 12:00:00', '2026-01-13 10:00:00', 'medium', $agus->id, rating: 5, ratedAt: '2026-01-16 10:00:00');

        $figures = $this->csvFigures($this->get(route('admin.reports.sla.export', self::JANUARY)));
        $records = collect($this->csvRecords($this->get(route('admin.reports.sla.export', self::JANUARY))))
            ->keyBy(fn (array $record): string => $record['Metrik'].'|'.$record['Rincian']);

        $this->assertEquals(2, $figures['Tiket selesai per teknisi / Agus: total']);
        $this->assertEquals(50, $figures['Tiket selesai per teknisi / Agus: persentase (%)']);
        $this->assertEquals(30, $figures['Tiket selesai per teknisi / Agus: rata-rata respons pertama']);
        $this->assertEquals(1, $figures['Tiket selesai per prioritas / Mendesak: total']);
        $this->assertEquals(1, $figures['Tiket selesai per prioritas / Sedang: total']);
        $this->assertEquals(2, $figures['Tiket selesai per sumber target / Target kategori tiket: total']);
        $this->assertEquals(4.5, $figures['Kepuasan pelanggan']);
        $this->assertEquals(2, $figures['Jumlah penilaian pelanggan']);
        $this->assertSame('Nilai (1-5)', $records['Kepuasan pelanggan|']['Satuan']);
        $this->assertSame('Periode: 2026-01-01 sampai 2026-01-31; menurut tanggal penilaian', $records['Kepuasan pelanggan|']['Cakupan']);
        $this->assertSame('Periode: 2026-01-01 sampai 2026-01-31', $records['Tiket selesai per teknisi|Agus: total']['Cakupan'], 'the splits follow the day the ticket was resolved');
        $this->assertSame('v1', $records['Kepuasan pelanggan|']['Versi'], 'registered, so the file names its version');
    }

    public function test_the_figures_are_registered_metrics_and_the_dictionary_lists_them(): void
    {
        $listed = collect(collect($this->get(route('admin.reports.dictionary'))->assertOk()->inertiaProps('reports'))->firstWhere('key', 'sla')['metrics'])->keyBy('key');

        $expected = [
            'by_technician' => ['Tiket selesai per teknisi', 'breakdown', 'tickets.resolved_at'],
            'by_priority' => ['Tiket selesai per prioritas', 'breakdown', 'tickets.resolved_at'],
            'by_source' => ['Tiket selesai per sumber target', 'breakdown', 'tickets.resolved_at'],
            'csat_average' => ['Kepuasan pelanggan', 'rating', 'tickets.customer_rated_at'],
            'csat_count' => ['Jumlah penilaian pelanggan', 'count', 'tickets.customer_rated_at'],
        ];
        foreach ($expected as $key => [$label, $unit, $dateColumn]) {
            $this->assertSame([$label, $unit, $dateColumn], [$listed[$key]['label'], $listed[$key]['unit'], $listed[$key]['date_column']], $key);
        }

        // The page explains each card with the same definition the dictionary holds.
        $meta = $this->get(route('admin.reports.sla'))->assertOk()->inertiaProps('meta.metrics');
        foreach (array_keys($expected) as $key) {
            $this->assertSame($listed[$key]['formula'], $meta[$key]['formula'], $key);
        }
    }

    private function handler(string $name): User
    {
        $handler = User::factory()->create(['company_id' => $this->companyId, 'is_active' => true, 'email_verified_at' => now(), 'name' => $name]);

        return $this->grantRole($handler, 'technician');
    }

    /** @param  list<string>  $permissions */
    private function employee(array $permissions, string $roleName): User
    {
        $user = User::factory()->create(['company_id' => $this->companyId, 'is_active' => true, 'email_verified_at' => now()]);

        return $this->grantRole($user, $this->companyRole($this->companyId, $roleName, $permissions));
    }

    private function unit(string $path, ?OrganizationUnit $parent = null): OrganizationUnit
    {
        return OrganizationUnit::factory()->create(['company_id' => $this->companyId, 'parent_id' => $parent?->id, 'path' => $path, 'is_active' => true]);
    }

    /** A user of the company holding a template role, on the payroll of a unit when one is named. */
    private function member(string $role, string $name, ?OrganizationUnit $unit): User
    {
        $user = User::factory()->create(['company_id' => $this->companyId, 'is_active' => true, 'email_verified_at' => now(), 'name' => $name]);
        $this->grantRole($user, $role);

        if ($unit !== null) {
            EmployeeProfile::factory()->create(['company_id' => $this->companyId, 'user_id' => $user->id, 'organization_id' => $unit->id, 'status' => 'active']);
        }

        return $user;
    }

    /** A ticket resolved at $resolvedAt against $slaDeadline, closed so that it keeps its handler's name. */
    private function resolved(string $createdAt, ?string $firstResponseAt, string $resolvedAt, ?string $slaDeadline, string $priority, ?int $assignedTo, ?int $subscriptionId = null, ?TicketCategory $category = null, ?int $rating = null, ?string $ratedAt = null): Ticket
    {
        $ticket = $this->worldTicket($this->companyId, $category ?? $this->category, $createdAt, $firstResponseAt, $resolvedAt, $slaDeadline, 'resolved', $assignedTo);
        $ticket->forceFill(['priority' => $priority, 'subscription_id' => $subscriptionId, 'customer_rating' => $rating, 'customer_rated_at' => $ratedAt])->save();

        return $ticket;
    }

    /** The package of the subscription is sold under a tier; the test names it so the figure can be found by name. */
    private function nameTierOf(ServiceSubscription $subscription, string $name): void
    {
        SLATier::withoutCompany()->whereKey(ServicePackageVersion::withoutCompany()->whereKey($subscription->service_package_version_id)->value('sla_tier_id'))->update(['name' => $name]);
    }
}
