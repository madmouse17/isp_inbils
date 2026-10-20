<?php

namespace Tests\Feature\Inventory;

use App\Models\Core\Company;
use App\Models\User;
use App\Services\Core\CompanyMembershipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Inventory\Models\InventoryRequest;
use Modules\Inventory\Models\MaterialReturnCase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The one mail the warehouse sends: a daily digest of what waits for the person, only for those who switched it on in
 * their profile, only when something waits, one mail per person however many companies they work in.
 */
class InventoryDigestCommandTest extends TestCase
{
    use RefreshDatabase;

    private const PERMISSIONS = [
        'inventory.request.approve', 'inventory.request.prepare', 'inventory.request.issue',
        'inventory.return.receive', 'inventory.return.resolve',
    ];

    private Company $company;

    private User $technician;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::PERMISSIONS as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $this->company = Company::factory()->create(['is_active' => true, 'name' => 'Alpha Net']);
        $this->technician = $this->person([]);
    }

    public function test_opted_in_user_gets_one_mail_with_counts(): void
    {
        $approver = $this->person(['inventory.request.approve'], optedIn: true);
        $keeper = $this->person(['inventory.request.prepare', 'inventory.request.issue', 'inventory.return.receive'], optedIn: true);
        $resolver = $this->person(['inventory.return.resolve'], optedIn: true);
        $this->pendingWork();

        $this->artisan('inventory:send-digest')->assertSuccessful();

        $mails = $this->mails();
        $this->assertEqualsCanonicalizing([$approver->email, $keeper->email, $resolver->email], array_keys($mails));
        $this->assertSame('Ringkasan Harian Gudang: 2 menunggu Anda', $mails[$approver->email]['subject']);
        $this->assertStringContainsString('Permintaan barang menunggu persetujuan: 2', $mails[$approver->email]['body']);
        $this->assertStringNotContainsString('menunggu disiapkan', $mails[$approver->email]['body']);

        // Prepare counts the request to prepare and the one being prepared; what the person may not act on is left out.
        $this->assertSame('Ringkasan Harian Gudang: 5 menunggu Anda', $mails[$keeper->email]['subject']);
        foreach (['Permintaan barang menunggu disiapkan: 2', 'Permintaan barang siap diserahkan: 1', 'Pengembalian material menunggu diterima gudang: 2'] as $line) {
            $this->assertStringContainsString($line, $mails[$keeper->email]['body']);
        }
        $this->assertStringNotContainsString('menunggu persetujuan', $mails[$keeper->email]['body']);

        $this->assertStringContainsString('Selisih pengembalian menunggu diselesaikan: 1', $mails[$resolver->email]['body']);
        $this->assertStringContainsString(route('admin.inventory.requests.index'), $mails[$resolver->email]['body']);
    }

    public function test_nothing_pending_sends_nothing(): void
    {
        $this->person(['inventory.request.approve', 'inventory.return.receive'], optedIn: true);
        $this->request('draft');
        $this->request('issued');
        $this->request('rejected');
        $this->returnCase('awaiting_declaration');
        $this->returnCase('reconciled');

        $this->artisan('inventory:send-digest')->assertSuccessful();

        $this->assertSame([], $this->mails());
    }

    public function test_opted_out_gets_nothing(): void
    {
        $this->person(['inventory.request.approve', 'inventory.return.receive'], optedIn: false);
        $this->pendingWork();

        $this->artisan('inventory:send-digest')->assertSuccessful();

        $this->assertSame([], $this->mails());
    }

    public function test_an_inactive_login_and_another_companys_staff_get_nothing(): void
    {
        $this->person(['inventory.request.approve'], optedIn: true, active: false);
        $this->person(['inventory.request.approve'], Company::factory()->create(['is_active' => true]), optedIn: true);
        $this->pendingWork();

        $this->artisan('inventory:send-digest')->assertSuccessful();

        $this->assertSame([], $this->mails());
    }

    public function test_a_user_who_works_in_two_companies_gets_one_digest_with_both(): void
    {
        $beta = Company::factory()->create(['is_active' => true, 'name' => 'Beta Net']);
        $roaming = $this->person(['inventory.request.approve'], optedIn: true);
        app(CompanyMembershipService::class)->grant($roaming, $beta);
        $this->request('submitted');
        $this->request('submitted', $beta);
        $this->request('submitted', $beta);

        $this->artisan('inventory:send-digest')->assertSuccessful();

        $mails = $this->mails();
        $this->assertSame([$roaming->email], array_keys($mails));
        $this->assertSame('Ringkasan Harian Gudang: 3 menunggu Anda', $mails[$roaming->email]['subject']);
        $body = $mails[$roaming->email]['body'];
        $this->assertLessThan(strpos($body, 'Permintaan barang menunggu persetujuan: 1'), strpos($body, 'Alpha Net'));
        $this->assertLessThan(strpos($body, 'Permintaan barang menunggu persetujuan: 2'), strpos($body, 'Beta Net'));
    }

    /**
     * @param  list<string>  $permissions
     */
    private function person(array $permissions, ?Company $home = null, bool $optedIn = false, bool $active = true): User
    {
        $user = User::factory()->create(['company_id' => ($home ?? $this->company)->id, 'is_active' => $active]);
        $user->givePermissionTo($permissions);
        $user->forceFill(['inventory_digest' => $optedIn])->save();

        return $user;
    }

    /** Two requests wait for approval, one to prepare, one being prepared, one ready; two returns wait at the desk and one has a difference. */
    private function pendingWork(): void
    {
        foreach (['submitted', 'submitted', 'approved_reserved', 'preparing', 'ready_for_pickup', 'draft', 'issued'] as $status) {
            $this->request($status);
        }
        foreach (['awaiting_receipt', 'awaiting_receipt', 'exception_open', 'awaiting_declaration', 'resolved'] as $status) {
            $this->returnCase($status);
        }
    }

    private function request(string $status, ?Company $company = null): InventoryRequest
    {
        return InventoryRequest::query()->create([
            'company_id' => ($company ?? $this->company)->id,
            'code' => 'REQ-'.Str::upper(Str::random(8)),
            'requested_by' => $this->technician->id,
            'status' => $status,
        ]);
    }

    private function returnCase(string $status): MaterialReturnCase
    {
        return MaterialReturnCase::query()->create([
            'company_id' => $this->company->id,
            'inventory_request_id' => $this->request('issued')->id,
            'technician_id' => $this->technician->id,
            'status' => $status,
        ]);
    }

    /** @return array<string, array{subject: string, body: string}> the mails sent so far, by recipient address */
    private function mails(): array
    {
        $mails = [];
        foreach (app('mailer')->getSymfonyTransport()->messages() as $sent) {
            $email = $sent->getOriginalMessage();
            $mails[$email->getTo()[0]->getAddress()] = ['subject' => $email->getSubject(), 'body' => (string) $email->getHtmlBody()];
        }

        return $mails;
    }
}
