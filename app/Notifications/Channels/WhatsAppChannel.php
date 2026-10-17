<?php

namespace App\Notifications\Channels;

use App\Models\Core\Customer;
use App\Models\Core\EmployeeProfile;
use App\Models\Core\WhatsAppMessage;
use App\Models\User;
use App\Notifications\WhatsAppNotification;
use App\Services\Core\CompanyService;
use App\Services\WhatsApp\PhoneNumber;
use App\Services\WhatsApp\WhatsAppCloudClient;
use App\Services\WhatsApp\WhatsAppSendRejected;
use App\Services\WhatsApp\WhatsAppSettings;
use App\Services\WhatsApp\WhatsAppTransientError;
use DateTimeInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * The one gate every WhatsApp message passes, so the rules live in one place. In order: feature configured
 * -> company switch and event switch -> recipient (customer consent, technician active) -> phone number ->
 * monthly cap -> idempotency key -> send -> store the provider message id. Every outcome that is not a
 * send leaves a `skipped` row with its reason. Runs in the notification's company; nothing here reads
 * another company's settings or writes its rows. Everything up to and including the claim of the key runs
 * under the company's row lock, so workers cannot sell the last slot of the monthly cap twice.
 *
 * @phpstan-import-type Payload from WhatsAppNotification
 */
class WhatsAppChannel
{
    /** A queued row that nobody finished for this long belongs to a crashed attempt and may be taken over. */
    private const STALE_AFTER_SECONDS = 60;

    public function __construct(private readonly WhatsAppCloudClient $client) {}

    /** When a batch message due now may go out; null outside the company's quiet hours. */
    public static function quietDelay(int $companyId): ?DateTimeInterface
    {
        return WhatsAppSettings::for($companyId)->quietDelay();
    }

    public function send(object $notifiable, WhatsAppNotification $notification): void
    {
        CompanyService::runAs($notification->companyId, function () use ($notifiable, $notification): void {
            $payload = $notification->toWhatsApp($notifiable);

            if ($payload !== null) {
                $this->deliver($notifiable, $notification->companyId, $payload);
            }
        });
    }

    /**
     * The decision and the claim are one step per company: the lock is released before Meta is called, and the
     * queued row holds its slot of the cap meanwhile.
     *
     * @param  Payload  $payload
     */
    private function deliver(object $notifiable, int $companyId, #[\SensitiveParameter] array $payload): void
    {
        $claimed = DB::transaction(fn (): ?array => $this->reserve($notifiable, $companyId, $payload));

        if ($claimed !== null) {
            [$row, $phone] = $claimed;

            $this->transmit($row, $phone, $payload);
        }
    }

    /**
     * Runs inside the delivery transaction. The company row is locked first, before anything of the log is read.
     *
     * @param  Payload  $payload
     * @return array{0: WhatsAppMessage, 1: string}|null the row to send and the number to send it to; null when nothing is to be sent
     */
    private function reserve(object $notifiable, int $companyId, #[\SensitiveParameter] array $payload): ?array
    {
        $settings = WhatsAppSettings::lockedFor($companyId);
        $existing = WhatsAppMessage::query()->where('dedupe_key', $payload['dedupe_key'])->first();

        // Already sent (or failed for good, or in flight in another worker): a retry, a re-run or a duplicate
        // dispatch must not send again.
        if ($existing !== null && ! $this->mayAttemptAgain($existing)) {
            return null;
        }

        $recipient = $this->recipient($notifiable, $companyId);
        $phone = PhoneNumber::normalizeIndonesianMobile($recipient['phone']);

        $skip = match (true) {
            ! $this->client->isConfigured(), ! $settings->enabled() => 'disabled',
            ! $settings->eventEnabled($payload['event']) => 'event_disabled',
            $recipient['blocked'] !== null => $recipient['blocked'],
            $phone === null => 'invalid_phone',
            $settings->monthlyCapReached($existing?->id) => 'budget_exceeded',
            default => null,
        };

        $row = $this->claim($existing, [
            'recipient_type' => $recipient['type'],
            'recipient_id' => $recipient['id'],
            'event' => $payload['event'],
            'subject_type' => $payload['subject_type'],
            'subject_id' => $payload['subject_id'],
            'template' => $payload['template'],
            'language' => $payload['language'],
            'dedupe_key' => $payload['dedupe_key'],
            'status' => $skip === null ? 'queued' : 'skipped',
            'skip_reason' => $skip,
            'error_code' => null,
            'provider_message_id' => null,
            'recipient_hash' => $phone === null ? null : hash_hmac('sha256', $phone, (string) config('app.key')),
            'recipient_last4' => $phone === null ? null : substr($phone, -4),
        ]);

        return $row !== null && $skip === null ? [$row, (string) $phone] : null;
    }

    /**
     * A skipped row is overwritten by a later attempt; a queued row is retaken when the previous attempt failed
     * transiently (it left an error code) or died mid-send (nobody touched it for a minute). Anything else
     * owns its key.
     */
    private function mayAttemptAgain(WhatsAppMessage $row): bool
    {
        return match ($row->status) {
            'skipped' => true,
            'queued' => $row->error_code !== null || $row->updated_at->lte(now()->subSeconds(self::STALE_AFTER_SECONDS)),
            default => false,
        };
    }

    /**
     * Take the row for this attempt, or null when a concurrent attempt got it first. The unique index on
     * (company_id, dedupe_key) is the backstop when two jobs for the same key race on an empty table.
     *
     * @param  array<string, mixed>  $values
     */
    private function claim(?WhatsAppMessage $existing, array $values): ?WhatsAppMessage
    {
        if ($existing === null) {
            try {
                return WhatsAppMessage::query()->create($values);
            } catch (UniqueConstraintViolationException) {
                return null;
            }
        }

        // Only the attempt that still sees the row as it read it takes it over.
        $taken = WhatsAppMessage::query()
            ->whereKey($existing->id)
            ->where('status', $existing->status)
            ->where('updated_at', $existing->updated_at)
            ->update($values);

        return $taken === 1 ? $existing->refresh() : null;
    }

    /**
     * @param  array{template: string, language: string, parameters: list<string>}  $payload
     */
    private function transmit(WhatsAppMessage $row, #[\SensitiveParameter] string $phone, #[\SensitiveParameter] array $payload): void
    {
        try {
            $providerId = $this->client->sendTemplate($phone, $payload['template'], $payload['language'], $payload['parameters']);
        } catch (WhatsAppSendRejected $e) {
            $row->update(['status' => 'failed', 'error_code' => $e->errorCode, 'failed_at' => now()]);
            Log::warning('whatsapp.send_rejected', ['message_id' => $row->id, 'event' => $row->event, 'error_code' => $e->errorCode]);

            return;
        } catch (WhatsAppTransientError $e) {
            // The row stays queued with the code: the queue retries and the next attempt retakes it.
            $row->update(['error_code' => $e->errorCode]);

            throw $e;
        }

        $row->update(['status' => 'sent', 'provider_message_id' => $providerId, 'sent_at' => now()]);
    }

    /**
     * Who receives the message and whether they may. Re-read inside the notification's company, never trusted
     * from the queued copy: consent can change while a delayed job waits, and a record of another company
     * or a deleted one resolves to nothing.
     *
     * @return array{type: string, id: int, phone: ?string, blocked: ?string}
     */
    private function recipient(object $notifiable, int $companyId): array
    {
        if ($notifiable instanceof Customer) {
            return $this->customerRecipient($notifiable->id, Customer::query()->find($notifiable->id));
        }

        if (! $notifiable instanceof User) {
            throw new InvalidArgumentException('WhatsApp notifications go to customers and users only.');
        }

        // A customer's portal user stands for the customer: their phone, their consent.
        $customer = Customer::query()->where('user_id', $notifiable->id)->first();

        if ($customer !== null) {
            return $this->customerRecipient($customer->id, $customer);
        }

        // Technician: no consent of their own (work instruction), but only while active in this company.
        $user = User::query()->whereKey($notifiable->id)->where('company_id', $companyId)->where('is_active', true)->first();
        $profile = $user === null ? null : EmployeeProfile::query()->where('user_id', $user->id)->where('status', 'active')->first();

        return ['type' => 'user', 'id' => $notifiable->id, 'phone' => $profile?->phone, 'blocked' => $profile === null ? 'no_consent' : null];
    }

    /** @return array{type: string, id: int, phone: ?string, blocked: ?string} */
    private function customerRecipient(int $id, ?Customer $customer): array
    {
        $blocked = match (true) {
            $customer === null => 'no_consent',
            $customer->whatsapp_opt_out_at !== null => 'opted_out',
            ! $customer->hasWhatsAppConsent() => 'no_consent',
            default => null,
        };

        return ['type' => 'customer', 'id' => $id, 'phone' => $customer?->phone, 'blocked' => $blocked];
    }
}
