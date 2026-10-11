<?php

namespace App\Services\WhatsApp;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\Core\WhatsAppInboundReceipt;
use App\Models\Core\WhatsAppMessage;
use App\Services\Core\AuditService;
use App\Services\Core\CompanyService;
use Carbon\CarbonInterface;
use Generator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Applies a payload whose signature was already verified: delivery statuses move a message forward, a STOP or
 * BERHENTI reply withdraws the sender's consent. Everything else is ignored. Meta repeats and reorders
 * callbacks, so a status only ever moves forward, and only through a conditional UPDATE: two callbacks racing
 * on the same row cannot move it back, and a reply is applied once per message (a receipt per company) and
 * never to consent given after it was sent. Nothing here logs a request body or a phone number, and every
 * parameter that holds one is #[\SensitiveParameter], so a stack trace cannot carry them into failed_jobs or
 * the logs.
 */
class WhatsAppWebhookHandler
{
    /** A message's progress. `failed` sits outside it: reachable from queued and sent only. */
    private const PROGRESS = ['queued' => 0, 'sent' => 1, 'delivered' => 2, 'read' => 3];

    /** Replies that withdraw consent, compared trimmed and in lower case. */
    private const OPT_OUT_WORDS = ['stop', 'berhenti'];

    /** @param array<string, mixed> $payload */
    public function handle(#[\SensitiveParameter] array $payload): void
    {
        foreach ($this->values($payload) as $value) {
            foreach ($this->items($value, 'statuses') as $status) {
                $this->applyStatus($status);
            }

            foreach ($this->items($value, 'messages') as $message) {
                $this->applyReply($message);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $status
     */
    private function applyStatus(#[\SensitiveParameter] array $status): void
    {
        $providerId = $status['id'] ?? null;
        $state = $status['status'] ?? null;

        if (! is_string($providerId) || ! is_string($state)) {
            return;
        }

        $at = $this->moment($status['timestamp'] ?? null);

        if ($state === 'failed') {
            $from = ['queued', 'sent'];
            $changes = ['status' => 'failed', 'failed_at' => $at, 'error_code' => $this->errorCode($status)];
        } elseif (isset(self::PROGRESS[$state]) && self::PROGRESS[$state] > self::PROGRESS['queued']) {
            $from = array_keys(array_filter(self::PROGRESS, fn (int $rank): bool => $rank < self::PROGRESS[$state]));
            $changes = ['status' => $state, $state.'_at' => $at] + $this->pricing($status['pricing'] ?? null);
        } else {
            return; // played, unknown states
        }

        // The provider id is globally unique and the only way to learn which company the message belongs to.
        $message = WhatsAppMessage::withoutCompany()->where('provider_message_id', $providerId)->first();

        if ($message === null) {
            return;
        }

        if ($state !== 'failed') {
            // Delivered or read while the row never learned that the send went through: the message left now,
            // and counts against the cap of this month.
            $changes['sent_at'] = $message->sent_at ?? $at;
        }

        $moved = CompanyService::runAs($message->company_id, fn (): int => WhatsAppMessage::query()
            ->whereKey($message->id)
            ->whereIn('status', $from)
            ->update($changes));

        if ($moved === 1 && $state === 'failed') {
            Log::warning('whatsapp.delivery_failed', ['message_id' => $message->id, 'error_code' => $changes['error_code']]);
        }
    }

    /**
     * A STOP is applied once per message and only to consent given before it was sent. Meta delivers a callback
     * at least once, so a redelivered STOP must not withdraw consent that staff recorded again in the meantime.
     *
     * @param  array<string, mixed>  $message
     */
    private function applyReply(#[\SensitiveParameter] array $message): void
    {
        $from = $message['from'] ?? null;
        $body = data_get($message, 'text.body');

        if (($message['type'] ?? null) !== 'text' || ! is_string($from) || ! is_string($body)) {
            return;
        }

        if (! in_array(mb_strtolower(trim($body)), self::OPT_OUT_WORDS, true)) {
            return;
        }

        $phone = PhoneNumber::normalizeIndonesianMobile($from);

        if ($phone === null) {
            return;
        }

        // Meta's message ids embed the sender's number: only a keyed hash of one is kept, as the receipt.
        $id = $message['id'] ?? null;
        $receipt = is_string($id) && $id !== '' ? hash_hmac('sha256', $id, (string) config('app.key')) : null;
        $sentAt = $this->moment($message['timestamp'] ?? null);

        // A number can belong to customers of several companies; each withdrawal is audited in its own company.
        foreach (Company::query()->pluck('id') as $companyId) {
            CompanyService::runAs((int) $companyId, fn () => $this->optOutCustomers($phone, $receipt, $sentAt));
        }
    }

    /**
     * One transaction per company: the receipt of the message and the withdrawals stand or fall together. When a
     * withdrawal fails, the receipt is rolled back with it, the webhook answers with an error and Meta delivers
     * the reply again; a company that already finished it is skipped by its receipt.
     *
     * shortcut: a scan of the company's customers per STOP, because stored phones are free text and cannot be
     * matched in SQL; replace it with a normalised phone column when customers or STOP replies grow into thousands.
     */
    private function optOutCustomers(#[\SensitiveParameter] string $phone, ?string $receipt, CarbonInterface $sentAt): void
    {
        $matching = Customer::query()
            ->whereNull('whatsapp_opt_out_at')
            ->whereNotNull('phone')
            ->lazyById(200)
            ->filter(fn (Customer $customer): bool => PhoneNumber::normalizeIndonesianMobile($customer->phone) === $phone)
            ->pluck('id')
            ->all();

        if ($matching === []) {
            return;
        }

        DB::transaction(function () use ($matching, $receipt, $sentAt): void {
            if ($receipt !== null && ! WhatsAppInboundReceipt::query()->createOrFirst(['message_hash' => $receipt])->wasRecentlyCreated) {
                return;
            }

            // Read again under the row lock: staff may have recorded consent since the scan, and consent given
            // after the STOP was sent stands.
            Customer::query()
                ->whereKey($matching)
                ->whereNull('whatsapp_opt_out_at')
                ->lockForUpdate()
                ->get()
                ->filter(fn (Customer $customer): bool => $customer->whatsapp_opt_in_at === null || $customer->whatsapp_opt_in_at->lte($sentAt))
                ->each(function (Customer $customer): void {
                    $customer->forceFill(['whatsapp_opt_out_at' => now()])->save();

                    AuditService::log('whatsapp', 'opted_out_by_reply', ['customer_id' => $customer->id], $customer);
                });
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return Generator<int, array<string, mixed>>
     */
    private function values(#[\SensitiveParameter] array $payload): Generator
    {
        foreach ($this->items($payload, 'entry') as $entry) {
            foreach ($this->items($entry, 'changes') as $change) {
                if (is_array($change['value'] ?? null)) {
                    yield $change['value'];
                }
            }
        }
    }

    /**
     * The array members of a list in the payload; anything else in its place is junk and skipped.
     *
     * @param  array<string, mixed>  $parent
     * @return list<array<string, mixed>>
     */
    private function items(#[\SensitiveParameter] array $parent, string $key): array
    {
        return is_array($parent[$key] ?? null) ? array_values(array_filter($parent[$key], 'is_array')) : [];
    }

    private function moment(mixed $unixSeconds): CarbonInterface
    {
        return is_numeric($unixSeconds) && (int) $unixSeconds > 0
            ? Carbon::createFromTimestamp((int) $unixSeconds)->setTimezone((string) config('app.timezone'))
            : now();
    }

    /** @param array<string, mixed> $status */
    private function errorCode(#[\SensitiveParameter] array $status): ?string
    {
        $code = data_get($status, 'errors.0.code');

        return is_int($code) || (is_string($code) && ctype_digit($code)) ? (string) $code : null;
    }

    /**
     * `billable` is on its way out of Meta's payload in favour of pricing.type, so a missing flag is derived
     * from the type: only `regular` messages are billed.
     *
     * @return array<string, bool|string>
     */
    private function pricing(mixed $pricing): array
    {
        if (! is_array($pricing)) {
            return [];
        }

        $billable = $pricing['billable'] ?? (is_string($pricing['type'] ?? null) ? $pricing['type'] === 'regular' : null);
        $category = $pricing['category'] ?? null;

        return array_filter([
            'billable' => is_bool($billable) ? $billable : null,
            'pricing_category' => is_string($category) ? mb_substr($category, 0, 40) : null,
        ], fn (bool|string|null $value): bool => $value !== null);
    }
}
