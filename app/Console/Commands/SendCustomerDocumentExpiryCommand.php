<?php

namespace App\Console\Commands;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Notifications\CustomerDocumentExpiringNotification;
use App\Services\Core\CompanyService;
use App\Services\Core\DocumentService;
use App\Support\Notifications\StaffRecipients;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class SendCustomerDocumentExpiryCommand extends Command
{
    protected $signature = 'customers:document-expiry {--company= : Company id; default all active companies}';

    protected $description = 'Notify customer-update staff once when a customer document enters its 30-day expiry window.';

    public function handle(): int
    {
        $companyIds = $this->option('company') !== null
            ? [(int) $this->option('company')]
            : Company::query()->where('is_active', true)->orderBy('id')->pluck('id')->all();
        $sent = 0;

        foreach ($companyIds as $companyId) {
            $sent += CompanyService::runAs($companyId, fn (): int => $this->sendForCompany($companyId));
        }

        $this->info("Sent {$sent} customer document expiry reminder(s).");

        return self::SUCCESS;
    }

    private function sendForCompany(int $companyId): int
    {
        $recipients = StaffRecipients::holding($companyId, 'customer.update');

        if ($recipients->isEmpty()) {
            return 0;
        }

        $today = DocumentService::today();
        $through = CarbonImmutable::parse($today)->addDays(DocumentService::EXPIRY_WINDOW_DAYS)->toDateString();
        $sent = 0;

        // shortcut: the model morph index narrows this scan to customer media. Add a generated expiry column and index past 100k media rows.
        Media::query()
            ->where('model_type', Customer::class)
            ->where('collection_name', 'documents')
            ->where('custom_properties->company_id', $companyId)
            ->whereBetween('custom_properties->expires_at', [$today, $through])
            ->whereIn('model_id', Customer::query()->select('id'))
            ->orderBy('id')
            ->chunkById(200, function ($media) use ($companyId, $recipients, $today, $through, &$sent): void {
                foreach ($media as $document) {
                    $sent += DB::transaction(function () use ($document, $companyId, $recipients, $today, $through): int {
                        $locked = Media::query()->lockForUpdate()->find($document->id);
                        $expiresAt = $locked?->getCustomProperty('expires_at');

                        if (! $locked instanceof Media
                            || $locked->model_type !== Customer::class
                            || $locked->collection_name !== 'documents'
                            || (int) $locked->getCustomProperty('company_id') !== $companyId
                            || ! is_string($expiresAt)
                            || $expiresAt < $today
                            || $expiresAt > $through
                            || $locked->getCustomProperty('expiry_notified_for') === $expiresAt
                            || ! Customer::query()->whereKey($locked->model_id)->exists()
                        ) {
                            return 0;
                        }

                        foreach ($recipients as $recipient) {
                            $recipient->notify(new CustomerDocumentExpiringNotification($companyId, $locked->id));
                        }

                        $locked->setCustomProperty('expiry_notified_for', $expiresAt)->save();

                        return $recipients->count();
                    });
                }
            });

        return $sent;
    }
}
