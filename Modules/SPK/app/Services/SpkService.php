<?php

namespace Modules\SPK\Services;

use App\Models\User;
use App\Services\Core\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Modules\SPK\Models\WorkOrder;
use Modules\SPK\Models\WorkOrderAssignment;

class SpkService
{
    public static function generateCode(): string
    {
        $year = now()->year;
        $prefix = "SPK-{$year}-";

        $last = WorkOrder::withoutCompany()
            ->where('code', 'like', $prefix . '%')
            ->orderByDesc('code')
            ->lockForUpdate()
            ->first();

        $next = $last ? ((int) substr($last->code, strlen($prefix))) + 1 : 1;

        return $prefix . str_pad((string) $next, 5, '0', STR_PAD_LEFT);
    }

    public static function generate(WorkOrder $wo): WorkOrder
    {
        abort_if($wo->status !== 'draft', 422, 'SPK must be draft to generate.');

        return DB::transaction(function () use ($wo) {
            $wo->update([
                'status' => 'generated',
                'code' => $wo->code ?? self::generateCode(),
            ]);

            AuditService::log('work_order', 'generated', ['code' => $wo->code], $wo);

            return $wo->fresh();
        });
    }

    public static function assign(WorkOrder $wo, int $technicianId, int $assignedBy): WorkOrder
    {
        abort_if(! in_array($wo->status, ['generated', 'draft', 'rejected']), 422, 'SPK must be generated, draft, or rejected to assign.');

        return DB::transaction(function () use ($wo, $technicianId, $assignedBy) {
            // Deactivate previous active assignment
            WorkOrderAssignment::where('work_order_id', $wo->id)
                ->whereNull('unassigned_at')
                ->update(['unassigned_at' => now(), 'unassigned_by' => $assignedBy]);

            WorkOrderAssignment::create([
                'work_order_id' => $wo->id,
                'technician_id' => $technicianId,
                'assigned_by' => $assignedBy,
                'assigned_at' => now(),
            ]);

            $wo->update(['status' => 'assigned', 'assigned_to' => $technicianId]);

            AuditService::log('work_order', 'assigned', [
                'technician_id' => $technicianId,
            ], $wo);

            return $wo->fresh();
        });
    }

    public static function start(WorkOrder $wo): WorkOrder
    {
        abort_if($wo->status !== 'assigned', 422, 'SPK must be assigned to start.');
        abort_if(!$wo->assigned_to, 422, 'SPK has no assigned technician.');

        return DB::transaction(function () use ($wo) {
            $wo->update(['status' => 'in_progress', 'started_at' => now()]);

            AuditService::log('work_order', 'started', ['code' => $wo->code], $wo);

            return $wo->fresh();
        });
    }

    public static function submitForReview(WorkOrder $wo): WorkOrder
    {
        abort_if($wo->status !== 'in_progress', 422, 'SPK must be in progress to submit.');

        if ($wo->evidence()->count() === 0) {
            abort(422, 'Evidence required before submission.');
        }

        return DB::transaction(function () use ($wo) {
            $wo->update(['status' => 'waiting_review']);

            AuditService::log('work_order', 'submitted_for_review', ['code' => $wo->code], $wo);

            return $wo->fresh();
        });
    }

    public static function approve(WorkOrder $wo): WorkOrder
    {
        abort_if($wo->status !== 'waiting_review', 422, 'SPK must be waiting review to approve.');

        return DB::transaction(function () use ($wo) {
            $wo->update([
                'status' => 'completed',
                'completed_at' => now(),
            ]);

            // ponytail: CompleteSpkAction cross-module orchestration (IssueStock + InstallAsset + ActivateSubscription + CreateInvoice)
            // deferred to when Billing module exists (Phase 5). For now just mark completed.
            // CompleteSpkAction::execute($wo);

            AuditService::log('work_order', 'approved', ['code' => $wo->code], $wo);

            return $wo->fresh();
        });
    }

    public static function reject(WorkOrder $wo, string $reason): WorkOrder
    {
        abort_if($wo->status !== 'waiting_review', 422, 'SPK must be waiting review to reject.');

        return DB::transaction(function () use ($wo, $reason) {
            $wo->update([
                'status' => 'rejected',
                'rejection_reason' => $reason,
            ]);

            AuditService::log('work_order', 'rejected', [
                'code' => $wo->code, 'reason' => $reason,
            ], $wo);

            return $wo->fresh();
        });
    }

    public static function cancel(WorkOrder $wo, string $reason): WorkOrder
    {
        abort_if(in_array($wo->status, ['completed', 'cancelled']), 422, 'SPK already completed or cancelled.');

        return DB::transaction(function () use ($wo, $reason) {
            // ponytail: release reserved stock via StockService when inventory items exist
            // foreach ($wo->items as $item) { StockService::release(...); }

            $wo->update(['status' => 'cancelled']);

            AuditService::log('work_order', 'cancelled', [
                'code' => $wo->code, 'reason' => $reason,
            ], $wo);

            return $wo->fresh();
        });
    }
}
