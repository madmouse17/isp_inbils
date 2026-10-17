<?php

namespace Modules\SPK\Notifications;

use App\Notifications\WhatsAppNotification;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\SPK\Models\WorkOrder;

/**
 * WhatsApp about an SPK assignment. To the technician: code, title, visit date and a link to the SPK (login
 * required), never the customer's phone, name or address. To the customer: the visit date and the technician's
 * first name, never the technician's phone. Carries ids only. The visit date has no time slot: scheduled_date
 * is a date.
 */
class WorkOrderScheduledNotification extends WhatsAppNotification
{
    public const AUDIENCES = ['technician', 'customer'];

    public function __construct(int $companyId, public readonly int $workOrderId, public readonly string $audience)
    {
        if (! in_array($audience, self::AUDIENCES, true)) {
            throw new InvalidArgumentException('Unknown SPK audience '.$audience.'.');
        }

        parent::__construct($companyId);
    }

    public function toWhatsApp(object $notifiable): ?array
    {
        $workOrder = WorkOrder::query()->with('assignee')->find($this->workOrderId);

        if ($workOrder === null) {
            return null;
        }

        return $this->audience === 'technician'
            ? $this->forTechnician($workOrder, (int) $notifiable->getKey())
            : $this->forCustomer($workOrder);
    }

    protected function event(): string
    {
        return $this->audience === 'technician' ? 'spk_scheduled' : 'spk_scheduled_customer';
    }

    /** The technician's phone hears of an assignment; the customer's visit date stays WhatsApp and the bell. */
    protected function pushesToApp(): bool
    {
        return $this->audience === 'technician';
    }

    /** @return array<string, mixed>|null */
    private function forTechnician(WorkOrder $workOrder, int $technicianId): ?array
    {
        // Reassigned before this job ran (a retry after an outage, say): the new technician has their own message.
        if ((int) $workOrder->assigned_to !== $technicianId) {
            return null;
        }

        return $this->payload('spk_scheduled', [
            $workOrder->code,
            $workOrder->title,
            $workOrder->scheduled_date === null ? 'belum dijadwalkan' : $this->formatDate($workOrder->scheduled_date),
            route('admin.spk.show', $workOrder),
        ], 'work_order', $workOrder->id, 'spk_scheduled:'.$workOrder->id.':'.$technicianId);
    }

    /** @return array<string, mixed>|null */
    private function forCustomer(WorkOrder $workOrder): ?array
    {
        if ($workOrder->scheduled_date === null || $workOrder->assignee === null) {
            return null;
        }

        return $this->payload('spk_scheduled_customer', [
            $this->companyName(),
            $workOrder->code,
            $this->formatDate($workOrder->scheduled_date),
            Str::of($workOrder->assignee->name)->trim()->before(' ')->toString(),
        ], 'work_order', $workOrder->id, 'spk_scheduled_customer:'.$workOrder->id);
    }
}
