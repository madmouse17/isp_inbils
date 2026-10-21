<?php

namespace Tests\Feature\Billing;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\Core\WhatsAppMessage;
use App\Models\User;
use App\Services\Core\CompanyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Testing\Fakes\NotificationFake;
use Illuminate\Testing\TestResponse;
use InvalidArgumentException;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Notifications\InvoiceDueReminderNotification;
use Modules\Billing\Services\BillingService;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;

/**
 * F5-U03: "Kirim pengingat" from Tunggakan. The customer gets the due reminder now, at most once per invoice per company
 * day, whoever presses the button, and the audit says it was sent. Time stands at midday of 21 October 2026 in Jakarta.
 */
class InvoiceManualReminderTest extends TestCase
{
    use CreatesCompanyUser;
    use RefreshDatabase;

    private User $admin;

    private Customer $customer;

    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-21 05:00:00');
        $this->admin = $this->createCompanyUser();
        $this->customer = Customer::factory()->create(['company_id' => $this->admin->company_id]);
        $this->invoice = $this->invoice();
    }

    /** @param array<string, mixed> $attributes */
    private function invoice(array $attributes = []): Invoice
    {
        return Invoice::factory()->create([
            'company_id' => $this->admin->company_id,
            'customer_id' => $this->customer->id,
            'status' => 'sent',
            'due_date' => '2026-10-24',
            'total' => 100000,
            'paid_amount' => 0,
            ...$attributes,
        ]);
    }

    private function remind(Invoice $invoice): TestResponse
    {
        return $this->actingAs($this->admin)->post(route('admin.invoices.remind', $invoice));
    }

    private function key(string $day = '2026-10-21'): string
    {
        return BillingService::reminderKey((int) $this->admin->company_id, $this->invoice->id, $day);
    }

    private function reminders(): int
    {
        return Activity::query()->where('log_name', 'invoice')->where('description', 'reminder_sent')->count();
    }

    public function test_the_customer_gets_the_manual_reminder_and_the_audit_names_the_channels_not_the_content(): void
    {
        Notification::fake();

        $this->remind($this->invoice)->assertRedirect()->assertSessionHas('success', "Pengingat dikirim untuk {$this->invoice->number}.");

        Notification::assertSentToTimes($this->customer, InvoiceDueReminderNotification::class, 1);
        Notification::assertSentTo($this->customer, InvoiceDueReminderNotification::class, fn (InvoiceDueReminderNotification $sent): bool => $sent->stage === 'manual'
            && $sent->invoiceId === $this->invoice->id
            && $sent->companyId === (int) $this->admin->company_id);

        $row = Activity::query()->where('log_name', 'invoice')->where('description', 'reminder_sent')->sole();
        $this->assertSame($this->invoice->id, $row->subject_id);
        $this->assertSame($this->admin->id, $row->causer_id);
        $this->assertSame($this->invoice->number, $row->properties['number']);
        $this->assertSame(['whatsapp'], $row->properties['channels'], 'a customer without the app is reached by WhatsApp only');
        $this->assertSame(['number', 'channels'], array_keys($row->properties->all()));
    }

    public function test_a_customer_with_the_app_is_reached_by_its_bell_too(): void
    {
        Notification::fake();
        $this->customer->forceFill(['user_id' => User::factory()->create(['company_id' => $this->admin->company_id])->id])->save();

        $this->remind($this->invoice)->assertRedirect();

        $this->assertSame(['whatsapp', 'database'], Activity::query()->where('description', 'reminder_sent')->sole()->properties['channels']);
    }

    public function test_a_second_reminder_the_same_day_is_refused_for_every_kind_of_client_and_sends_nothing(): void
    {
        Notification::fake();
        $message = "Pengingat untuk {$this->invoice->number} sudah dikirim hari ini.";

        $this->remind($this->invoice)->assertSessionHas('success');

        $this->actingAs($this->admin)->postJson(route('admin.invoices.remind', $this->invoice))->assertStatus(429)->assertExactJson(['message' => $message]);
        $this->post(route('admin.invoices.remind', $this->invoice))->assertStatus(429);
        // A page visit keeps its page and shows the message as a toast.
        $this->withHeaders(['X-Inertia' => 'true'])->post(route('admin.invoices.remind', $this->invoice))->assertRedirect()->assertSessionHas('error', $message);

        Notification::assertSentToTimes($this->customer, InvoiceDueReminderNotification::class, 1);
        $this->assertSame(1, $this->reminders());
    }

    public function test_another_person_pressing_the_button_the_same_day_is_refused_too(): void
    {
        Notification::fake();
        $colleague = User::factory()->create(['company_id' => $this->admin->company_id, 'email_verified_at' => now(), 'is_active' => true]);
        $this->grantRole($colleague, 'admin');

        $this->remind($this->invoice)->assertSessionHas('success');
        $this->actingAs($colleague)->postJson(route('admin.invoices.remind', $this->invoice))->assertStatus(429);

        Notification::assertSentToTimes($this->customer, InvoiceDueReminderNotification::class, 1);
    }

    public function test_each_invoice_has_its_own_day(): void
    {
        Notification::fake();
        $other = $this->invoice(['due_date' => '2026-10-25']);

        $this->remind($this->invoice)->assertSessionHas('success');
        $this->remind($other)->assertSessionHas('success');

        Notification::assertSentToTimes($this->customer, InvoiceDueReminderNotification::class, 2);
        $this->assertSame(2, $this->reminders());
    }

    public function test_the_day_is_the_companys_so_the_next_morning_in_jakarta_may_remind_again(): void
    {
        Notification::fake();

        $this->travelTo('2026-10-21 16:30:00'); // 23:30 in Jakarta, 21 October
        $this->remind($this->invoice)->assertSessionHas('success');
        $this->travelTo('2026-10-21 16:59:00'); // still the 21st in Jakarta, and the 21st in UTC
        $this->actingAs($this->admin)->postJson(route('admin.invoices.remind', $this->invoice))->assertStatus(429);

        $this->travelTo('2026-10-21 17:30:00'); // 00:30 in Jakarta, 22 October: a new company day, the same UTC day
        $this->remind($this->invoice)->assertSessionHas('success');
        $this->travelTo('2026-10-22 05:00:00'); // midday of the 22nd in Jakarta: the same company day again
        $this->actingAs($this->admin)->postJson(route('admin.invoices.remind', $this->invoice))->assertStatus(429);

        Notification::assertSentToTimes($this->customer, InvoiceDueReminderNotification::class, 2);
    }

    public function test_a_failed_send_gives_the_day_back_and_audits_nothing(): void
    {
        Notification::swap(new class() extends NotificationFake
        {
            public function send($notifiables, $notification): void
            {
                throw new RuntimeException('queue is down');
            }
        });
        $this->withoutExceptionHandling();

        try {
            $this->remind($this->invoice);
            $this->fail('the failure was swallowed');
        } catch (RuntimeException $e) {
            $this->assertSame('queue is down', $e->getMessage());
        }

        $this->assertFalse(Cache::has($this->key()), 'the claim of the day is handed back');
        $this->assertSame(0, $this->reminders());

        Notification::fake();
        $this->remind($this->invoice)->assertSessionHas('success');
        Notification::assertSentToTimes($this->customer, InvoiceDueReminderNotification::class, 1);
    }

    public function test_only_an_invoice_that_still_owes_money_is_reminded(): void
    {
        Notification::fake();
        $refused = [
            'draft' => $this->invoice(['status' => 'draft']),
            'paid' => $this->invoice(['status' => 'paid', 'paid_amount' => 100000]),
            'cancelled' => $this->invoice(['status' => 'cancelled']),
            'sent but fully paid' => $this->invoice(['status' => 'sent', 'paid_amount' => 100000]),
        ];

        foreach ($refused as $invoice) {
            $this->actingAs($this->admin)
                ->postJson(route('admin.invoices.remind', $invoice))
                ->assertUnprocessable()
                ->assertJsonPath('message', 'Hanya tagihan yang belum lunas yang bisa diingatkan.');
        }
        foreach (['partial' => 20000, 'overdue' => 0] as $status => $paid) {
            $this->remind($this->invoice(['status' => $status, 'paid_amount' => $paid, 'due_date' => '2026-10-10']))->assertSessionHas('success');
        }

        Notification::assertSentToTimes($this->customer, InvoiceDueReminderNotification::class, 2);
    }

    public function test_a_deleted_customer_has_nobody_to_remind_and_the_day_stays_free(): void
    {
        Notification::fake();
        $this->customer->delete();

        $this->actingAs($this->admin)
            ->postJson(route('admin.invoices.remind', $this->invoice))
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Pelanggan tagihan ini sudah dihapus.');

        $this->assertFalse(Cache::has($this->key()));
        Notification::assertNothingSent();
    }

    public function test_it_needs_the_permission_to_send_invoices(): void
    {
        Notification::fake();
        $viewer = User::factory()->create(['company_id' => $this->admin->company_id, 'email_verified_at' => now(), 'is_active' => true]);
        $this->grantPermissions($viewer, ['app.web', 'billing.view']);
        $sender = User::factory()->create(['company_id' => $this->admin->company_id, 'email_verified_at' => now(), 'is_active' => true]);
        $this->grantPermissions($sender, ['app.web', 'billing.view', 'billing.send']);

        $this->actingAs($viewer)->postJson(route('admin.invoices.remind', $this->invoice))->assertForbidden();
        Notification::assertNothingSent();
        $this->assertFalse(Cache::has($this->key()), 'a refused person claims no day');

        $this->actingAs($sender)->post(route('admin.invoices.remind', $this->invoice))->assertSessionHas('success');
        Notification::assertSentToTimes($this->customer, InvoiceDueReminderNotification::class, 1);
    }

    public function test_another_companys_invoice_is_not_found_and_a_guest_is_sent_to_login(): void
    {
        Notification::fake();
        $other = Company::factory()->create(['is_active' => true]);
        $foreign = Invoice::factory()->create(['company_id' => $other->id, 'customer_id' => Customer::factory()->create(['company_id' => $other->id])->id, 'status' => 'sent', 'total' => 100000]);

        $this->post(route('admin.invoices.remind', $this->invoice))->assertRedirect(route('login'));
        $this->actingAs($this->admin)->postJson(route('admin.invoices.remind', $foreign))->assertNotFound();

        Notification::assertNothingSent();
    }

    public function test_a_real_send_leaves_the_bell_row_and_the_whatsapp_row_of_that_day(): void
    {
        $this->customer->forceFill(['user_id' => User::factory()->create(['company_id' => $this->admin->company_id])->id])->save();

        $this->remind($this->invoice)->assertSessionHas('success');

        $bell = $this->customer->notifications()->sole();
        $this->assertSame(InvoiceDueReminderNotification::class, $bell->type);
        $this->assertSame(['invoice_due_soon', 'manual', $this->invoice->public_id, $this->invoice->number, '2026-10-24'], [$bell->data['type'], $bell->data['stage'], $bell->data['invoice_id'], $bell->data['invoice_number'], $bell->data['due_date']]);
        $this->assertSame("invoice_reminder:{$this->invoice->id}:manual:2026-10-21", WhatsAppMessage::query()->sole()->dedupe_key);
    }

    public function test_the_bell_calls_a_manual_reminder_overdue_only_after_the_due_date_and_the_whatsapp_key_holds_the_day(): void
    {
        $notification = new InvoiceDueReminderNotification((int) $this->admin->company_id, $this->invoice->id, 'manual');
        $bell = fn (): array => $notification->toArray($this->customer);

        $this->assertSame(['invoice_due_soon', 'manual'], [$bell()['type'], $bell()['stage']]);

        $this->invoice->update(['due_date' => '2026-10-21']);
        $this->assertSame('invoice_due_soon', $bell()['type'], 'due today is not late yet');

        $this->invoice->update(['due_date' => '2026-10-20']);
        $this->assertSame('invoice_overdue', $bell()['type']);

        $key = fn (): string => CompanyService::runAs((int) $this->admin->company_id, fn (): string => $notification->toWhatsApp($this->customer)['dedupe_key']);
        $this->assertSame("invoice_reminder:{$this->invoice->id}:manual:2026-10-21", $key());
        $this->travelTo('2026-10-22 05:00:00');
        $this->assertSame("invoice_reminder:{$this->invoice->id}:manual:2026-10-22", $key(), 'tomorrow is another message');
    }

    public function test_the_scheduled_stages_are_the_same_three_and_the_manual_one_is_not_among_them(): void
    {
        $this->assertSame(['H-3', 'H-1', 'H+1'], array_keys(InvoiceDueReminderNotification::STAGES));
        $this->assertSame('manual', InvoiceDueReminderNotification::MANUAL);
        $this->expectException(InvalidArgumentException::class);

        new InvoiceDueReminderNotification(1, 1, 'whenever');
    }
}
