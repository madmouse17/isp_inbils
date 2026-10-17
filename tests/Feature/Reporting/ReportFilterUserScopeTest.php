<?php

namespace Tests\Feature\Reporting;

use App\Models\Core\Company;
use App\Models\User;
use App\Support\PublicId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Reporting\Queries\AuditLogQuery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Traits\AsksInertiaForProps;
use Tests\Traits\BuildsCompanyGroup;

/**
 * The people a report filter may name are the ones the company's own pickers offer: the audit log's actor is a member of
 * the company (home or granted), a sales acquirer is a user of it. Anyone else is a validation error before any query
 * runs, and the answer is the same for another company's user and for an id nobody has.
 */
class ReportFilterUserScopeTest extends TestCase
{
    use AsksInertiaForProps;
    use BuildsCompanyGroup;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildGroup();
        $this->admin = $this->groupUser($this->subA, 'admin');
    }

    public function test_the_audit_log_filters_by_a_member_who_is_homed_in_another_company(): void
    {
        $guest = $this->groupUser($this->head, 'manager', $this->subA);
        $guest->forceFill(['name' => 'Tamu Cabang'])->save();
        $this->activity($this->subA, $guest, 'guest_event_in_a');
        $this->activity($this->subA, $this->admin, 'admin_event_in_a');
        $this->activity($this->head, $guest, 'guest_event_at_head');

        $this->actingAs($this->admin);

        // The picker offers the guest...
        $this->get(route('admin.reports.audit-log', ['user_search' => 'Tamu']), $this->partialReload('Admin/Reports/AuditLog', 'userOptions'))
            ->assertOk()
            ->assertJsonPath('props.userOptions.0.value', $guest->public_id);

        // ...so choosing the guest must list their activity of this company, on the page and in the export.
        $descriptions = $this->get(route('admin.reports.audit-log', ['user_id' => $guest->public_id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('selectedUser.value', $guest->public_id))
            ->inertiaProps('data.data');
        $this->assertSame(['guest_event_in_a'], array_column($descriptions, 'description'));

        $export = $this->get(route('admin.reports.audit-log.export', ['user_id' => $guest->public_id]))->assertOk()->streamedContent();
        $this->assertStringContainsString('guest_event_in_a', $export);
        $this->assertStringNotContainsString('admin_event_in_a', $export);
        $this->assertStringNotContainsString('guest_event_at_head', $export);
    }

    public function test_the_audit_log_search_finds_the_activity_of_a_member_homed_in_another_company_by_name(): void
    {
        $guest = $this->groupUser($this->head, 'manager', $this->subA);
        $guest->forceFill(['name' => 'Tamu Cabang'])->save();
        $this->activity($this->subA, $guest, 'guest_event_in_a');
        $this->activity($this->subA, $this->admin, 'admin_event_in_a');
        $this->activity($this->head, $guest, 'guest_event_at_head');

        $found = $this->actingAs($this->admin)
            ->get(route('admin.reports.audit-log', ['search' => 'Tamu Cabang']))
            ->assertOk()
            ->inertiaProps('data.data');

        $this->assertSame(['guest_event_in_a'], array_column($found, 'description'));
    }

    public function test_the_audit_log_refuses_a_user_who_is_not_a_member_in_the_same_words_as_an_unknown_one(): void
    {
        $outsider = $this->groupUser($this->outsider);
        $sibling = $this->groupUser($this->subB);
        $this->activity($this->subA, $outsider, 'outsider_event');

        $this->actingAs($this->admin);

        foreach ([$outsider->public_id, $sibling->public_id, PublicId::new(), (string) $outsider->id] as $userId) {
            $this->getJson(route('admin.reports.audit-log', ['user_id' => $userId]))
                ->assertUnprocessable()
                ->assertJsonPath('errors.user_id', ['Pengguna tidak ditemukan.']);
            $this->getJson(route('admin.reports.audit-log.export', ['user_id' => $userId]))
                ->assertUnprocessable()
                ->assertJsonPath('errors.user_id', ['Pengguna tidak ditemukan.']);
        }
    }

    public function test_the_audit_log_query_itself_stays_closed_to_a_user_who_is_not_a_member(): void
    {
        $outsider = $this->groupUser($this->outsider);
        $this->activity($this->subA, $outsider, 'outsider_event');

        $this->actingAs($this->admin);

        $this->assertSame(0, AuditLogQuery::paginate(userId: $outsider->id)->total());
    }

    /** @return array<string, array{0: string}> */
    public static function notAcquirers(): array
    {
        return ['zero' => ['0'], 'an integer id' => ['999999'], 'an unknown public id' => ['01arz3ndektsv4rrffq69g5fav']];
    }

    #[DataProvider('notAcquirers')]
    public function test_the_sales_report_refuses_an_acquirer_nobody_has(string $acquirerId): void
    {
        $this->actingAs($this->admin)
            ->getJson(route('admin.reports.sales', ['acquirer_id' => $acquirerId]))
            ->assertUnprocessable()
            ->assertJsonPath('errors.acquirer_id', ['Petugas penjualan tidak ditemukan.']);
    }

    public function test_the_sales_report_refuses_an_acquirer_of_another_company_in_the_same_words(): void
    {
        $foreign = $this->groupUser($this->outsider, 'manager');

        $this->actingAs($this->admin)
            ->getJson(route('admin.reports.sales', ['acquirer_id' => $foreign->public_id]))
            ->assertUnprocessable()
            ->assertJsonPath('errors.acquirer_id', ['Petugas penjualan tidak ditemukan.']);
    }

    public function test_a_user_of_the_company_who_is_no_acquirer_is_still_forbidden_by_the_report_itself(): void
    {
        $technician = $this->groupUser($this->subA, 'technician');

        $this->actingAs($this->admin)
            ->getJson(route('admin.reports.sales', ['acquirer_id' => $technician->public_id]))
            ->assertForbidden();
    }

    private function activity(Company $company, User $causer, string $description): void
    {
        DB::table('activity_log')->insert([
            'public_id' => PublicId::new(),
            'company_id' => $company->id,
            'log_name' => 'billing',
            'description' => $description,
            'causer_type' => User::class,
            'causer_id' => $causer->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
