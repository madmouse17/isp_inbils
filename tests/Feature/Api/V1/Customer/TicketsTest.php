<?php

namespace Tests\Feature\Api\V1\Customer;

use App\Models\Core\Customer;
use App\Models\Core\CustomerAddress;
use App\Models\Core\ServiceSubscription;
use App\Models\User;
use App\Services\Core\CompanyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\NetworkAsset\Database\Factories\NetworkAssetFactory;
use Modules\Service\Database\Factories\ServicePackageFactory;
use Modules\Ticketing\Models\Ticket;
use Modules\Ticketing\Models\TicketCategory;
use Modules\Ticketing\Models\TicketComment;
use Tests\TestCase;
use Tests\Traits\InteractsWithMobileApi;

class TicketsTest extends TestCase
{
    use InteractsWithMobileApi;
    use RefreshDatabase;

    private User $user;

    private Customer $customer;

    private string $token;

    private TicketCategory $category;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->user, $this->customer] = $this->customerLogin();
        $this->token = $this->issueToken($this->user, 'customer');
        $this->category = $this->category('Support', 'high');
        $this->staff = User::factory()->create(['company_id' => $this->user->company_id, 'email_verified_at' => now(), 'is_active' => true]);
        Notification::fake();
    }

    public function test_customer_can_create_list_and_show_only_their_tickets(): void
    {
        $foreignCustomer = Customer::factory()->create(['company_id' => $this->user->company_id]);
        $foreignTicket = $this->ticket('Foreign', $foreignCustomer->id);
        $ownClosed = $this->ticket('Done', $this->customer->id, ['status' => 'resolved', 'created_at' => now()->subDay()]);

        $created = $this->api('POST', 'customer/tickets', [
            'category_id' => $this->category->public_id,
            'title' => 'Internet down',
            'source' => 'internal',
        ], $this->token)->assertCreated();

        $createdTicket = Ticket::query()->wherePublicId($created->json('data.id'))->firstOrFail();
        $this->assertSame('customer', $createdTicket->source);
        $this->assertSame($this->customer->id, $createdTicket->customer_id);
        $this->assertSame('high', $createdTicket->priority);

        $this->api('GET', 'customer/ticket-categories', token: $this->token)
            ->assertOk()
            ->assertJsonPath('data.0.id', $this->category->public_id)
            ->assertJsonMissingPath('data.0.code');

        $this->api('GET', 'customer/tickets?status=active', token: $this->token)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.title', 'Internet down');

        $this->api('GET', 'customer/tickets?status=closed', token: $this->token)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ownClosed->public_id);

        $this->api('GET', "customer/tickets/{$foreignTicket->public_id}", token: $this->token)->assertNotFound();
    }

    public function test_ticket_categories_carry_the_customer_hint_the_isp_wrote_and_null_otherwise(): void
    {
        $this->category->forceFill(['customer_hint' => 'Matikan modem 30 detik, lalu nyalakan lagi.'])->save();
        $this->category('Billing');

        $categories = $this->api('GET', 'customer/ticket-categories', token: $this->token)->assertOk()->json('data');

        $this->assertSame(['id', 'name', 'customer_hint'], array_keys($categories[0]));
        $this->assertSame(
            ['Billing' => null, 'Support' => 'Matikan modem 30 detik, lalu nyalakan lagi.'],
            collect($categories)->pluck('customer_hint', 'name')->all(),
        );
    }

    public function test_customer_detail_hides_internal_fields_and_internal_comments(): void
    {
        Storage::fake('local');
        $ticket = $this->ticket('Own', $this->customer->id, ['description' => 'Need help']);
        CompanyService::runAs((int) $this->user->company_id, function () use ($ticket): void {
            TicketComment::query()->create(['ticket_id' => $ticket->id, 'author_id' => $this->staff->id, 'body' => 'Internal', 'is_internal' => true]);
            TicketComment::query()->create(['ticket_id' => $ticket->id, 'author_id' => $this->staff->id, 'body' => 'Public', 'is_internal' => false]);
        });
        $ticket->addMedia(UploadedFile::fake()->create('note.pdf', 10, 'application/pdf'))->toMediaCollection('attachments');

        $response = $this->api('GET', "customer/tickets/{$ticket->public_id}", token: $this->token)
            ->assertOk()
            ->assertJsonPath('data.description', 'Need help')
            ->assertJsonCount(1, 'data.comments')
            ->assertJsonPath('data.comments.0.body', 'Public')
            ->assertJsonPath('data.comments.0.mine', false)
            ->assertJsonPath('data.attachments.0.file_name', 'note.pdf')
            ->assertJsonStructure(['data' => ['attachments' => [['mime_type', 'size']]]]);

        $this->assertForbiddenKeysAbsent($response->json('data'), ['assigned_to', 'created_by', 'network_asset_id', 'location_id', 'spawned_spk_id', 'is_internal']);
    }

    public function test_customer_comments_are_public_scoped_and_idempotent(): void
    {
        $ticket = $this->ticket('Own', $this->customer->id);
        $foreign = $this->ticket('Foreign', Customer::factory()->create(['company_id' => $this->user->company_id])->id);
        $key = (string) Str::uuid();

        $first = $this->api('POST', "customer/tickets/{$ticket->public_id}/comments", ['body' => 'Reply', 'is_internal' => true], $this->token, ['Idempotency-Key' => $key])
            ->assertCreated()
            ->assertJsonPath('data.body', 'Reply')
            ->assertJsonPath('data.mine', true);
        $this->api('POST', "customer/tickets/{$ticket->public_id}/comments", ['body' => 'Reply', 'is_internal' => true], $this->token, ['Idempotency-Key' => $key])
            ->assertCreated()
            ->assertHeader('Idempotent-Replayed', 'true')
            ->assertContent($first->content());

        $this->assertSame(1, $ticket->comments()->count());
        $this->assertFalse((bool) $ticket->comments()->firstOrFail()->is_internal);
        $this->api('POST', "customer/tickets/{$foreign->public_id}/comments", ['body' => 'Nope'], $this->token)->assertNotFound();
    }

    public function test_customer_create_rejects_foreign_subscription_or_network_asset(): void
    {
        $other = Customer::factory()->create(['company_id' => $this->user->company_id]);
        $foreignSubscription = $this->subscription($other);
        $foreignAsset = NetworkAssetFactory::new()->create(['company_id' => $this->user->company_id, 'customer_id' => $other->id]);

        $this->api('POST', 'customer/tickets', [
            'category_id' => $this->category->public_id,
            'title' => 'Bad sub',
            'subscription_id' => $foreignSubscription->public_id,
        ], $this->token)->assertUnprocessable()->assertJsonValidationErrors(['subscription_id']);

        $this->api('POST', 'customer/tickets', [
            'category_id' => $this->category->public_id,
            'title' => 'Bad asset',
            'network_asset_id' => $foreignAsset->public_id,
        ], $this->token)->assertUnprocessable()->assertJsonValidationErrors(['network_asset_id']);
    }

    public function test_tickets_carry_sla_deadlines_and_only_the_first_name_of_the_handler(): void
    {
        $this->staff->forceFill(['name' => 'Siti Rahayu'])->save();
        $assigned = $this->ticket('Assigned', $this->customer->id, [
            'assigned_to' => $this->staff->id,
            'response_deadline' => '2026-10-16 10:00:00',
            'sla_deadline' => '2026-10-17 10:00:00',
            'first_response_at' => '2026-10-16 09:00:00',
            'status' => 'resolved',
            'resolved_at' => '2026-10-16 12:00:00',
        ]);
        $unassigned = $this->ticket('Unassigned', $this->customer->id, ['response_deadline' => null, 'sla_deadline' => null]);

        $list = collect($this->api('GET', 'customer/tickets', token: $this->token)->assertOk()->json('data'))->keyBy('id');
        $detail = $this->api('GET', "customer/tickets/{$assigned->public_id}", token: $this->token)->assertOk();

        foreach ([$list[$assigned->public_id], $detail->json('data')] as $row) {
            $this->assertSame('Siti', $row['handler_first_name']);
            $this->assertSame(Carbon::parse('2026-10-16 10:00:00')->toJSON(), $row['response_deadline']);
            $this->assertSame(Carbon::parse('2026-10-17 10:00:00')->toJSON(), $row['sla_deadline']);
            $this->assertSame(Carbon::parse('2026-10-16 09:00:00')->toJSON(), $row['first_response_at']);
            $this->assertSame(Carbon::parse('2026-10-16 12:00:00')->toJSON(), $row['resolved_at']);
            $this->assertContains('priority', array_keys($row));
        }
        $this->assertNull($list[$unassigned->public_id]['handler_first_name']);
        $this->assertNull($list[$unassigned->public_id]['response_deadline']);
        $this->assertNull($list[$unassigned->public_id]['first_response_at']);
        $this->assertStringNotContainsString('Rahayu', $detail->getContent());
        $this->assertStringNotContainsString($this->staff->email, $detail->getContent());
        $this->assertForbiddenKeysAbsent($detail->json('data'), ['assigned_to', 'phone', 'email', 'assignee']);
    }

    private function category(string $name, string $priority = 'medium'): TicketCategory
    {
        return TicketCategory::query()->create([
            'company_id' => $this->user->company_id,
            'name' => $name,
            'code' => strtoupper($name).'-'.Str::random(6),
            'default_sla_hours' => 24,
            'default_priority' => $priority,
            'is_active' => true,
        ]);
    }

    /** @param  array<string, mixed>  $overrides */
    private function ticket(string $title, int $customerId, array $overrides = []): Ticket
    {
        return Ticket::query()->create([
            'company_id' => $this->user->company_id,
            'code' => 'TKT-'.Str::random(10),
            'title' => $title,
            'source' => 'customer',
            'category_id' => $this->category->id,
            'status' => 'open',
            'priority' => 'medium',
            'customer_id' => $customerId,
            'created_by' => $this->staff->id,
            'sla_deadline' => now()->addDay(),
            ...$overrides,
        ]);
    }

    private function subscription(Customer $customer): ServiceSubscription
    {
        return ServiceSubscription::factory()->create([
            'company_id' => $this->user->company_id,
            'customer_id' => $customer->id,
            'service_package_id' => ServicePackageFactory::new()->create(['company_id' => $this->user->company_id])->id,
            'installation_address_id' => CustomerAddress::factory()->create(['company_id' => $this->user->company_id, 'customer_id' => $customer->id])->id,
            'status' => 'active',
        ]);
    }

    /** @param  array<string, mixed>  $payload */
    private function assertForbiddenKeysAbsent(array $payload, array $keys): void
    {
        foreach ($payload as $key => $value) {
            $this->assertNotContains($key, $keys);
            if (is_array($value)) {
                $this->assertForbiddenKeysAbsent($value, $keys);
            }
        }
    }
}
