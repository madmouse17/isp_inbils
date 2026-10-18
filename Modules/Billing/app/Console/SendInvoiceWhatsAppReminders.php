<?php

namespace Modules\Billing\Console;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Services\Core\CompanyService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Notifications\BillingScheduleFailedNotification;
use Modules\Billing\Notifications\InvoiceDueReminderNotification;
use Modules\Billing\Notifications\InvoiceWhatsAppNotification;
use Throwable;

class SendInvoiceWhatsAppReminders extends Command
{
    protected $signature = 'billing:whatsapp-reminders {--company= : Explicit company id for artisan runs} {--dry-run : Count the reminders that are due without queueing them}';

    protected $description = 'Queue WhatsApp payment reminders (H-3, H-1, H+1) for invoices that still owe money';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $failedCompanyIds = [];

        // One company's failure (a broken timezone, say) must not keep the others from being reminded: it is reported, and only its own admins are told, at the end.
        foreach ($this->resolveCompanyIds() as $companyId) {
            try {
                $counts = CompanyService::runAs($companyId, fn (): array => $this->remind($companyId, $dryRun));

                $this->info(($dryRun ? '[DRY RUN] ' : '').'Company '.$companyId.': '.collect($counts)->map(fn (int $count, string $stage): string => $stage.' '.$count)->implode(', '));
            } catch (Throwable $e) {
                report($e);
                $this->error('WhatsApp reminders failed for company '.$companyId.': '.$e->getMessage());
                $failedCompanyIds[] = $companyId;
            }
        }

        BillingScheduleFailedNotification::alertAdmins('billing:whatsapp-reminders', $failedCompanyIds);

        return $failedCompanyIds === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Invoices that still owe money and are due in three days, tomorrow or were due yesterday, where "today" is
     * the company's calendar day, not the server's: a run at 17:30 UTC is already tomorrow in Jakarta.
     *
     * @return array<string, int> reminders due (or queued) per stage
     */
    private function remind(int $companyId, bool $dryRun): array
    {
        $today = CarbonImmutable::now(Company::query()->whereKey($companyId)->value('timezone') ?: config('app.timezone'));
        $counts = [];

        foreach (InvoiceDueReminderNotification::STAGES as $stage => $daysUntilDue) {
            $counts[$stage] = 0;

            Invoice::query()
                ->whereIn('status', InvoiceWhatsAppNotification::AWAITING_PAYMENT)
                ->whereColumn('paid_amount', '<', 'total')
                ->where('due_date', $today->addDays($daysUntilDue)->toDateString())
                ->with('customer')
                ->chunkById(200, function ($invoices) use ($companyId, $dryRun, $stage, &$counts): void {
                    foreach ($invoices as $invoice) {
                        if ($invoice->customer === null || $this->bellRung($invoice->customer, $invoice, $stage)) {
                            continue;
                        }

                        $counts[$stage]++;

                        if (! $dryRun) {
                            Notification::send($invoice->customer, new InvoiceDueReminderNotification($companyId, $invoice->id, $stage));
                        }
                    }
                });
        }

        return $counts;
    }

    /**
     * Whether this invoice-stage bell already rang. The bell row is the push's durable twin, so it prevents both from
     * being sent again. WhatsApp keeps its channel-level dedupe key for customers without an app account, who have no bell.
     *
     * shortcut: a re-run before the queue worker wrote the first bell row still rings twice, the row being the only record;
     * give the notification a dedupe key of its own if queue lag ever makes that real.
     */
    private function bellRung(Customer $customer, Invoice $invoice, string $stage): bool
    {
        return $customer->notifications()
            ->reorder()
            ->where('type', InvoiceDueReminderNotification::class)
            ->where('data->invoice_id', $invoice->public_id)
            ->where('data->stage', $stage)
            ->exists();
    }

    /** @return list<int> */
    private function resolveCompanyIds(): array
    {
        $explicit = $this->option('company');

        if ($explicit !== null) {
            return [(int) $explicit];
        }

        return Company::where('is_active', true)->pluck('id')->all();
    }
}
