<?php

namespace Tests\Feature\Api\V1\Shared;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\User;
use App\Notifications\CustomerAccountCreated;
use App\Support\PublicId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Modules\Billing\Database\Factories\InvoiceFactory;
use Modules\Billing\Notifications\InvoiceIssuedNotification;
use Tests\TestCase;
use Tests\Traits\InteractsWithMobileApi;

class NotificationsTest extends TestCase
{
    use InteractsWithMobileApi;
    use RefreshDatabase;

    public function test_technician_lists_marks_and_reads_only_their_own_notifications(): void
    {
        $technician = $this->technicianLogin();
        $other = $this->technicianLogin(Company::query()->findOrFail($technician->company_id));
        $token = $this->issueToken($technician, 'technician');

        $ticketId = PublicId::new();
        $owned = $this->notification($technician, ['type' => 'ticket_assigned', 'ticket_id' => $ticketId, 'ticket_code' => 'TKT-101']);
        $otherNotification = $this->notification($other, ['type' => 'ticket_assigned', 'ticket_id' => PublicId::new(), 'ticket_code' => 'TKT-202']);
        $alreadyRead = $this->notification($technician, ['type' => 'invoice_issued', 'invoice_id' => PublicId::new()], readAt: now());

        $response = $this->api('GET', 'notifications?unread_only=1&per_page=10', token: $token)
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', (string) $owned->id)
            ->assertJsonPath('data.0.subject', ['type' => 'ticket', 'id' => $ticketId]);

        $this->assertSame(['id', 'type', 'title', 'message', 'subject', 'created_at', 'read_at'], array_keys($response->json('data.0')));

        $this->api('POST', 'notifications/'.$otherNotification->id.'/read', token: $token)->assertNotFound();
        $this->api('POST', 'notifications/'.$owned->id.'/read', token: $token)->assertNoContent();
        $this->assertNotNull($owned->fresh()->read_at);
        $this->assertNull($otherNotification->fresh()->read_at);

        $this->api('POST', 'notifications/read-all', token: $token)->assertNoContent();
        $this->assertNotNull($alreadyRead->fresh()->read_at);
        $this->assertNull($otherNotification->fresh()->read_at);
    }

    public function test_customer_inbox_uses_the_resolved_customer_not_the_linked_user(): void
    {
        [$user, $customer] = $this->customerLogin();
        $token = $this->issueToken($user, 'customer');
        $invoiceId = PublicId::new();
        $customerNotification = $this->notification($customer, ['type' => 'invoice_issued', 'invoice_id' => $invoiceId]);
        $this->notification($user, ['type' => 'ticket_assigned', 'ticket_id' => PublicId::new()]);

        $this->api('GET', 'notifications', token: $token)
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', (string) $customerNotification->id)
            ->assertJsonPath('data.0.title', 'Tagihan baru')
            ->assertJsonPath('data.0.subject', ['type' => 'invoice', 'id' => $invoiceId]);
    }

    public function test_whatsapp_customer_events_write_ids_only_database_rows_for_linked_customers(): void
    {
        [$user, $customer] = $this->customerLogin();
        $customer->forceFill(['phone' => '0812-3456-7890'])->save();
        $invoice = InvoiceFactory::new()->create([
            'company_id' => $customer->company_id,
            'customer_id' => $customer->id,
            'number' => 'INV-2026-0001',
            'status' => 'sent',
            'total' => 250000,
            'paid_amount' => 0,
        ]);

        Notification::sendNow($customer, new InvoiceIssuedNotification((int) $customer->company_id, $invoice->id));

        $row = $customer->notifications()->sole();
        $this->assertEqualsCanonicalizing(['type', 'invoice_id', 'invoice_number', 'total', 'outstanding', 'due_date'], array_keys($row->data), 'the number, the amounts and the date say what the bell says; nothing else');
        $this->assertEquals(['type' => 'invoice_issued', 'invoice_id' => $invoice->public_id, 'invoice_number' => 'INV-2026-0001'], array_intersect_key($row->data, ['type' => 1, 'invoice_id' => 1, 'invoice_number' => 1]));
        foreach ([$customer->phone, $customer->name] as $private) {
            $this->assertStringNotContainsString((string) $private, json_encode($row->data, JSON_THROW_ON_ERROR));
        }

        $unlinked = Customer::factory()->create(['company_id' => $customer->company_id, 'user_id' => null]);
        Notification::sendNow($unlinked, new InvoiceIssuedNotification((int) $customer->company_id, $invoice->id));
        $this->assertSame(0, $unlinked->notifications()->count());

        Notification::sendNow($user, new CustomerAccountCreated((int) $customer->company_id));
        $this->assertSame(1, $customer->notifications()->count(), 'Access-link notices never use the database channel.');
    }

    public function test_subject_is_derived_from_known_payload_ids(): void
    {
        $technician = $this->technicianLogin();
        $token = $this->issueToken($technician, 'technician');
        [$workOrder, $invoice, $request, $returnCase] = [PublicId::new(), PublicId::new(), PublicId::new(), PublicId::new()];
        foreach ([
            ['work_order_id' => $workOrder, 'subject' => ['type' => 'work_order', 'id' => $workOrder]],
            ['invoice_id' => $invoice, 'subject' => ['type' => 'invoice', 'id' => $invoice]],
            ['request_id' => $request, 'subject' => ['type' => 'inventory_request', 'id' => $request]],
            ['case_id' => $returnCase, 'subject' => ['type' => 'material_return', 'id' => $returnCase]],
            ['unknown_id' => 6, 'subject' => null],
            ['ticket_id' => 7, 'subject' => null],
        ] as $case) {
            $data = $case;
            unset($data['subject']);
            $this->notification($technician, ['type' => 'test', ...$data]);
        }

        $subjects = collect($this->api('GET', 'notifications?per_page=50', token: $token)->assertOk()->json('data'))
            ->pluck('subject')
            ->values();

        foreach ([
            ['type' => 'work_order', 'id' => $workOrder],
            ['type' => 'invoice', 'id' => $invoice],
            ['type' => 'inventory_request', 'id' => $request],
            ['type' => 'material_return', 'id' => $returnCase],
            null,
        ] as $subject) {
            $this->assertTrue($subjects->contains($subject), 'Missing subject '.json_encode($subject));
        }
    }

    /** @param array<string, mixed> $data */
    private function notification(User|Customer $notifiable, array $data, mixed $readAt = null): DatabaseNotification
    {
        return $notifiable->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => 'test-notification',
            'data' => $data,
            'read_at' => $readAt,
        ])->fresh();
    }
}
