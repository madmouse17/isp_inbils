<?php

namespace Tests\Feature\WhatsApp\Fixtures;

use App\Notifications\WhatsAppNotification;

/** Smallest possible WhatsApp notification, to drive the channel without any domain code. */
class ProbeNotification extends WhatsAppNotification
{
    public function __construct(
        int $companyId,
        public readonly string $dedupeKey = 'probe:1',
        public readonly string $eventKey = 'payment_received',
        public readonly bool $nothingToSend = false,
    ) {
        parent::__construct($companyId);
    }

    public function toWhatsApp(object $notifiable): ?array
    {
        if ($this->nothingToSend) {
            return null;
        }

        return $this->payload(
            $this->eventKey,
            ['PT Contoh Net', 'INV-2026-00012', 'Rp 250.000', 'Rp 0', '14 Okt 2026'],
            'invoice',
            12,
            $this->dedupeKey,
        );
    }

    protected function event(): string
    {
        return $this->eventKey;
    }
}
