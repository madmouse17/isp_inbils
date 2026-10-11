<?php

namespace App\Services\WhatsApp;

use App\Models\Core\Company;
use App\Models\Core\WhatsAppMessage;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * A company's non-secret WhatsApp switches (companies.settings: enabled, events, quiet window, monthly cap)
 * with the code defaults of config/whatsapp.php filled in. The company row is read fresh on purpose:
 * CompanyService::setting() memoises per process, and a queue worker must not act on a settings snapshot
 * taken when it booted.
 */
final class WhatsAppSettings
{
    /**
     * A queued message keeps its slot of the monthly cap while its retries can still send it: five attempts with
     * the backoff of WhatsAppNotification (60, 300, 900 and 3600 seconds) end 81 minutes after the first one.
     */
    private const IN_FLIGHT_SECONDS = 7200;

    private function __construct(private readonly Company $company) {}

    public static function for(int $companyId): self
    {
        return new self(Company::query()->findOrFail($companyId));
    }

    /**
     * The same settings with the company's row locked until the surrounding transaction ends: the workers of a
     * company take their turn at the monthly cap through that lock.
     */
    public static function lockedFor(int $companyId): self
    {
        return new self(Company::query()->lockForUpdate()->findOrFail($companyId));
    }

    /**
     * What a company that never saved these settings gets, keyed like companies.settings.
     *
     * @return array{whatsapp_enabled: bool, whatsapp_events: list<string>, whatsapp_quiet_start: string, whatsapp_quiet_end: string, whatsapp_monthly_cap: int}
     */
    public static function defaults(): array
    {
        return [
            'whatsapp_enabled' => false,
            'whatsapp_events' => array_keys(config('whatsapp.events')),
            'whatsapp_quiet_start' => (string) config('whatsapp.quiet_start'),
            'whatsapp_quiet_end' => (string) config('whatsapp.quiet_end'),
            'whatsapp_monthly_cap' => (int) config('whatsapp.monthly_cap'),
        ];
    }

    public function enabled(): bool
    {
        return filter_var($this->stored('whatsapp_enabled', false), FILTER_VALIDATE_BOOLEAN);
    }

    public function eventEnabled(string $event): bool
    {
        $events = $this->stored('whatsapp_events', self::defaults()['whatsapp_events']);

        return is_array($events) && in_array($event, $events, true);
    }

    public function monthlyCap(): int
    {
        $cap = $this->stored('whatsapp_monthly_cap', null);

        return is_numeric($cap) ? max(0, (int) $cap) : self::defaults()['whatsapp_monthly_cap'];
    }

    public function timezone(): string
    {
        return $this->company->timezone ?: (string) config('app.timezone');
    }

    /** First instant of the company-local calendar month, in the app timezone the timestamps are stored in. */
    public function monthStart(): CarbonImmutable
    {
        return CarbonImmutable::now($this->timezone())->startOfMonth()->setTimezone((string) config('app.timezone'));
    }

    /**
     * Counts of this calendar month's messages: handed to Meta (sent, delivered and read), delivered (and
     * read), failed, and billed by Meta. A message belongs to the month it was handed to Meta in (it may have
     * been created in the month before), or to the month it was created in when it never was.
     *
     * @return array{sent: int, delivered: int, failed: int, billable: int}
     */
    public function usage(): array
    {
        $monthStart = $this->monthStart();
        $row = WhatsAppMessage::forCompany($this->company->id)
            ->where(fn (Builder $month) => $month
                ->where('sent_at', '>=', $monthStart)
                ->orWhere(fn (Builder $unsent) => $unsent->whereNull('sent_at')->where('created_at', '>=', $monthStart)))
            ->selectRaw("COALESCE(SUM(status IN ('sent', 'delivered', 'read')), 0) AS sent")
            ->selectRaw("COALESCE(SUM(status IN ('delivered', 'read')), 0) AS delivered")
            ->selectRaw("COALESCE(SUM(status = 'failed'), 0) AS failed")
            ->selectRaw('COALESCE(SUM(billable = 1), 0) AS billable')
            ->toBase()
            ->first();

        return [
            'sent' => (int) $row->sent,
            'delivered' => (int) $row->delivered,
            'failed' => (int) $row->failed,
            'billable' => (int) $row->billable,
        ];
    }

    /**
     * Whether this month's cap is used up. A slot is taken by every message handed to Meta since the month began
     * and by every message still being sent. $exceptMessageId is the message asking: a retry does not compete
     * with the slot of its own earlier attempt.
     */
    public function monthlyCapReached(?int $exceptMessageId = null): bool
    {
        $taken = WhatsAppMessage::forCompany($this->company->id)
            ->when($exceptMessageId, fn (Builder $query, int $id) => $query->whereKeyNot($id))
            ->where(fn (Builder $slot) => $slot
                ->where(fn (Builder $sent) => $sent->whereIn('status', ['sent', 'delivered', 'read'])->where('sent_at', '>=', $this->monthStart()))
                ->orWhere(fn (Builder $inFlight) => $inFlight->where('status', 'queued')->where('updated_at', '>', now()->subSeconds(self::IN_FLIGHT_SECONDS))))
            ->count();

        return $taken >= $this->monthlyCap();
    }

    /**
     * When a batch message due now may go out: the end of the company-local quiet window, or null outside it.
     * The window may cross midnight (21:00-07:00) or sit within one day; equal times mean no window.
     */
    public function quietDelay(): ?CarbonImmutable
    {
        $now = CarbonImmutable::now($this->timezone());
        $start = $now->setTimeFromTimeString($this->time('whatsapp_quiet_start'));
        $end = $now->setTimeFromTimeString($this->time('whatsapp_quiet_end'));

        if ($start->equalTo($end)) {
            return null;
        }

        if ($start->lessThan($end)) {
            return $now->greaterThanOrEqualTo($start) && $now->lessThan($end) ? $end : null;
        }

        return match (true) {
            $now->greaterThanOrEqualTo($start) => $end->addDay(),
            $now->lessThan($end) => $end,
            default => null,
        };
    }

    /** A stored H:i value, or the default when it is missing or malformed. */
    private function time(string $key): string
    {
        $value = $this->stored($key, null);

        return is_string($value) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) === 1
            ? $value
            : self::defaults()[$key];
    }

    private function stored(string $key, mixed $default): mixed
    {
        return ($this->company->settings ?? [])[$key] ?? $default;
    }
}
