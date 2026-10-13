<?php

namespace Tests\Feature\Billing;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Modules\Billing\Database\Factories\InvoiceFactory;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Notifications\BillingScheduleFailedNotification;
use Modules\Billing\Notifications\InvoiceDueReminderNotification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Tests\Traits\InteractsWithWhatsApp;

class InvoiceWhatsAppReminderCommandTest extends TestCase
{
    use InteractsWithWhatsApp;
    use RefreshDatabase;

    private Company $company;

    private Customer $customer;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create(['name' => 'PT Contoh Net', 'timezone' => 'Asia/Jakarta']);
        $this->whatsAppEnabledFor($this->company);
        $this->enableWhatsApp();
        $this->fakeWhatsApp();
        $this->customer = $this->consentedCustomer($this->company, '0812-3456-7890');
        $this->travelTo('2026-10-14 01:00:00'); // 08:00 on 14 October in Jakarta
    }

    /** @param array<string, mixed> $attributes */
    private function invoiceDue(string $dueDate, array $attributes = [], ?Company $company = null, ?Customer $customer = null): Invoice
    {
        $company ??= $this->company;

        return InvoiceFactory::new()->create($attributes + [
            'company_id' => $company->id,
            'customer_id' => ($customer ?? $this->customer)->id,
            'number' => 'INV-REM-'.++$this->sequence,
            'status' => 'sent',
            'total' => 250000,
            'paid_amount' => 0,
            'due_date' => $dueDate,
        ]);
    }

    private function remind(array $options = []): void
    {
        $this->artisan('billing:whatsapp-reminders', $options)->assertSuccessful();
    }

    /** @return array<string, string> dedupe key => template parameters joined, for every reminder row */
    private function rowKeys(): array
    {
        return $this->whatsAppRows()->pluck('status', 'dedupe_key')->all();
    }

    public function test_each_stage_gets_its_reminder_and_every_other_day_gets_none(): void
    {
        $h3 = $this->invoiceDue('2026-10-17');
        $h1 = $this->invoiceDue('2026-10-15');
        $overdue = $this->invoiceDue('2026-10-13');
        foreach (['2026-10-18', '2026-10-16', '2026-10-14', '2026-10-12', '2026-10-01'] as $day) {
            $this->invoiceDue($day);
        }

        $this->remind();

        $this->assertCount(3, $this->graphRequests());
        $this->assertSame([
            'invoice_reminder:'.$h3->id.':H-3:2026-10-17' => 'sent',
            'invoice_reminder:'.$h1->id.':H-1:2026-10-15' => 'sent',
            'invoice_reminder:'.$overdue->id.':H+1:2026-10-13' => 'sent',
        ], $this->rowKeys());
        Http::assertSent(fn (Request $request): bool => $request->data()['template']['name'] === 'tagihan_pengingat' && $request->data()['to'] === '6281234567890');
        $this->assertSame(
            ['PT Contoh Net', $h3->number, 'Rp 250.000', '17 Okt 2026', route('login')],
            array_column($this->graphRequests()->first()->data()['template']['components'][0]['parameters'], 'text'),
        );
        $row = $this->whatsAppRows()->first();
        $this->assertSame('invoice_reminder', $row->event);
        $this->assertSame('invoice', $row->subject_type);
        $this->assertSame($h3->id, $row->subject_id);
    }

    public function test_running_twice_the_same_day_sends_each_reminder_once(): void
    {
        $this->invoiceDue('2026-10-17');
        $this->invoiceDue('2026-10-15');

        $this->remind();
        $this->remind();
        $this->remind(['--company' => $this->company->id]);

        $this->assertCount(2, $this->graphRequests());
        $this->assertCount(2, $this->whatsAppRows());
    }

    public function test_a_rescheduled_due_date_earns_a_fresh_reminder(): void
    {
        $invoice = $this->invoiceDue('2026-10-15');
        $this->remind();
        $invoice->forceFill(['due_date' => '2026-10-17'])->save();

        $this->remind();

        $this->assertCount(2, $this->graphRequests());
    }

    public function test_only_invoices_that_still_owe_money_are_reminded(): void
    {
        $this->invoiceDue('2026-10-15', ['status' => 'paid', 'paid_amount' => 250000]);
        $this->invoiceDue('2026-10-15', ['status' => 'cancelled']);
        $this->invoiceDue('2026-10-15', ['status' => 'draft']);
        $this->invoiceDue('2026-10-15', ['status' => 'sent', 'paid_amount' => 250000]);
        $partial = $this->invoiceDue('2026-10-15', ['status' => 'partial', 'paid_amount' => 100000]);
        $late = $this->invoiceDue('2026-10-13', ['status' => 'overdue']);

        $this->remind();

        $this->assertCount(2, $this->graphRequests());
        $remaining = $this->graphRequests()->map(fn (Request $request): string => $request->data()['template']['components'][0]['parameters'][2]['text'])->sort()->values()->all();
        $this->assertSame(['Rp 150.000', 'Rp 250.000'], $remaining);
        $this->assertEqualsCanonicalizing([$partial->id, $late->id], $this->whatsAppRows()->pluck('subject_id')->all());
    }

    public function test_an_invoice_paid_after_the_reminder_was_queued_gets_no_message_and_no_row(): void
    {
        Queue::fake();
        $invoice = $this->invoiceDue('2026-10-15');
        $this->remind();
        $invoice->forceFill(['status' => 'paid', 'paid_amount' => 250000])->save();

        Queue::pushed(SendQueuedNotifications::class)->sole()->handle(app(ChannelManager::class));

        Http::assertNothingSent();
        $this->assertCount(0, $this->whatsAppRows());
    }

    public function test_today_is_the_company_calendar_day_not_the_utc_one(): void
    {
        $due = $this->invoiceDue('2026-10-16');

        // 17:00 on the 14th in Jakarta: 16 October is two days away, no reminder yet.
        $this->travelTo('2026-10-14 10:00:00');
        $this->remind();
        $this->assertCount(0, $this->graphRequests());

        // 00:30 on the 15th in Jakarta (17:30 UTC on the 14th): 16 October is tomorrow.
        $this->travelTo('2026-10-14 17:30:00');
        $this->remind();

        $this->assertCount(1, $this->graphRequests());
        $this->assertSame('invoice_reminder:'.$due->id.':H-1:2026-10-16', $this->whatsAppRows()->sole()->dedupe_key);
    }

    public function test_each_company_uses_its_own_timezone(): void
    {
        $utc = Company::factory()->create(['name' => 'UTC Net', 'timezone' => 'UTC', 'settings' => ['whatsapp_enabled' => true]]);
        $utcCustomer = $this->consentedCustomer($utc, '0813-9999-0001');
        $this->invoiceDue('2026-10-16');
        $this->invoiceDue('2026-10-16', [], $utc, $utcCustomer);

        $this->travelTo('2026-10-14 17:30:00'); // already the 15th in Jakarta, still the 14th in UTC
        $this->remind();

        $this->assertCount(1, $this->graphRequests());
        $this->assertSame('6281234567890', $this->graphRequests()->sole()->data()['to']);
    }

    public function test_two_companies_with_the_same_invoice_number_remind_only_their_own_customer(): void
    {
        $other = Company::factory()->create(['name' => 'CV Lain', 'settings' => ['whatsapp_enabled' => true]]);
        $otherCustomer = $this->consentedCustomer($other, '0813-9999-0001');
        $mine = $this->invoiceDue('2026-10-15', ['number' => 'INV-DUP-1']);
        $theirs = $this->invoiceDue('2026-10-15', ['number' => 'INV-DUP-1', 'total' => 900000], $other, $otherCustomer);

        $this->remind();

        $this->assertCount(2, $this->graphRequests());
        $byRecipient = $this->graphRequests()->mapWithKeys(fn (Request $request): array => [
            $request->data()['to'] => array_column($request->data()['template']['components'][0]['parameters'], 'text'),
        ])->all();
        $this->assertSame(['PT Contoh Net', 'INV-DUP-1', 'Rp 250.000', '15 Okt 2026', route('login')], $byRecipient['6281234567890']);
        $this->assertSame(['CV Lain', 'INV-DUP-1', 'Rp 900.000', '15 Okt 2026', route('login')], $byRecipient['6281399990001']);
        $this->assertSame([$mine->id], $this->whatsAppRows($this->company->id)->pluck('subject_id')->all());
        $this->assertSame([$theirs->id], $this->whatsAppRows($other->id)->pluck('subject_id')->all());
    }

    public function test_customers_without_consent_leave_a_skipped_row_and_are_not_called(): void
    {
        $this->customer->forceFill(['whatsapp_opt_in_at' => null])->save();
        $this->invoiceDue('2026-10-15');

        $this->remind();

        Http::assertNothingSent();
        $row = $this->whatsAppRows()->sole();
        $this->assertSame('skipped', $row->status);
        $this->assertSame('no_consent', $row->skip_reason);
    }

    public function test_invoices_of_deleted_customers_are_left_alone(): void
    {
        $gone = $this->consentedCustomer($this->company, '0813-9999-0002');
        $this->invoiceDue('2026-10-15', [], null, $gone);
        $gone->delete();

        $this->remind();

        Http::assertNothingSent();
        $this->assertCount(0, $this->whatsAppRows());
    }

    public function test_reminders_go_out_at_once_at_the_scheduled_hour(): void
    {
        Queue::fake();
        $this->invoiceDue('2026-10-15');

        $this->remind();

        $job = Queue::pushed(SendQueuedNotifications::class)->sole();
        $this->assertNull($job->delay, '08:00 in Jakarta is outside the quiet window');
        $this->assertSame('whatsapp', $job->queue);
    }

    public function test_company_option_limits_the_run_and_inactive_companies_are_skipped(): void
    {
        $other = Company::factory()->create(['settings' => ['whatsapp_enabled' => true]]);
        $otherCustomer = $this->consentedCustomer($other, '0813-9999-0001');
        $inactive = Company::factory()->create(['is_active' => false, 'settings' => ['whatsapp_enabled' => true]]);
        $inactiveCustomer = $this->consentedCustomer($inactive, '0813-9999-0003');
        $this->invoiceDue('2026-10-15');
        $this->invoiceDue('2026-10-15', [], $other, $otherCustomer);
        $this->invoiceDue('2026-10-15', [], $inactive, $inactiveCustomer);

        $this->remind(['--company' => $other->id]);
        $this->assertSame(['6281399990001'], $this->graphRequests()->map(fn (Request $request): string => $request->data()['to'])->all());

        $this->remind();
        $this->assertEqualsCanonicalizing(['6281399990001', '6281234567890'], $this->graphRequests()->map(fn (Request $request): string => $request->data()['to'])->all());
    }

    public function test_dry_run_counts_and_queues_nothing(): void
    {
        $this->invoiceDue('2026-10-17');
        $this->invoiceDue('2026-10-15');
        $this->invoiceDue('2026-10-15');
        $this->invoiceDue('2026-10-13');

        $this->artisan('billing:whatsapp-reminders', ['--dry-run' => true])
            ->expectsOutputToContain('[DRY RUN] Company '.$this->company->id.': H-3 1, H-1 2, H+1 1')
            ->assertSuccessful();

        Http::assertNothingSent();
        $this->assertCount(0, $this->whatsAppRows());
    }

    public function test_a_failing_company_does_not_stop_the_others_and_the_admins_are_told(): void
    {
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $admin = User::factory()->create(['company_id' => $this->company->id, 'is_active' => true]);
        $admin->assignRole('admin');
        Company::factory()->create(['timezone' => 'Mars/Phobos', 'settings' => ['whatsapp_enabled' => true]]);
        $late = Company::factory()->create(['name' => 'CV Terakhir', 'settings' => ['whatsapp_enabled' => true]]);
        $lateCustomer = $this->consentedCustomer($late, '0813-9999-0001');
        $this->invoiceDue('2026-10-15');
        $this->invoiceDue('2026-10-15', [], $late, $lateCustomer);
        Notification::fake();

        $this->artisan('billing:whatsapp-reminders')->assertFailed();

        Notification::assertSentTo($this->customer, InvoiceDueReminderNotification::class);
        Notification::assertSentTo($lateCustomer, InvoiceDueReminderNotification::class);
        Notification::assertSentToTimes($admin, BillingScheduleFailedNotification::class, 1);
        Notification::assertSentTo($admin, BillingScheduleFailedNotification::class, fn (BillingScheduleFailedNotification $n): bool => $n->command === 'billing:whatsapp-reminders');
    }

    public function test_the_command_is_scheduled_daily_at_0100_utc_and_old_messages_are_pruned_daily(): void
    {
        $events = collect(app(Schedule::class)->events());

        $reminders = $events->first(fn ($event): bool => str_contains($event->command, 'billing:whatsapp-reminders'));
        $this->assertNotNull($reminders);
        $this->assertSame('0 1 * * *', $reminders->expression);
        $this->assertTrue($reminders->withoutOverlapping);

        $prune = $events->first(fn ($event): bool => str_contains($event->command, 'model:prune') && str_contains($event->command, 'WhatsAppMessage'));
        $this->assertNotNull($prune);
        $this->assertSame('0 0 * * *', $prune->expression);
        $this->assertStringContainsString('WhatsAppInboundReceipt', $prune->command, 'the STOP receipts are pruned by the same daily command');
    }
}
