<?php

namespace Tests\Feature\Platform;

use App\Models\Core\Company;
use App\Models\Platform\TenantExport;
use App\Models\User;
use App\Services\Core\CompanyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;
use Tests\Traits\InteractsWithMobileApi;

/**
 * Suspend, reactivate and request deletion of a tenant (root company plus subsidiaries) from the platform panel.
 * The enforcement of a suspension (login, requests, API) already exists and has its own tests; these prove that the
 * platform actions drive it through the model events, keep the tenants apart and leave the audit trail.
 */
class TenantLifecycleTest extends TestCase
{
    use InteractsWithMobileApi;
    use RefreshDatabase;
    use TenantFixtures;

    private const REASON = 'Tagihan platform menunggak tiga bulan';

    private function suspend(Company $root, array $overrides = [], bool $confirmed = true): TestResponse
    {
        return $this->asOperator($confirmed)->post(route('platform.companies.suspend', $root), [
            'reason' => self::REASON,
            'confirmation_code' => $root->code,
            ...$overrides,
        ]);
    }

    private function reactivate(Company $root, array $overrides = []): TestResponse
    {
        return $this->asOperator(false)->post(route('platform.companies.reactivate', $root), ['reason' => 'Tagihan sudah dilunasi', ...$overrides]);
    }

    private function requestDeletion(Company $root, array $overrides = [], bool $confirmed = true): TestResponse
    {
        return $this->asOperator($confirmed)->post(route('platform.companies.deletion-request.store', $root), [
            'confirmation_code' => $root->code,
            ...$overrides,
        ]);
    }

    /**
     * A tenant the platform suspended $daysAgo days ago (root and subsidiary both), written the way the service
     * writes it.
     *
     * @return array{root: Company, child: Company, admin: User, staff: User, branch: User}
     */
    private function suspendedTenant(string $code, int $daysAgo): array
    {
        $tenant = $this->buildTenant($code, 'Tenant '.$code);

        foreach ([$tenant['root'], $tenant['child']] as $company) {
            $company->forceFill(['is_active' => false, 'suspended_at' => now()->subDays($daysAgo)])->save();
        }

        return $tenant;
    }

    /** An export of $root's tenant in the given state (ready, expired, failed, running or queued). */
    private function export(Company $root, string $state = 'ready', ?string $completedAt = null): TenantExport
    {
        $factory = TenantExport::factory();
        $factory = match ($state) {
            'ready' => $factory->ready($completedAt),
            'expired' => $factory->expired($completedAt),
            'failed' => $factory->failed(),
            'running' => $factory->running(),
            default => $factory,
        };

        return $factory->create(['company_id' => $root->id, 'requested_by_platform_admin_id' => $this->operator->id]);
    }

    /** @return list<bool> the is_active flag of each company, read fresh */
    private function states(Company ...$companies): array
    {
        return array_map(fn (Company $company): bool => (bool) $company->fresh()->is_active, $companies);
    }

    // ---------------------------------------------------------------------------------------------
    // Access
    // ---------------------------------------------------------------------------------------------

    public function test_every_lifecycle_route_is_closed_to_anyone_but_a_platform_admin_with_a_verified_second_factor(): void
    {
        $tenant = $this->buildTenant('ALP', 'Alpha Net');
        $root = $tenant['root'];
        $requests = [
            fn () => $this->post(route('platform.companies.suspend', $root), ['reason' => self::REASON, 'confirmation_code' => 'ALP']),
            fn () => $this->post(route('platform.companies.reactivate', $root), ['reason' => self::REASON]),
            fn () => $this->post(route('platform.companies.deletion-request.store', $root), ['confirmation_code' => 'ALP']),
        ];

        foreach ($requests as $request) {
            $this->asTenantVisitor();
            $request()->assertRedirect(route('platform.login'));

            $this->actingAs($tenant['admin']);
            $request()->assertRedirect(route('platform.login'));

            $this->signInPlatformAdmin($this->operator, ['platform.two_factor_verified_at' => null] + $this->platformPasswordConfirmed());
            $request()->assertRedirect(route('platform.login'));
        }

        $this->assertSame([true, true], $this->states($root, $tenant['child']));
    }

    public function test_suspend_and_deletion_request_need_a_fresh_password_confirmation_and_reactivate_does_not(): void
    {
        $active = $this->buildTenant('ALP', 'Alpha Net')['root'];
        $old = $this->suspendedTenant('OLD', 40)['root'];
        $this->export($old);

        $this->suspend($active, confirmed: false)->assertRedirect(route('platform.password.confirm'));
        $this->requestDeletion($old, confirmed: false)->assertRedirect(route('platform.password.confirm'));
        $this->assertTrue($active->fresh()->is_active);
        $this->assertNull($old->fresh()->deletion_requested_at);

        $this->reactivate($old)->assertRedirect(route('platform.companies.show', $old));
        $this->assertTrue($old->fresh()->is_active);
    }

    public function test_only_a_top_level_company_is_a_tenant_a_subsidiary_id_is_a_404(): void
    {
        $tenant = $this->buildTenant('ALP', 'Alpha Net');
        $child = $tenant['child'];

        $this->asOperator()->get(route('platform.companies.show', $child))->assertNotFound();
        $this->suspend($child, ['confirmation_code' => $child->code])->assertNotFound();
        $this->reactivate($child)->assertNotFound();
        $this->requestDeletion($child, ['confirmation_code' => $child->code])->assertNotFound();
        $this->asOperator()->post(route('platform.companies.subsidiaries.store', $child), ['name' => 'X', 'code' => 'XYZ'])->assertNotFound();
        $this->asOperator()->get('/platform/companies/not-a-number')->assertNotFound();
        $this->asOperator()->get('/platform/companies/999999')->assertNotFound();

        $this->assertSame([true, true], $this->states($tenant['root'], $child));
        $this->assertSame(0, Activity::query()->where('description', 'like', 'tenant.%')->count());
    }

    // ---------------------------------------------------------------------------------------------
    // Suspend
    // ---------------------------------------------------------------------------------------------

    public function test_suspending_deactivates_the_root_and_its_active_subsidiaries_and_leaves_other_tenants_and_inactive_rows_alone(): void
    {
        $alpha = $this->buildTenant('ALP', 'Alpha Net');
        $beta = $this->buildTenant('BET', 'Beta Link');
        $legacy = Company::factory()->create(['name' => 'Alpha Lama', 'code' => 'ALL', 'is_active' => false]);
        $legacy->forceFill(['parent_company_id' => $alpha['root']->id])->save();
        $this->travelTo('2026-10-20 09:15:00');

        $this->suspend($alpha['root'])->assertRedirect(route('platform.companies.show', $alpha['root']))->assertSessionHas('success');

        $this->assertSame([false, false, false], $this->states($alpha['root'], $alpha['child'], $legacy));
        $this->assertSame('2026-10-20 09:15:00', $alpha['root']->fresh()->suspended_at->toDateTimeString());
        $this->assertSame('2026-10-20 09:15:00', $alpha['child']->fresh()->suspended_at->toDateTimeString());
        $this->assertNull($legacy->fresh()->suspended_at, 'a row that was inactive before stays as it was: no timestamp, so reactivation will not touch it');
        $this->assertNull($alpha['root']->fresh()->deletion_requested_at);

        $this->assertSame([true, true], $this->states($beta['root'], $beta['child']));
        $this->assertNull($beta['root']->fresh()->suspended_at);
    }

    public function test_a_suspension_locks_the_tenant_out_everywhere_and_takes_its_app_tokens_but_not_another_tenants(): void
    {
        [$alphaUser] = $this->customerLogin($this->buildTenant('ALP', 'Alpha Net')['root']);
        [$betaUser] = $this->customerLogin($this->buildTenant('BET', 'Beta Link')['root']);
        $alphaToken = $this->issueToken($alphaUser, 'customer');
        $betaToken = $this->issueToken($betaUser, 'customer');
        $this->api('GET', 'me', token: $alphaToken)->assertOk();
        $alpha = Company::query()->where('code', 'ALP')->firstOrFail();

        $this->suspend($alpha)->assertRedirect();

        $this->assertSame(0, $alphaUser->tokens()->count(), 'the Company::updated hook revoked them');
        $this->assertSame(1, $betaUser->tokens()->count());
        $this->api('GET', 'me', token: $betaToken)->assertOk();
        $this->asTenantVisitor()->post('/login', ['email' => $alphaUser->email, 'password' => 'password'])
            ->assertSessionHasErrors(['email' => CompanyService::SUSPENDED_MESSAGE]);
        $this->assertGuest();
        $this->asTenantVisitor()->post('/login', ['email' => $betaUser->email, 'password' => 'password'])
            ->assertSessionDoesntHaveErrors();
        $this->assertAuthenticatedAs($betaUser);
    }

    public function test_suspending_is_audited_for_the_platform_and_shown_to_every_affected_company_as_support_access(): void
    {
        $tenant = $this->buildTenant('ALP', 'Alpha Net');
        ['root' => $root, 'child' => $child] = $tenant;

        $this->suspend($root)->assertRedirect();

        $platform = Activity::query()->where('log_name', 'platform')->where('description', 'tenant.suspended')->sole();
        $this->assertSame($this->operator::class, $platform->causer_type);
        $this->assertSame($this->operator->id, $platform->causer_id);
        $this->assertSame($root->id, $platform->subject_id);
        $this->assertSame($root->id, (int) $platform->company_id);
        $this->assertSame(self::REASON, $platform->properties['reason']);
        $this->assertSame([$root->id, $child->id], $platform->properties['affected_company_ids']);

        $support = Activity::query()->where('log_name', 'support')->where('description', 'tenant.suspended')->orderBy('company_id')->get();
        $this->assertSame([$root->id, $child->id], $support->pluck('company_id')->map(fn ($id): int => (int) $id)->sort()->values()->all());
        foreach ($support as $row) {
            $this->assertSame($this->operator::class, $row->causer_type);
            $this->assertSame(['reason' => self::REASON], Arr::except($row->properties->all(), 'company_id'));
            $this->assertStringNotContainsString($this->operator->email, json_encode($row->properties));
        }
    }

    public function test_suspending_twice_is_refused_and_changes_nothing(): void
    {
        ['root' => $root] = $this->buildTenant('ALP', 'Alpha Net');
        $this->travelTo('2026-10-20 09:15:00');
        $this->suspend($root)->assertRedirect();
        $this->travelTo('2026-10-21 09:15:00');

        $this->suspend($root)->assertSessionHasErrors(['lifecycle' => 'Perusahaan ini sudah ditangguhkan.']);

        $this->assertSame('2026-10-20 09:15:00', $root->fresh()->suspended_at->toDateTimeString());
        $this->assertSame(1, Activity::query()->where('description', 'tenant.suspended')->where('log_name', 'platform')->count());
    }

    public function test_the_suspension_form_is_validated_and_the_company_code_is_compared_exactly(): void
    {
        ['root' => $root] = $this->buildTenant('ALP', 'Alpha Net');

        $this->suspend($root, ['confirmation_code' => 'alp'])->assertSessionHasErrors(['confirmation_code' => 'Kode perusahaan yang diketik tidak sesuai.']);
        $this->suspend($root, ['confirmation_code' => ' ALP-C'])->assertSessionHasErrors('confirmation_code');
        $this->suspend($root, ['confirmation_code' => 'ALP-C'])->assertSessionHasErrors('confirmation_code');
        $this->suspend($root, ['confirmation_code' => ''])->assertSessionHasErrors('confirmation_code');
        $this->suspend($root, ['confirmation_code' => ['ALP']])->assertSessionHasErrors('confirmation_code');
        $this->suspend($root, ['reason' => ''])->assertSessionHasErrors('reason');
        $this->suspend($root, ['reason' => 'pend'])->assertSessionHasErrors('reason');
        $this->suspend($root, ['reason' => str_repeat('x', 201)])->assertSessionHasErrors('reason');

        $this->assertTrue($root->fresh()->is_active);
        $this->assertSame(0, Activity::query()->where('description', 'like', 'tenant.%')->count());

        $this->suspend($root, ['reason' => str_repeat('x', 5)])->assertSessionDoesntHaveErrors();
        $this->assertFalse($root->fresh()->is_active);
    }

    public function test_the_code_of_the_subsidiary_does_not_stand_in_for_the_tenants_code_and_the_tenants_code_is_not_another_tenants(): void
    {
        $alpha = $this->buildTenant('ALP', 'Alpha Net');
        $beta = $this->buildTenant('BET', 'Beta Link');

        $this->suspend($alpha['root'], ['confirmation_code' => $beta['root']->code])->assertSessionHasErrors('confirmation_code');

        $this->assertSame([true, true, true, true], $this->states($alpha['root'], $alpha['child'], $beta['root'], $beta['child']));
    }

    // ---------------------------------------------------------------------------------------------
    // Reactivate
    // ---------------------------------------------------------------------------------------------

    public function test_reactivating_restores_the_tenant_and_clears_a_deletion_request_but_not_a_row_that_was_inactive_before(): void
    {
        $tenant = $this->suspendedTenant('ALP', 40);
        $legacy = Company::factory()->create(['name' => 'Alpha Lama', 'code' => 'ALL', 'is_active' => false]);
        $legacy->forceFill(['parent_company_id' => $tenant['root']->id])->save();
        $tenant['root']->forceFill(['deletion_requested_at' => now()->subDay()])->save();
        $other = $this->suspendedTenant('BET', 40);

        $this->reactivate($tenant['root'])->assertRedirect(route('platform.companies.show', $tenant['root']))->assertSessionHas('success');

        $this->assertSame([true, true, false], $this->states($tenant['root'], $tenant['child'], $legacy));
        foreach ([$tenant['root'], $tenant['child']] as $company) {
            $this->assertNull($company->fresh()->suspended_at);
        }
        $this->assertNull($tenant['root']->fresh()->deletion_requested_at);
        $this->assertSame([false, false], $this->states($other['root'], $other['child']), 'another tenant stays suspended');
        $this->assertNotNull($other['root']->fresh()->suspended_at);
    }

    public function test_reactivating_lets_the_tenants_users_log_in_again(): void
    {
        [$user] = $this->customerLogin($this->buildTenant('ALP', 'Alpha Net')['root']);
        $alpha = Company::query()->where('code', 'ALP')->firstOrFail();
        $this->suspend($alpha)->assertRedirect();
        $this->asTenantVisitor()->post('/login', ['email' => $user->email, 'password' => 'password'])->assertSessionHasErrors('email');

        $this->reactivate($alpha)->assertRedirect();

        $this->asTenantVisitor()->post('/login', ['email' => $user->email, 'password' => 'password'])->assertSessionDoesntHaveErrors();
        $this->assertAuthenticatedAs($user);
    }

    public function test_reactivating_is_audited_on_both_sides_with_its_reason(): void
    {
        $tenant = $this->suspendedTenant('ALP', 5);
        $tenant['root']->forceFill(['deletion_requested_at' => now()])->save();

        $this->reactivate($tenant['root'])->assertRedirect();

        $platform = Activity::query()->where('log_name', 'platform')->where('description', 'tenant.reactivated')->sole();
        $this->assertSame($this->operator->id, $platform->causer_id);
        $this->assertSame('Tagihan sudah dilunasi', $platform->properties['reason']);
        $this->assertSame([$tenant['root']->id, $tenant['child']->id], $platform->properties['affected_company_ids']);
        $this->assertTrue($platform->properties['deletion_request_cleared']);
        $this->assertSame(
            [$tenant['root']->id, $tenant['child']->id],
            Activity::query()->where('log_name', 'support')->where('description', 'tenant.reactivated')->pluck('company_id')->map(fn ($id): int => (int) $id)->sort()->values()->all(),
        );
    }

    public function test_reactivating_an_active_tenant_is_refused_and_a_reason_is_required(): void
    {
        ['root' => $root] = $this->buildTenant('ALP', 'Alpha Net');

        $this->reactivate($root)->assertSessionHasErrors(['lifecycle' => 'Perusahaan ini sudah aktif.']);

        $suspended = $this->suspendedTenant('OLD', 3)['root'];
        $this->reactivate($suspended, ['reason' => ''])->assertSessionHasErrors('reason');
        $this->reactivate($suspended, ['reason' => 'abc'])->assertSessionHasErrors('reason');
        $this->reactivate($suspended, ['reason' => str_repeat('x', 201)])->assertSessionHasErrors('reason');
        $this->assertFalse($suspended->fresh()->is_active);
        $this->assertSame(0, Activity::query()->where('description', 'tenant.reactivated')->count());
    }

    public function test_a_root_that_was_inactive_before_the_platform_existed_can_be_reactivated(): void
    {
        $root = Company::factory()->create(['code' => 'OLD', 'is_active' => false]);

        $this->reactivate($root)->assertRedirect();

        $this->assertTrue($root->fresh()->is_active);
        $this->assertNull($root->fresh()->suspended_at);
    }

    // ---------------------------------------------------------------------------------------------
    // Deletion request
    // ---------------------------------------------------------------------------------------------

    public function test_a_deletion_request_needs_thirty_suspended_days_and_a_finished_export_after_the_suspension(): void
    {
        $this->travelTo('2026-10-20 12:00:00');
        $tenant = $this->suspendedTenant('ALP', 30);
        $root = $tenant['root'];
        $this->export($root);

        $this->requestDeletion($root)->assertRedirect(route('platform.companies.show', $root))->assertSessionHas('success');

        $this->assertSame('2026-10-20 12:00:00', $root->fresh()->deletion_requested_at->toDateTimeString());
        $this->assertFalse($root->fresh()->is_active, 'the request does not reactivate or purge anything');
        $this->assertNull($tenant['child']->fresh()->deletion_requested_at, 'only the root carries it');
    }

    public function test_a_deletion_request_is_refused_one_second_short_of_thirty_days(): void
    {
        $this->travelTo('2026-10-20 12:00:00');
        $root = $this->suspendedTenant('ALP', 30)['root'];
        $root->forceFill(['suspended_at' => now()->subDays(30)->addSecond()])->save();
        $this->export($root);

        $this->requestDeletion($root)->assertSessionHasErrors(['lifecycle' => 'Pengajuan hapus baru boleh setelah 30 hari ditangguhkan (mulai 20 Okt 2026 12:00 UTC).']);

        $this->assertNull($root->fresh()->deletion_requested_at);
    }

    public function test_a_deletion_request_needs_a_suspended_tenant(): void
    {
        $active = $this->buildTenant('ALP', 'Alpha Net')['root'];
        $this->export($active);

        $this->requestDeletion($active)->assertSessionHasErrors(['lifecycle' => 'Perusahaan belum ditangguhkan.']);

        // Inactive without a platform suspension (no timestamp): the 30 days cannot be proven.
        $legacy = Company::factory()->create(['code' => 'OLD', 'is_active' => false]);
        $this->export($legacy);
        $this->requestDeletion($legacy)->assertSessionHasErrors(['lifecycle' => 'Perusahaan belum ditangguhkan.']);

        $this->assertNull($active->fresh()->deletion_requested_at);
        $this->assertNull($legacy->fresh()->deletion_requested_at);
    }

    public function test_only_a_completed_export_of_this_tenant_after_its_suspension_counts(): void
    {
        $this->travelTo('2026-10-20 12:00:00');
        $root = $this->suspendedTenant('ALP', 45)['root'];
        $other = $this->suspendedTenant('BET', 45)['root'];
        $noExport = 'Belum ada ekspor data yang selesai setelah penangguhan.';

        $this->requestDeletion($root)->assertSessionHasErrors(['lifecycle' => $noExport]);

        $this->export($root, 'ready', now()->subDays(46)->toDateTimeString());
        $this->requestDeletion($root)->assertSessionHasErrors(['lifecycle' => $noExport]);

        $this->export($root, 'failed');
        $this->export($root, 'queued', null);
        $this->export($root, 'running', null);
        $this->export($other);
        $this->requestDeletion($root)->assertSessionHasErrors(['lifecycle' => $noExport]);
        $this->assertNull($root->fresh()->deletion_requested_at);

        $this->export($root, 'expired', now()->subDay()->toDateTimeString());
        $this->requestDeletion($root)->assertSessionDoesntHaveErrors();
        $this->assertNotNull($root->fresh()->deletion_requested_at, 'the archive may be gone: the record of a finished export is the proof');
        $this->assertNull($other->fresh()->deletion_requested_at);
    }

    public function test_every_unmet_condition_is_reported_together(): void
    {
        $this->travelTo('2026-10-20 12:00:00');
        $root = $this->suspendedTenant('ALP', 10)['root'];

        $this->requestDeletion($root)->assertSessionHasErrors('lifecycle');

        $this->assertSame([
            'Pengajuan hapus baru boleh setelah 30 hari ditangguhkan (mulai 09 Nov 2026 12:00 UTC).',
            'Belum ada ekspor data yang selesai setelah penangguhan.',
        ], session('errors')->get('lifecycle'));
    }

    public function test_a_second_deletion_request_is_refused_and_the_first_stamp_stands(): void
    {
        $this->travelTo('2026-10-20 12:00:00');
        $root = $this->suspendedTenant('ALP', 45)['root'];
        $this->export($root);
        $this->requestDeletion($root)->assertSessionDoesntHaveErrors();

        $this->travelTo('2026-10-22 08:00:00');
        $this->requestDeletion($root)->assertSessionHasErrors(['lifecycle' => 'Pengajuan hapus sudah dibuat pada 20 Okt 2026 12:00 UTC.']);

        $this->assertSame('2026-10-20 12:00:00', $root->fresh()->deletion_requested_at->toDateTimeString());
        $this->assertSame(1, Activity::query()->where('log_name', 'platform')->where('description', 'tenant.deletion_requested')->count());
    }

    public function test_a_deletion_request_is_audited_with_the_export_that_proves_it_and_needs_the_exact_code(): void
    {
        $tenant = $this->suspendedTenant('ALP', 45);
        $root = $tenant['root'];
        $export = $this->export($root);

        $this->requestDeletion($root, ['confirmation_code' => 'alp'])->assertSessionHasErrors('confirmation_code');
        $this->assertNull($root->fresh()->deletion_requested_at);

        $this->requestDeletion($root)->assertRedirect();

        $platform = Activity::query()->where('log_name', 'platform')->where('description', 'tenant.deletion_requested')->sole();
        $this->assertSame($this->operator->id, $platform->causer_id);
        $this->assertSame($root->id, (int) $platform->company_id);
        $this->assertSame($export->id, $platform->properties['export_id']);
        $support = Activity::query()->where('log_name', 'support')->where('description', 'tenant.deletion_requested')->sole();
        $this->assertSame($root->id, (int) $support->company_id);
        $this->assertSame($this->operator::class, $support->causer_type);
    }

    public function test_the_request_to_delete_is_the_only_thing_a_deletion_request_does_nothing_is_removed(): void
    {
        $tenant = $this->suspendedTenant('ALP', 45);
        $this->export($tenant['root']);
        $users = User::query()->count();

        $this->requestDeletion($tenant['root'])->assertRedirect();

        $this->assertSame($users, User::query()->count());
        $this->assertSame(2, Company::query()->count());
        $this->assertDatabaseHas('users', ['id' => $tenant['admin']->id]);
    }

    // ---------------------------------------------------------------------------------------------
    // What the detail page offers
    // ---------------------------------------------------------------------------------------------

    public function test_the_detail_page_says_which_lifecycle_actions_are_open(): void
    {
        $this->travelTo('2026-10-20 12:00:00');
        $active = $this->buildTenant('ALP', 'Alpha Net')['root'];
        $young = $this->suspendedTenant('YNG', 3)['root'];
        $ready = $this->suspendedTenant('RDY', 40)['root'];
        $this->export($ready);
        $requested = $this->suspendedTenant('REQ', 40)['root'];
        $this->export($requested);
        $requested->forceFill(['deletion_requested_at' => now()])->save();

        $expect = [
            [$active, ['can_suspend' => true, 'can_reactivate' => false, 'can_request_deletion' => false, 'deletion_blockers' => ['Perusahaan belum ditangguhkan.']]],
            [$young, ['can_suspend' => false, 'can_reactivate' => true, 'can_request_deletion' => false, 'deletion_blockers' => [
                'Pengajuan hapus baru boleh setelah 30 hari ditangguhkan (mulai 16 Nov 2026 12:00 UTC).',
                'Belum ada ekspor data yang selesai setelah penangguhan.',
            ]]],
            [$ready, ['can_suspend' => false, 'can_reactivate' => true, 'can_request_deletion' => true, 'deletion_blockers' => []]],
            [$requested, ['can_suspend' => false, 'can_reactivate' => true, 'can_request_deletion' => false, 'deletion_blockers' => ['Pengajuan hapus sudah dibuat pada 20 Okt 2026 12:00 UTC.']]],
        ];

        foreach ($expect as [$root, $lifecycle]) {
            $this->asOperator(false)->get(route('platform.companies.show', $root))->assertOk()->assertInertia(fn (Assert $page) => $page->where('lifecycle', $lifecycle));
        }
    }
}
