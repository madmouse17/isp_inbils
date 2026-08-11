<?php

namespace Modules\Ticketing\Services;

use App\Models\Core\Customer;
use App\Models\Core\Location;
use App\Models\Core\ServiceSubscription;
use App\Models\User;
use App\Services\Core\AuditService;
use App\Services\Core\CompanyService;
use App\Services\Core\NumberSequenceService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Modules\NetworkAsset\Models\NetworkAsset;
use Modules\SPK\Models\WorkOrder;
use Modules\Ticketing\Models\Ticket;
use Modules\Ticketing\Models\TicketCategory;
use Modules\Ticketing\Notifications\TicketAssignedNotification;

class TicketService
{
    public static function create(array $data, int $createdBy): Ticket
    {
        return DB::transaction(function () use ($data, $createdBy) {
            $companyId = CompanyService::currentId();
            abort_if($companyId === null, 403, 'Company context is required.');

            $category = TicketCategory::withoutCompany()->where('company_id', $companyId)->lockForUpdate()->findOrFail($data['category_id'] ?? null);
            self::assertCreateReferences($data, $companyId, $category);
            $priority = $data['priority'] ?? $category->default_priority ?? 'medium';

            $data['company_id'] = $companyId;
            $data['category_id'] = $category->id;
            $data['code'] = self::generateCode();
            $data['status'] = $data['status'] ?? 'open';
            $data['created_by'] = $createdBy;
            $data['sla_deadline'] = self::computeSlaDeadline($category, $priority);

            $ticket = Ticket::create($data);

            AuditService::log('ticket', 'created', [
                'code' => $ticket->code,
                'category_id' => $ticket->category_id,
            ], $ticket);

            return $ticket->fresh();
        });
    }

    public static function computeSlaDeadline(TicketCategory $category, string $priority): Carbon
    {
        $hours = (int) $category->default_sla_hours;

        if ($priority === 'urgent') {
            $hours = (int) ceil($hours / 2);
        }

        return now()->addHours($hours);
    }

    public static function generateCode(): string
    {
        $year = now()->year;
        $prefix = "TKT-{$year}-";

        $last = Ticket::forCompany(CompanyService::currentId())
            ->where('code', 'like', $prefix.'%')
            ->orderByDesc('code')
            ->lockForUpdate()
            ->first();

        $next = $last ? ((int) substr($last->code, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $next, 5, '0', STR_PAD_LEFT);
    }

    public static function assign(Ticket $ticket, int $handlerId, int $assignedBy): Ticket
    {
        abort_if(! in_array($ticket->status, ['open', 'assigned']), 422, 'Ticket must be open or assigned to assign.');

        return DB::transaction(function () use ($ticket, $handlerId, $assignedBy) {
            $ticket = self::lockedTicket($ticket->id);
            self::assertCompany($ticket);

            $handler = User::query()->findOrFail($handlerId);
            abort_unless((int) $handler->company_id === (int) $ticket->company_id, 422, 'Handler must belong to the same company.');
            abort_unless($handler->is_active && $handler->hasAnyRole(['admin', 'manager', 'noc', 'staff', 'technician']), 422, 'Handler must be an active eligible user.');

            $previousHandlerId = $ticket->assigned_to;
            $ticket->update(['assigned_to' => $handlerId, 'status' => 'assigned']);

            if ($previousHandlerId !== $handlerId) {
                Notification::send($handler, new TicketAssignedNotification($ticket->id, $ticket->code, $ticket->company_id));
            }

            AuditService::log('ticket', 'assigned', [
                'handler_id' => $handlerId,
                'assigned_by' => $assignedBy,
            ], $ticket);

            return $ticket->fresh();
        });
    }

    public static function startWork(Ticket $ticket): Ticket
    {
        abort_if(! in_array($ticket->status, ['open', 'assigned']), 422, 'Ticket must be open or assigned to start.');

        return DB::transaction(function () use ($ticket) {
            $ticket = self::lockedTicket($ticket->id);
            self::assertCompany($ticket);
            $ticket->update([
                'status' => 'on_progress',
                'first_response_at' => $ticket->first_response_at ?? now(),
            ]);

            AuditService::log('ticket', 'started', ['code' => $ticket->code], $ticket);

            return $ticket->fresh();
        });
    }

    public static function resolve(Ticket $ticket, string $resolutionNote): Ticket
    {
        abort_if(! in_array($ticket->status, ['on_progress', 'assigned']), 422, 'Ticket must be on progress or assigned to resolve.');

        return DB::transaction(function () use ($ticket, $resolutionNote) {
            $ticket = self::lockedTicket($ticket->id);
            self::assertCompany($ticket);
            $ticket->update([
                'status' => 'resolved',
                'resolved_at' => now(),
                'resolution_note' => $resolutionNote,
            ]);

            AuditService::log('ticket', 'resolved', ['code' => $ticket->code], $ticket);

            return $ticket->fresh();
        });
    }

    public static function close(Ticket $ticket): Ticket
    {
        abort_if($ticket->status !== 'resolved', 422, 'Ticket must be resolved to close.');

        return DB::transaction(function () use ($ticket) {
            $ticket = self::lockedTicket($ticket->id);
            self::assertCompany($ticket);
            $ticket->update(['status' => 'closed', 'closed_at' => now()]);

            AuditService::log('ticket', 'closed', ['code' => $ticket->code], $ticket);

            return $ticket->fresh();
        });
    }

    public static function spawnSpk(Ticket $ticket): WorkOrder
    {
        abort_if($ticket->spawned_spk_id, 422, 'SPK already spawned for this ticket.');
        abort_if(! in_array($ticket->status, ['on_progress', 'assigned']), 422, 'Ticket must be on progress or assigned to spawn SPK.');

        return DB::transaction(function () use ($ticket) {
            $ticket = self::lockedTicket($ticket->id);
            self::assertCompany($ticket);
            $wo = WorkOrder::create([
                'code' => NumberSequenceService::generate('spk', 'SPK', $ticket->company_id),
                'type' => 'maintenance',
                'title' => 'SPK from Ticket '.$ticket->code,
                'description' => $ticket->description,
                'status' => 'generated',
                'customer_id' => $ticket->customer_id,
                'subscription_id' => $ticket->subscription_id,
                'location_id' => $ticket->location_id,
                'source' => 'ticket',
                'priority' => $ticket->priority,
                'created_by' => Auth::id(),
            ]);

            $ticket->update(['spawned_spk_id' => $wo->id]);

            AuditService::log('ticket', 'spawned_spk', ['ticket_id' => $ticket->id, 'spk_id' => $wo->id], $ticket);

            return $wo;
        });
    }

    /** @param array<string, mixed> $data */
    private static function assertCreateReferences(array $data, int $companyId, TicketCategory $category): void
    {
        $customerId = self::lockedCompanyId(Customer::class, $data['customer_id'] ?? null, $companyId);
        $subscription = self::lockedModel(ServiceSubscription::class, $data['subscription_id'] ?? null, $companyId);
        $asset = self::lockedModel(NetworkAsset::class, $data['network_asset_id'] ?? null, $companyId);
        self::lockedCompanyId(Location::class, $data['location_id'] ?? null, $companyId);
        abort_unless($category->company_id === $companyId, 404);

        if ($subscription && $customerId !== null) {
            abort_unless((int) $subscription->customer_id === (int) $customerId, 422, 'Subscription does not belong to customer.');
        }

        if ($asset && $customerId !== null && $asset->customer_id !== null) {
            abort_unless((int) $asset->customer_id === (int) $customerId, 422, 'Network asset does not belong to customer.');
        }

        if ($asset && $subscription && $asset->subscription_id !== null) {
            abort_unless((int) $asset->subscription_id === (int) $subscription->id, 422, 'Network asset does not belong to subscription.');
        }
    }

    /** @param class-string<Model> $model */
    private static function lockedCompanyId(string $model, mixed $id, int $companyId): ?int
    {
        return self::lockedModel($model, $id, $companyId)?->id;
    }

    /** @param class-string<Model> $model */
    private static function lockedModel(string $model, mixed $id, int $companyId): ?Model
    {
        if (! $id) {
            return null;
        }

        return $model::withoutCompany()
            ->where('company_id', $companyId)
            ->lockForUpdate()
            ->findOrFail($id);
    }

    private static function lockedTicket(int $ticketId): Ticket
    {
        return Ticket::withoutCompany()->lockForUpdate()->findOrFail($ticketId);
    }

    private static function assertCompany(Ticket $ticket): void
    {
        abort_unless($ticket->company_id === CompanyService::currentId(), 404);
    }
}
