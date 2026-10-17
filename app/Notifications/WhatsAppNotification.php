<?php

namespace App\Notifications;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\User;
use App\Notifications\Channels\FcmChannel;
use App\Notifications\Channels\WhatsAppChannel;
use App\Services\Core\CompanyService;
use App\Services\Core\SettingService;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Carbon;

/**
 * A WhatsApp template message. The notification carries ids only, so the job payload in the queue and in
 * failed_jobs never holds a phone number, a name or an amount: toWhatsApp() resolves everything inside the
 * job, in the company's context.
 *
 * Every delivery is its own job on the `whatsapp` queue (workers must listen to it), dispatched after the
 * surrounding transaction commits, so a rolled-back change sends nothing. Batch events (invoice issued,
 * reminders) wait out the company's quiet hours; the rest go out at once.
 */
abstract class WhatsAppNotification extends Notification implements ShouldQueueAfterCommit
{
    use Queueable;

    /** Releases by the rate limiter count as attempts too; the backoff below is for failures. */
    public int $tries = 5;

    public function __construct(public readonly int $companyId) {}

    /**
     * What to send, or null when there is nothing to send any more (the invoice got paid after the
     * reminder was queued): no row is written then. Build it with payload().
     *
     * @return array{event: string, template: string, language: string, parameters: list<string>, subject_type: ?string, subject_id: ?int, dedupe_key: string, batch: bool}|null
     */
    abstract public function toWhatsApp(object $notifiable): ?array;

    /** Key in config('whatsapp.events') of the message this instance will send. */
    abstract protected function event(): string;

    /** @return list<class-string|string> */
    public function via(object $notifiable): array
    {
        if (! $this->canUseDatabaseChannel($notifiable)) {
            return [WhatsAppChannel::class];
        }

        // The push is the phone's twin of the bell row: only for an app user, and only for the events that opt in.
        return [WhatsAppChannel::class, 'database', ...($this->pushesToApp() ? FcmChannel::for($notifiable) : [])];
    }

    /** Whether this event also goes to the phone (FcmChannel). The events of the customer and technician apps say yes. */
    protected function pushesToApp(): bool
    {
        return false;
    }

    /** @return array<class-string, string> */
    public function viaQueues(): array
    {
        return [WhatsAppChannel::class => 'whatsapp'];
    }

    public function withDelay(object $_notifiable, string $channel): ?DateTimeInterface
    {
        return $channel === WhatsAppChannel::class && config('whatsapp.events.'.$this->event().'.batch')
            ? WhatsAppChannel::quietDelay($this->companyId)
            : null;
    }

    /** @return list<RateLimited> */
    public function middleware(object $_notifiable, string $channel): array
    {
        return $channel === WhatsAppChannel::class ? [new RateLimited('whatsapp-send')] : [];
    }

    /** The bell row and the push exist only when there is something to say; the other channels decide for themselves. */
    public function shouldSend(object $notifiable, string $channel): bool
    {
        return ! in_array($channel, ['database', FcmChannel::class], true) || $this->payloadFor($notifiable) !== null;
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $payload = $this->payloadFor($notifiable);

        return $payload === null ? ['type' => $this->event()] : $this->bell($payload);
    }

    /**
     * What the bell row holds: the event and the id of its subject, nothing else.
     *
     * @param  array{event: string, template: string, language: string, parameters: list<string>, subject_type: ?string, subject_id: ?int, dedupe_key: string, batch: bool}  $payload
     * @return array<string, mixed>
     */
    protected function bell(array $payload): array
    {
        $subjectType = $payload['subject_type'];
        $subjectId = $payload['subject_id'];

        return ['type' => $payload['event']]
            + ($subjectType !== null && $subjectId !== null ? [$subjectType.'_id' => $subjectId] : []);
    }

    /**
     * @return array{event: string, template: string, language: string, parameters: list<string>, subject_type: ?string, subject_id: ?int, dedupe_key: string, batch: bool}|null
     */
    private function payloadFor(object $notifiable): ?array
    {
        return CompanyService::runAs($this->companyId, fn (): ?array => $this->toWhatsApp($notifiable));
    }

    private function canUseDatabaseChannel(object $notifiable): bool
    {
        return $notifiable instanceof User || ($notifiable instanceof Customer && $notifiable->user_id !== null);
    }

    /** @return list<int> seconds before attempts 2 to 5 */
    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    /**
     * @param  list<string>  $parameters  in the order of config('whatsapp.events.<event>.parameters')
     * @return array{event: string, template: string, language: string, parameters: list<string>, subject_type: ?string, subject_id: ?int, dedupe_key: string, batch: bool}
     */
    protected function payload(string $event, array $parameters, ?string $subjectType, ?int $subjectId, string $dedupeKey): array
    {
        $template = config('whatsapp.events.'.$event);

        return [
            'event' => $event,
            'template' => $template['template'],
            'language' => $template['language'],
            'parameters' => $parameters,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'dedupe_key' => $dedupeKey,
            'batch' => (bool) $template['batch'],
        ];
    }

    protected function companyName(): string
    {
        return Company::query()->findOrFail($this->companyId)->name;
    }

    /** The company's local timezone, for dates that come from timestamps (a payment's paid_at). */
    protected function companyTimezone(): string
    {
        return Company::query()->findOrFail($this->companyId)->timezone ?: (string) config('app.timezone');
    }

    /** "14 Okt 2026": the company's date format, in Indonesian. */
    protected function formatDate(DateTimeInterface|string $date): string
    {
        $format = data_get(Company::query()->findOrFail($this->companyId)->settings, 'date_format') ?: SettingService::get('default_date_format', 'd M Y');

        return Carbon::parse($date)->locale('id')->translatedFormat($format);
    }

    /** "Rp 250.000"; cents only when there are some. */
    protected function formatMoney(string|int|float $amount): string
    {
        $decimals = fmod((float) $amount, 1.0) === 0.0 ? 0 : 2;

        return 'Rp '.number_format((float) $amount, $decimals, ',', '.');
    }
}
