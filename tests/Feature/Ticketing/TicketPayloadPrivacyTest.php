<?php

declare(strict_types=1);

namespace Tests\Feature\Ticketing;

use App\Models\Core\Customer;
use App\Models\Core\CustomerAddress;
use App\Models\Core\ServiceSubscription;
use App\Models\User;
use App\Services\Core\AuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\NetworkAsset\Models\NetworkAsset;
use Modules\Ticketing\Http\Resources\TicketCommentResource;
use Modules\Ticketing\Http\Resources\TicketResource;
use Modules\Ticketing\Models\Ticket;
use Modules\Ticketing\Models\TicketCategory;
use Modules\Ticketing\Models\TicketComment;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;

/**
 * What the ticket pages tell a viewer about the people on a ticket. A customer's email, tax id and notes belong to whoever
 * may open customers; a staff member's email, roles and 2FA state belong to the user pages. The ticket pages need a name
 * (and, for the dispatcher who calls, a phone number), so that is all a viewer without the permission receives.
 */
class TicketPayloadPrivacyTest extends TestCase
{
    use CreatesCompanyUser;
    use RefreshDatabase;

    private const CUSTOMER_EMAIL = 'sari.pribadi@example.test';

    private const CUSTOMER_TAX_ID = '99.888.777.6-543.210';

    private const CUSTOMER_NOTES = 'Pelanggan lama, minta dihubungi sore hari.';

    private User $admin;

    private User $reader;

    private User $portal;

    private Ticket $ticket;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createCompanyUser();
        $companyId = (int) $this->admin->company_id;

        // Sees every ticket (no start, resolve or close permission narrows the list) but may not open customers.
        $this->reader = $this->staff(null);
        $this->grantPermissions($this->reader, ['app.web', 'ticket.view']);

        $this->portal = $this->staff('customer');
        $customer = Customer::factory()->create([
            'company_id' => $companyId, 'user_id' => $this->portal->id, 'name' => 'Sari Wulandari', 'phone' => '081234567890',
            'email' => self::CUSTOMER_EMAIL, 'tax_id' => self::CUSTOMER_TAX_ID, 'contact_person' => 'Bapak Rahmat', 'notes' => self::CUSTOMER_NOTES,
        ]);
        CustomerAddress::factory()->create(['company_id' => $companyId, 'customer_id' => $customer->id, 'label' => 'Rumah', 'address' => 'Jl. Kenanga 7', 'is_installation_point' => true]);

        $category = TicketCategory::query()->create([
            'company_id' => $companyId, 'name' => 'Gangguan', 'code' => 'CAT-'.Str::random(6),
            'default_sla_hours' => 24, 'default_priority' => 'medium', 'is_active' => true,
        ]);
        $this->ticket = Ticket::query()->create([
            'company_id' => $companyId, 'code' => 'TKT-PRIV', 'title' => 'Internet mati', 'source' => 'customer', 'category_id' => $category->id,
            'status' => 'open', 'priority' => 'medium', 'customer_id' => $customer->id, 'created_by' => $this->admin->id, 'sla_deadline' => now()->addDay(),
        ]);
    }

    public function test_a_viewer_without_customer_view_gets_a_code_and_a_name_in_the_list(): void
    {
        $props = $this->props($this->reader, route('admin.tickets.index'));
        $customer = Customer::query()->sole();

        $this->assertSame(
            ['id' => $customer->public_id, 'code' => $customer->code, 'name' => 'Sari Wulandari'],
            $props['tickets']['data'][0]['customer'],
        );
        $this->assertNoCustomerRecord($props);
    }

    public function test_the_ticket_page_gives_such_a_viewer_the_account_and_the_phone_number_only(): void
    {
        $props = $this->props($this->reader, route('admin.tickets.show', $this->ticket));
        $customer = $props['ticket']['data']['customer'];

        $this->assertSame(['id', 'code', 'name', 'user_id', 'phone'], array_keys($customer));
        $this->assertSame($this->portal->public_id, $customer['user_id'], 'the page tells the customer from staff by this account');
        $this->assertSame('081234567890', $customer['phone']);
        $this->assertNoCustomerRecord($props);
    }

    public function test_a_portal_account_never_gets_the_technicians_check_in_on_the_ticket_page(): void
    {
        AuditService::log('ticket', 'check_in', [
            'status' => 'recorded', 'lat' => -6.19, 'lng' => 106.8, 'accuracy_m' => 9.0, 'captured_at' => null,
            'distance_m' => 1112, 'far' => true, 'far_reason' => 'Pelanggan minta ketemu di warung',
        ], $this->ticket);
        // Roles stack: the customer role gives portal.access and another one the web, so the customer reaches the admin page of their own ticket.
        $this->grantPermissions($this->portal, ['app.web', 'ticket.view']);

        $staff = $this->props($this->reader, route('admin.tickets.show', $this->ticket))['ticket']['data'];
        $this->assertSame('Pelanggan minta ketemu di warung', $staff['check_in']['far_reason'], 'staff read why the start was far');

        $page = $this->props($this->portal, route('admin.tickets.show', $this->ticket));
        $this->assertSame('TKT-PRIV', $page['ticket']['data']['code'], 'the customer reads their own ticket');
        $this->assertArrayNotHasKey('check_in', $page['ticket']['data']);
        $this->assertStringNotContainsString('warung', (string) json_encode($page));
    }

    public function test_a_customer_view_holder_reads_the_record_on_the_ticket_page_and_a_name_in_the_list(): void
    {
        $this->assertSame(
            ['id', 'code', 'name'],
            array_keys($this->props($this->admin, route('admin.tickets.index'))['tickets']['data'][0]['customer']),
            'the list shows a name, so it carries a name, whoever asks',
        );

        $record = $this->props($this->admin, route('admin.tickets.show', $this->ticket))['ticket']['data']['customer'];

        $this->assertSame(self::CUSTOMER_EMAIL, $record['email']);
        $this->assertSame(self::CUSTOMER_TAX_ID, $record['tax_id']);
        $this->assertSame($this->portal->public_id, $record['user_id']);
        $this->assertSame(['Jl. Kenanga 7'], array_column($record['addresses'], 'address'));
    }

    public function test_a_ticket_without_a_customer_has_none_in_either_page(): void
    {
        $this->ticket->forceFill(['customer_id' => null])->save();

        $this->assertNull($this->props($this->reader, route('admin.tickets.index'))['tickets']['data'][0]['customer']);
        $this->assertNull($this->props($this->reader, route('admin.tickets.show', $this->ticket))['ticket']['data']['customer']);
        $this->assertNull($this->props($this->admin, route('admin.tickets.show', $this->ticket))['ticket']['data']['customer']);
    }

    public function test_a_comment_author_is_an_id_and_a_name_only(): void
    {
        $technician = $this->staff('technician', 'Budi Santoso', 'budi.teknisi@example.test');
        $this->comment($technician, 'Sudah dicek di lokasi');
        $this->comment($this->portal, 'Masih mati');

        $comments = collect($this->props($this->admin, route('admin.tickets.show', $this->ticket))['ticket']['data']['comments'])->keyBy('body');

        $this->assertCount(2, $comments);
        foreach ($comments as $comment) {
            $this->assertSame(['id', 'name'], array_keys($comment['author']));
            $this->assertSame($comment['author_id'], $comment['author']['id'], 'the badge reads author_id, the name reads author');
        }
        $this->assertSame(['id' => $technician->public_id, 'name' => 'Budi Santoso'], $comments['Sudah dicek di lokasi']['author']);
        $this->assertSame($this->portal->public_id, $comments['Masih mati']['author_id']);

        $json = (string) json_encode($comments->all());
        foreach (['budi.teknisi@example.test', '"email"', '"roles"', '"two_factor_enabled"', '"last_login_at"', '"can"'] as $leak) {
            $this->assertStringNotContainsString($leak, $json, $leak);
        }
    }

    public function test_a_comment_whose_author_is_gone_has_no_author(): void
    {
        $comment = (new TicketComment(['body' => 'Halo', 'is_internal' => false]))->setRelation('author', null);

        $payload = (new TicketCommentResource($comment))->resolve(Request::create('/'));

        $this->assertNull($payload['author']);
        $this->assertNull($payload['author_id']);
    }

    public function test_the_ticket_page_costs_the_same_with_more_comments(): void
    {
        $this->comment($this->staff('technician'), 'Satu');
        $this->comment($this->portal, 'Dua');
        // The first request also warms the permission cache.
        $this->props($this->admin, route('admin.tickets.show', $this->ticket));

        $before = $this->queryCount(fn () => $this->props($this->admin, route('admin.tickets.show', $this->ticket)));
        foreach (range(1, 6) as $number) {
            // Each author is new and holds a role: the roles of every author used to be read one author at a time.
            $this->comment($this->staff($number % 2 === 0 ? 'technician' : 'manager'), "Balasan {$number}");
        }
        $after = $this->queryCount(fn () => $this->props($this->admin, route('admin.tickets.show', $this->ticket)));

        $this->assertSame($before, $after, 'eight comments cost what two comments cost');
        $this->assertCount(8, $this->props($this->admin, route('admin.tickets.show', $this->ticket))['ticket']['data']['comments']);
    }

    public function test_the_ticket_page_names_the_subscription_and_the_asset_without_embedding_their_records(): void
    {
        $customer = Customer::query()->sole();
        $subscription = ServiceSubscription::factory()->create(['company_id' => $this->admin->company_id, 'customer_id' => $customer->id, 'status' => 'active']);
        $this->ticket->forceFill(['subscription_id' => $subscription->id])->save();

        $page = $this->props($this->admin, route('admin.tickets.show', $this->ticket))['ticket']['data'];

        $this->assertSame(['id' => $subscription->public_id, 'code' => $subscription->code], $page['subscription'], 'a name to show and an id to link, not the record');
        $this->assertNull($page['network_asset']);

        $asset = (new NetworkAsset(['code' => 'AST-1', 'name' => 'Router tepi']))->forceFill(['public_id' => '01JABCDEFGHJKMNPQRSTVWXYZ0', 'serial_number' => 'SN-RAHASIA', 'ip_address' => '10.1.1.1']);
        $resource = (new TicketResource($this->ticket->setRelation('networkAsset', $asset)))->resolve(Request::create('/'));

        $this->assertSame(['id' => '01JABCDEFGHJKMNPQRSTVWXYZ0', 'code' => 'AST-1', 'name' => 'Router tepi'], $resource['network_asset']);
        $this->assertStringNotContainsString('SN-RAHASIA', (string) json_encode($resource));
    }

    /** A verified, active user of the company, holding $role when one is named. */
    private function staff(?string $role, string $name = 'Petugas', ?string $email = null): User
    {
        $user = User::factory()->create(array_filter([
            'company_id' => $this->admin->company_id, 'name' => $name, 'email' => $email, 'email_verified_at' => now(), 'is_active' => true,
        ], static fn (mixed $value): bool => $value !== null));
        if ($role !== null) {
            $this->grantRole($user, $role);
        }

        return $user;
    }

    private function comment(User $author, string $body): TicketComment
    {
        return TicketComment::query()->create(['ticket_id' => $this->ticket->id, 'author_id' => $author->id, 'body' => $body, 'is_internal' => false]);
    }

    /** @return array<string, mixed> the Inertia props of the page */
    private function props(User $as, string $url): array
    {
        return $this->actingAs($as)->get($url)->assertOk()->viewData('page')['props'];
    }

    /** Nothing the customer pages keep for whoever opens customers: the email, the tax id and the notes written about them. */
    private function assertNoCustomerRecord(array $props): void
    {
        $json = (string) json_encode($props);

        foreach ([self::CUSTOMER_EMAIL, self::CUSTOMER_TAX_ID, self::CUSTOMER_NOTES, 'Bapak Rahmat'] as $private) {
            $this->assertStringNotContainsString($private, $json, $private);
        }
    }

    /** @param  callable(): mixed  $request */
    private function queryCount(callable $request): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $request();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
