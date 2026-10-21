<?php

namespace Modules\Ticketing\Notifications;

use App\Models\Core\Company;
use App\Notifications\Channels\FcmChannel;
use App\Services\Core\CompanyService;
use App\Support\MailBrand;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;
use Modules\Ticketing\Models\Ticket;

class TicketAssignedNotification extends Notification implements ShouldQueueAfterCommit
{
    use Queueable;

    /** Priority as the web names it (`resources/js/lib/statuses/common.ts`). */
    public const PRIORITY_LABELS = ['low' => 'Rendah', 'medium' => 'Sedang', 'high' => 'Tinggi', 'urgent' => 'Mendesak'];

    /** The longest ticket title a lock screen gets to show. */
    private const TITLE_LENGTH = 100;

    public function __construct(
        public readonly int $ticketId,
        public readonly string $ticketCode,
        public readonly ?int $companyId = null,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database', ...FcmChannel::for($notifiable)];
    }

    /** Everything is read when the mail is built, inside the worker: the job payload carries ids only. */
    public function toMail(object $notifiable): MailMessage
    {
        $ticket = $this->ticket();
        $mail = (new MailMessage())->subject("Tiket {$this->ticketCode} ditugaskan kepada Anda");

        if ($ticket === null) {
            return $mail->line("Tiket {$this->ticketCode} ditugaskan kepada Anda.");
        }

        $mail->line(MailBrand::literal($ticket->title));

        if ($ticket->customer !== null) {
            $mail->line('Pelanggan: '.MailBrand::literal($ticket->customer->name));
        }

        $mail->line('Prioritas: '.(self::PRIORITY_LABELS[$ticket->priority] ?? $ticket->priority));

        if (($due = $this->responseDue($ticket)) !== null) {
            $mail->line("Respons sebelum {$due}.");
        }

        return $mail->action('Buka tiket', route('admin.tickets.show', ['ticket' => $ticket]));
    }

    /**
     * The bell and the push: what NotificationResource turns into "Tiket TKT-1 · Tinggi" and "Judul. Respons sebelum 21.17."
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $ticket = $this->ticket();

        return [
            'type' => 'ticket_assigned',
            'ticket_id' => $ticket?->public_id,
            'ticket_code' => $this->ticketCode,
            'company_id' => $this->companyId,
        ] + ($ticket === null ? [] : array_filter([
            'priority' => $ticket->priority,
            'title' => Str::limit($ticket->title, self::TITLE_LENGTH),
            'response_due_local' => $this->responseDue($ticket),
        ], fn (?string $value): bool => $value !== null));
    }

    private function ticket(): ?Ticket
    {
        $ticket = Ticket::withoutCompany()->find($this->ticketId);

        // A worker has no company in scope, and the customer relation fails closed without one.
        return $ticket === null ? null : CompanyService::runAs((int) $ticket->company_id, fn (): Ticket => $ticket->load('customer:id,name'));
    }

    /** The first-response deadline on the company's clock: "21.17", "08.30 besok", or "22 Okt 08.30"; null when the ticket has none. */
    private function responseDue(Ticket $ticket): ?string
    {
        if ($ticket->response_deadline === null) {
            return null;
        }

        $timezone = Company::query()->whereKey($ticket->company_id)->value('timezone') ?: config('app.timezone');
        $due = $ticket->response_deadline->copy()->setTimezone($timezone);
        $today = now($timezone)->startOfDay();

        return match (true) {
            $due->isSameDay($today) => $due->format('H.i'),
            $due->isSameDay($today->copy()->addDay()) => $due->format('H.i').' besok',
            default => $due->locale('id')->translatedFormat('j M H.i'),
        };
    }
}
