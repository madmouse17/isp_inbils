<?php

namespace Tests\Feature\Inventory;

use App\Models\Core\Company;
use App\Models\Core\DeviceToken;
use App\Models\Core\Location;
use App\Models\User;
use App\Notifications\Channels\FcmChannel;
use App\Services\Core\CompanyMembershipService;
use App\Services\Core\CompanyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Modules\Inventory\Models\InventoryRequest;
use Modules\Inventory\Models\MaterialReturnCase;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Stock;
use Modules\Inventory\Notifications\InventoryRequestApprovedNotification;
use Modules\Inventory\Notifications\InventoryRequestReadyNotification;
use Modules\Inventory\Notifications\InventoryRequestRejectedNotification;
use Modules\Inventory\Notifications\InventoryRequestSubmittedNotification;
use Modules\Inventory\Notifications\MaterialReturnDeclaredNotification;
use Modules\Inventory\Notifications\MaterialReturnExceptionNotification;
use Modules\Inventory\Notifications\MaterialReturnRejectedNotification;
use Modules\Inventory\Services\InventoryRequestService;
use Modules\Inventory\Services\MaterialReturnService;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;
use Tests\Traits\FakesFirebaseMessaging;
use Tests\Traits\InteractsWithMobileApi;

/**
 * Who hears of a warehouse event and where: the approvers, the warehouse and whoever resolves a difference find it in
 * the bell; the technician who asked finds it in the bell and on the phone. Nothing here is mailed: mail is the daily
 * digest only (`inventory:send-digest`).
 */
class InventoryNoticeChannelsTest extends TestCase
{
    use FakesFirebaseMessaging;
    use InteractsWithMobileApi;
    use RefreshDatabase;

    private const PERMISSIONS = [
        'inventory.request.view.own', 'inventory.request.view.all', 'inventory.request.create', 'inventory.request.approve',
        'inventory.request.prepare', 'inventory.request.issue',
        'inventory.return.view.own', 'inventory.return.declare', 'inventory.return.receive', 'inventory.return.resolve',
    ];

    private Company $company;

    private Location $warehouse;

    private Product $product;

    /** Asks for material and declares what is left; has the technician app installed. */
    private User $technician;

    private User $approver;

    /** The warehouse: prepares, hands over and receives returns. */
    private User $keeper;

    private User $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeFirebase();
        foreach (self::PERMISSIONS as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $this->company = Company::factory()->create(['is_active' => true]);
        CompanyService::useCompanyForTests($this->company->id);
        $this->warehouse = Location::factory()->create(['company_id' => $this->company->id]);
        $this->product = Product::factory()->create(['company_id' => $this->company->id, 'type' => 'consumable', 'is_active' => true]);
        Stock::query()->create(['company_id' => $this->company->id, 'product_id' => $this->product->id, 'location_id' => $this->warehouse->id, 'quantity' => 100, 'reserved_quantity' => 0]);

        // A real technician login: the push job only reaches an install whose owner may use the app.
        $this->technician = $this->technicianLogin($this->company);
        $this->technician->givePermissionTo(['inventory.request.view.own', 'inventory.request.create', 'inventory.return.view.own', 'inventory.return.declare']);
        DeviceToken::factory()->create(['user_id' => $this->technician->id, 'app' => 'technician', 'token' => 'technician-install']);
        $this->approver = $this->person(['inventory.request.view.all', 'inventory.request.approve']);
        $this->keeper = $this->person(['inventory.request.prepare', 'inventory.request.issue', 'inventory.return.receive']);
        $this->resolver = $this->person(['inventory.return.resolve']);
        $this->actingAs($this->keeper);
    }

    public function test_no_inventory_notification_keeps_a_mail_channel(): void
    {
        $company = $this->company->id;
        $office = $this->person(['inventory.request.approve']);
        $bellOnly = [
            new InventoryRequestSubmittedNotification(1, 'REQ-1', $company),
            new MaterialReturnDeclaredNotification(1, null, $company, null),
            new MaterialReturnExceptionNotification(1, null, $company, null),
        ];
        $bellAndPhone = [
            new InventoryRequestApprovedNotification(1, 'REQ-1', $company),
            new InventoryRequestRejectedNotification(1, 'REQ-1', 'Stok kosong', $company),
            new InventoryRequestReadyNotification(1, 'REQ-1', $company),
            new MaterialReturnRejectedNotification(1, 'Belum diserahkan', $company, null),
        ];

        foreach ($bellOnly as $notification) {
            $this->assertSame(['database'], $notification->via($this->technician), $notification::class.' stays in the bell, even for an install');
            $this->assertSame(['database'], $notification->via($office), $notification::class);
        }

        foreach ($bellAndPhone as $notification) {
            $this->assertSame(['database', FcmChannel::class], $notification->via($this->technician), $notification::class);
            $this->assertSame(['database'], $notification->via($office), $notification::class.' without an install');
        }
    }

    public function test_request_notices_reach_the_right_people_by_bell_and_phone(): void
    {
        Notification::fake();
        $inactive = $this->person(['inventory.request.approve'], active: false);
        $outsider = $this->person(['inventory.request.approve'], Company::factory()->create(['is_active' => true]));
        $roaming = $this->person(['inventory.request.approve'], Company::factory()->create(['is_active' => true]));
        app(CompanyMembershipService::class)->grant($roaming, $this->company);
        $bell = fn (object $notification, array $channels): bool => $channels === ['database'];
        $bellAndPhone = fn (object $notification, array $channels): bool => $channels === ['database', FcmChannel::class];

        $request = $this->submittedRequest($this->technician);

        // The active members who may approve; not the person who asked, an inactive login, or another company's.
        Notification::assertSentTo([$this->approver, $roaming], InventoryRequestSubmittedNotification::class, $bell);
        Notification::assertNotSentTo([$this->technician, $this->keeper, $this->resolver, $inactive, $outsider], InventoryRequestSubmittedNotification::class);

        // An approver's own request is not announced to them: they cannot approve it.
        $this->submittedRequest($this->approver);
        Notification::assertSentToTimes($this->approver, InventoryRequestSubmittedNotification::class, 1);
        Notification::assertSentToTimes($roaming, InventoryRequestSubmittedNotification::class, 2);

        InventoryRequestService::approve($request, $this->approver);
        Notification::assertSentTo($this->technician, InventoryRequestApprovedNotification::class, $bellAndPhone);

        InventoryRequestService::prepare($request, $this->keeper);
        InventoryRequestService::markReady($request, $this->keeper);
        Notification::assertSentTo($this->technician, InventoryRequestReadyNotification::class, $bellAndPhone);

        InventoryRequestService::reject($this->submittedRequest($this->technician), $this->approver, 'Stok dialihkan ke proyek lain');
        Notification::assertSentTo($this->technician, InventoryRequestRejectedNotification::class, $bellAndPhone);
    }

    public function test_return_notices_reach_the_warehouse_and_the_technician(): void
    {
        Notification::fake();
        $bell = fn (object $notification, array $channels): bool => $channels === ['database'];
        $bellAndPhone = fn (object $notification, array $channels): bool => $channels === ['database', FcmChannel::class];
        // Holding the permission is not enough to be told of your own act: the declarer and the receiver are left out.
        $this->technician->givePermissionTo('inventory.return.receive');
        $this->keeper->givePermissionTo('inventory.return.resolve');

        $case = $this->issuedCase();
        $line = $case->lines()->firstOrFail();
        MaterialReturnService::declareUsage($case, $this->technician, ['lines' => [[
            'id' => $line->id, 'actual_used_qty' => 6, 'declared_good_return_qty' => 3, 'declared_discrepancy_qty' => 1,
            'discrepancy_code' => 'damaged', 'discrepancy_reason' => 'Kabel sobek',
        ]]]);

        Notification::assertSentTo($this->keeper, MaterialReturnDeclaredNotification::class, $bell);
        Notification::assertNotSentTo([$this->technician, $this->approver, $this->resolver], MaterialReturnDeclaredNotification::class);

        MaterialReturnService::receiveAccept($case, $this->keeper, ['lines' => [[
            'id' => $line->id, 'accepted_good_return_qty' => 3, 'damaged_return_qty' => 1, 'lost_qty' => 0, 'other_discrepancy_qty' => 0,
            'discrepancy_code' => 'damaged', 'discrepancy_reason' => 'Rusak',
        ]]]);

        Notification::assertSentTo($this->resolver, MaterialReturnExceptionNotification::class, $bell);
        Notification::assertNotSentTo([$this->keeper, $this->technician, $this->approver], MaterialReturnExceptionNotification::class);

        $refused = $this->issuedCase();
        MaterialReturnService::declareUsage($refused, $this->technician, ['lines' => [[
            'id' => $refused->lines()->firstOrFail()->id, 'actual_used_qty' => 10, 'declared_good_return_qty' => 0, 'declared_discrepancy_qty' => 0,
        ]]]);
        MaterialReturnService::receiveReject($refused, $this->keeper, 'Barang belum dibawa ke gudang');

        Notification::assertSentTo($this->technician, MaterialReturnRejectedNotification::class, $bellAndPhone);
    }

    public function test_a_difference_goes_to_the_approvers_when_nobody_may_resolve_returns(): void
    {
        Notification::fake();
        $this->resolver->revokePermissionTo('inventory.return.resolve');
        $case = $this->issuedCase();
        $line = $case->lines()->firstOrFail();
        MaterialReturnService::declareUsage($case, $this->technician, ['lines' => [[
            'id' => $line->id, 'actual_used_qty' => 9, 'declared_good_return_qty' => 0, 'declared_discrepancy_qty' => 1,
            'discrepancy_code' => 'lost', 'discrepancy_reason' => 'Hilang',
        ]]]);

        MaterialReturnService::receiveAccept($case, $this->keeper, ['lines' => [[
            'id' => $line->id, 'accepted_good_return_qty' => 0, 'damaged_return_qty' => 0, 'lost_qty' => 1, 'other_discrepancy_qty' => 0,
            'discrepancy_code' => 'lost', 'discrepancy_reason' => 'Hilang',
        ]]]);

        Notification::assertSentTo($this->approver, MaterialReturnExceptionNotification::class);
        Notification::assertNotSentTo([$this->keeper, $this->technician, $this->resolver], MaterialReturnExceptionNotification::class);
    }

    public function test_a_rejection_writes_the_bell_row_and_the_push_and_sends_no_mail(): void
    {
        $request = $this->submittedRequest($this->technician);
        $this->assertSame(['inventory_request_submitted'], $this->approver->notifications->pluck('data.type')->all());

        InventoryRequestService::reject($request, $this->approver, 'Stok dialihkan ke proyek lain');

        $row = $this->technician->notifications()->sole();
        $this->assertSame(['inventory_request_rejected', $request->public_id, 'Stok dialihkan ke proyek lain'], [$row->data['type'], $row->data['request_id'], $row->data['reason']]);
        $this->assertSame(['technician-install'], $this->pushedTokens());
        $this->assertSame(['title' => 'Permintaan barang ditolak', 'body' => "Permintaan {$request->code} ditolak."], $this->onlyPush()['notification']);
        $this->assertCount(0, app('mailer')->getSymfonyTransport()->messages());
    }

    /**
     * @param  list<string>  $permissions
     */
    private function person(array $permissions, ?Company $home = null, bool $active = true): User
    {
        $user = User::factory()->create(['company_id' => ($home ?? $this->company)->id, 'is_active' => $active]);
        $user->givePermissionTo($permissions);

        return $user;
    }

    private function submittedRequest(User $requester): InventoryRequest
    {
        return InventoryRequestService::create($requester, [
            'submit' => true,
            'warehouse_location_id' => $this->warehouse->id,
            'reason' => 'Pemasangan pelanggan baru',
            'items' => [['product_id' => $this->product->id, 'quantity_requested' => 10]],
        ]);
    }

    /** A request taken all the way to hand-over by the warehouse, so its return case waits for the technician's declaration. */
    private function issuedCase(): MaterialReturnCase
    {
        $request = $this->submittedRequest($this->technician);
        InventoryRequestService::approve($request, $this->approver);
        InventoryRequestService::prepare($request, $this->keeper);
        InventoryRequestService::markReady($request, $this->keeper);
        InventoryRequestService::issue($request, $this->keeper);

        return MaterialReturnCase::query()->where('inventory_request_id', $request->id)->firstOrFail();
    }
}
