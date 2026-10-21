<?php

namespace Tests\Feature\SPK;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\User;
use App\Services\Core\CompanyService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Modules\SPK\Models\WorkOrder;
use Modules\SPK\Notifications\WorkOrderScheduledNotification;
use Modules\SPK\Services\SpkService;
use Modules\Ticketing\Models\Ticket;
use Modules\Ticketing\Models\TicketCategory;
use Modules\Ticketing\Services\TicketService;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;
use Tests\Traits\InteractsWithMobileApi;
use Tests\Traits\WithoutAppOnlyRedirect;

/**
 * K16: the dispatcher gives the visit day with the assignment. It is a day on the company's calendar that is not past, it
 * rides on the assignment of the SPK page and of the Penugasan queue alike, and the success message names the SPK and the
 * person, once.
 */
class WorkOrderScheduleTest extends TestCase
{
    use CreatesCompanyUser;
    use InteractsWithMobileApi;
    use RefreshDatabase;
    use WithoutAppOnlyRedirect;

    private User $admin;

    private User $technician;

    private Company $company;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->admin = $this->createCompanyUser();
        $this->company = Company::query()->findOrFail($this->admin->company_id);
        $this->technician = $this->technicianLogin($this->company, ['name' => 'Budi Teknisi']);
    }

    public function test_the_assignment_saves_the_visit_day_and_the_message_names_the_spk_and_the_technician(): void
    {
        $spk = $this->spk('generated');
        $visit = $this->companyDay(3);

        $this->actingAs($this->admin)
            ->post(route('admin.spk.assign', $spk), $this->assignBody($spk, ['scheduled_date' => $visit]))
            ->assertRedirect()
            ->assertSessionHas('success', "{$spk->code} ditugaskan ke Budi Teknisi.");

        $fresh = $spk->fresh();
        $this->assertSame(['assigned', $this->technician->id, $visit], [$fresh->status, $fresh->assigned_to, $fresh->scheduled_date->toDateString()]);
    }

    public function test_an_assignment_without_a_day_keeps_the_day_the_spk_has(): void
    {
        $kept = $this->companyDay(5);
        $spk = $this->spk('generated', ['scheduled_date' => $kept]);

        $this->actingAs($this->admin)
            ->post(route('admin.spk.assign', $spk), $this->assignBody($spk, ['scheduled_date' => null]))
            ->assertRedirect();

        $this->assertSame($kept, $spk->fresh()->scheduled_date->toDateString());
    }

    public function test_a_past_day_is_refused_on_the_field_and_nothing_is_assigned(): void
    {
        $spk = $this->spk('generated');

        $this->actingAs($this->admin)
            ->post(route('admin.spk.assign', $spk), $this->assignBody($spk, ['scheduled_date' => $this->companyDay(-1)]))
            ->assertSessionHasErrors(['scheduled_date' => 'Tanggal kunjungan tidak boleh sebelum hari ini.']);

        $this->post(route('admin.spk.assign', $spk), $this->assignBody($spk, ['scheduled_date' => 'besok']))
            ->assertSessionHasErrors('scheduled_date');

        $this->assertSame(['generated', null], [$spk->fresh()->status, $spk->fresh()->assigned_to]);
    }

    public function test_the_earliest_day_is_today_on_the_companys_calendar_not_the_servers(): void
    {
        // Kiritimati is UTC+14: at 20:00 UTC on the 21st it is 10:00 on the 22nd there, while the app clock still says the 21st.
        Company::query()->whereKey($this->company->id)->update(['timezone' => 'Pacific/Kiritimati']);
        CompanyService::resetCache();
        CompanyService::useCompanyForTests($this->company->id);
        $this->travelTo(CarbonImmutable::parse('2026-10-21 20:00:00', 'UTC'));
        $spk = $this->spk('generated');

        $this->assertSame('2026-10-22', SpkService::earliestVisitDate());

        $this->actingAs($this->admin)
            ->post(route('admin.spk.assign', $spk), $this->assignBody($spk, ['scheduled_date' => '2026-10-21']))
            ->assertSessionHasErrors('scheduled_date');

        $this->post(route('admin.spk.assign', $spk), $this->assignBody($spk, ['scheduled_date' => '2026-10-22']))
            ->assertSessionHasNoErrors();

        $this->assertSame('2026-10-22', $spk->fresh()->scheduled_date->toDateString());
    }

    public function test_the_customer_hears_of_the_visit_only_when_the_assignment_brings_a_day(): void
    {
        $customer = Customer::factory()->create(['company_id' => $this->company->id]);
        $withDay = $this->spk('generated', ['customer_id' => $customer->id]);
        $withoutDay = $this->spk('generated', ['customer_id' => $customer->id]);

        $this->actingAs($this->admin)->post(route('admin.spk.assign', $withoutDay), $this->assignBody($withoutDay))->assertRedirect();
        Notification::assertNotSentTo($customer, WorkOrderScheduledNotification::class);

        $this->post(route('admin.spk.assign', $withDay), $this->assignBody($withDay, ['scheduled_date' => $this->companyDay(2)]))->assertRedirect();
        Notification::assertSentTo($customer, WorkOrderScheduledNotification::class, fn (WorkOrderScheduledNotification $notice): bool => $notice->audience === 'customer' && $notice->workOrderId === $withDay->id);
    }

    public function test_the_queue_assigns_an_spk_with_the_day_and_names_it_in_the_message(): void
    {
        $spk = $this->spk('generated');
        $visit = $this->companyDay(4);

        $this->actingAs($this->admin)
            ->post(route('admin.dispatch.assign'), $this->queueBody($spk, 'spk', ['scheduled_date' => $visit]))
            ->assertRedirect()
            ->assertSessionHas('success', "{$spk->code} ditugaskan ke Budi Teknisi.");

        $this->assertSame([$this->technician->id, $visit], [$spk->fresh()->assigned_to, $spk->fresh()->scheduled_date->toDateString()]);
    }

    public function test_the_queue_refuses_a_past_day_for_an_spk(): void
    {
        $spk = $this->spk('generated');

        $this->actingAs($this->admin)
            ->post(route('admin.dispatch.assign'), $this->queueBody($spk, 'spk', ['scheduled_date' => $this->companyDay(-2)]))
            ->assertSessionHasErrors(['scheduled_date' => 'Tanggal kunjungan tidak boleh sebelum hari ini.']);

        $this->assertNull($spk->fresh()->assigned_to);
    }

    public function test_a_ticket_has_no_visit_day_so_the_queue_ignores_it_and_names_the_ticket(): void
    {
        $ticket = $this->ticket();

        $this->actingAs($this->admin)
            ->post(route('admin.dispatch.assign'), $this->queueBody($ticket, 'ticket', ['scheduled_date' => $this->companyDay(-7)]))
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', "{$ticket->code} ditugaskan ke Budi Teknisi.");

        $this->assertSame($this->technician->id, $ticket->fresh()->assigned_to);
    }

    /** The company's calendar day `$offset` days from today, as `Y-m-d`. */
    private function companyDay(int $offset): string
    {
        return now($this->company->timezone ?: config('app.timezone'))->addDays($offset)->toDateString();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function spk(string $status, array $attributes = []): WorkOrder
    {
        return WorkOrder::forceCreate($attributes + [
            'company_id' => $this->company->id,
            'code' => 'SPK-2026-'.str_pad((string) ++$this->sequence, 5, '0', STR_PAD_LEFT),
            'type' => 'maintenance',
            'title' => 'Ganti kabel',
            'status' => $status,
            'source' => 'manual',
            'priority' => 'medium',
            'created_by' => $this->admin->id,
            'assigned_to' => null,
        ]);
    }

    private function ticket(): Ticket
    {
        $category = TicketCategory::create([
            'company_id' => $this->company->id,
            'name' => 'Internet mati',
            'code' => 'MATI',
            'default_sla_hours' => 24,
            'default_priority' => 'medium',
            'is_active' => true,
        ]);

        return TicketService::create([
            'title' => 'Internet mati',
            'description' => 'Tidak bisa tersambung',
            'source' => 'internal',
            'category_id' => $category->id,
            'priority' => 'medium',
        ], $this->admin->id);
    }

    private function version(WorkOrder|Ticket $record): string
    {
        return $record->fresh()->updated_at->utc()->format('Y-m-d\\TH:i:s.v\\Z');
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function assignBody(WorkOrder $spk, array $extra = []): array
    {
        return [
            'technician_id' => $this->technician->public_id,
            'expected_assigned_to' => null,
            'expected_updated_at' => $this->version($spk),
            ...$extra,
        ];
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function queueBody(WorkOrder|Ticket $record, string $domain, array $extra = []): array
    {
        return [
            'domain' => $domain,
            'id' => $record->public_id,
            'assignee_id' => $this->technician->public_id,
            'expected_assigned_to' => null,
            'expected_updated_at' => $this->version($record),
            ...$extra,
        ];
    }
}
