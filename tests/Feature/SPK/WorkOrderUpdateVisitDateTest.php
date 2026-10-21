<?php

declare(strict_types=1);

namespace Tests\Feature\SPK;

use App\Models\Core\Company;
use App\Models\User;
use App\Services\Core\CompanyService;
use App\Support\OptimisticLock;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\SPK\Database\Factories\WorkOrderFactory;
use Modules\SPK\Models\WorkOrder;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;

/**
 * K16 on the edit page: the visit day of an SPK is a day on the company's calendar that is not past, as in the assign dialog,
 * the Penugasan queue and customer registration. The day the SPK already carries is not "moved" by a save that leaves it alone.
 */
class WorkOrderUpdateVisitDateTest extends TestCase
{
    use CreatesCompanyUser;
    use RefreshDatabase;

    private const REFUSED = 'Tanggal kunjungan tidak boleh sebelum hari ini.';

    private User $admin;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createCompanyUser();
        $this->company = Company::query()->findOrFail($this->admin->company_id);
    }

    /** The company's calendar day `$offset` days from today, as `Y-m-d`. */
    private function companyDay(int $offset): string
    {
        return now($this->company->timezone ?: config('app.timezone'))->addDays($offset)->toDateString();
    }

    private function plan(?string $day = null): WorkOrder
    {
        return WorkOrderFactory::new()->create([
            'company_id' => $this->company->id, 'type' => 'maintenance', 'status' => 'generated', 'title' => 'Ganti kabel',
            'scheduled_date' => $day, 'created_by' => $this->admin->id, 'assigned_to' => null,
        ]);
    }

    /** @return array<string, mixed> */
    private function save(WorkOrder $workOrder, ?string $day, string $title = 'Ganti kabel'): array
    {
        return ['title' => $title, 'scheduled_date' => $day, OptimisticLock::FIELD => OptimisticLock::version($workOrder->fresh())];
    }

    public function test_a_past_day_is_refused_on_the_field_and_nothing_is_saved(): void
    {
        $workOrder = $this->plan();

        $this->actingAs($this->admin)
            ->put(route('admin.spk.update', $workOrder), $this->save($workOrder, $this->companyDay(-1), 'Judul baru'))
            ->assertSessionHasErrors(['scheduled_date' => self::REFUSED]);

        $this->put(route('admin.spk.update', $workOrder), $this->save($workOrder, 'besok'))->assertSessionHasErrors('scheduled_date');

        $saved = $workOrder->fresh();
        $this->assertSame(['Ganti kabel', null], [$saved->title, $saved->scheduled_date]);
    }

    public function test_today_and_a_later_day_are_saved(): void
    {
        $workOrder = $this->plan();

        foreach ([$this->companyDay(0), $this->companyDay(3)] as $day) {
            $this->actingAs($this->admin)
                ->put(route('admin.spk.update', $workOrder), $this->save($workOrder, $day))
                ->assertSessionHasNoErrors()
                ->assertRedirect(route('admin.spk.index'));

            $this->assertSame($day, $workOrder->fresh()->scheduled_date->toDateString());
        }
    }

    public function test_the_earliest_day_is_today_on_the_companys_calendar_not_the_servers(): void
    {
        // Kiritimati is UTC+14: at 20:00 UTC on the 21st it is 10:00 on the 22nd there, while the app clock still says the 21st.
        Company::query()->whereKey($this->company->id)->update(['timezone' => 'Pacific/Kiritimati']);
        CompanyService::resetCache();
        CompanyService::useCompanyForTests($this->company->id);
        $this->travelTo(CarbonImmutable::parse('2026-10-21 20:00:00', 'UTC'));
        $workOrder = $this->plan();

        $this->actingAs($this->admin)->put(route('admin.spk.update', $workOrder), $this->save($workOrder, '2026-10-21'))->assertSessionHasErrors(['scheduled_date' => self::REFUSED]);
        $this->put(route('admin.spk.update', $workOrder), $this->save($workOrder, '2026-10-22'))->assertSessionHasNoErrors();

        $this->assertSame('2026-10-22', $workOrder->fresh()->scheduled_date->toDateString());
    }

    public function test_a_day_the_spk_already_has_stays_while_it_is_not_moved(): void
    {
        $gone = $this->companyDay(-2);
        $workOrder = $this->plan($gone);

        // The edit page sends the day back untouched: other changes are saved.
        $this->actingAs($this->admin)
            ->put(route('admin.spk.update', $workOrder), $this->save($workOrder, $gone, 'Judul baru'))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.spk.index'));
        $this->assertSame(['Judul baru', $gone], [$workOrder->fresh()->title, $workOrder->fresh()->scheduled_date->toDateString()]);

        // Another day in the past is a move into the past, and so is.
        $this->put(route('admin.spk.update', $workOrder), $this->save($workOrder, $this->companyDay(-1)))->assertSessionHasErrors(['scheduled_date' => self::REFUSED]);
        $this->assertSame($gone, $workOrder->fresh()->scheduled_date->toDateString());

        // The day may be cleared.
        $this->put(route('admin.spk.update', $workOrder), $this->save($workOrder, null))->assertSessionHasNoErrors();
        $this->assertNull($workOrder->fresh()->scheduled_date);
    }
}
