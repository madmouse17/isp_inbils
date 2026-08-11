<?php

namespace Modules\SPK\Services;

use App\Services\Core\AuditService;
use App\Services\Core\CompanyService;
use App\Services\Core\NumberSequenceService;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\Product;
use Modules\NetworkAsset\Models\NetworkAsset;
use Modules\NetworkAsset\Models\NetworkAssetInstallation;
use Modules\SPK\Actions\CompleteSpkAction;
use Modules\SPK\Models\WorkOrder;
use Modules\SPK\Models\WorkOrderAssignment;
use Modules\SPK\Models\WorkOrderItem;

class SpkService
{
    public static function addItem(WorkOrder $wo, array $data): WorkOrderItem
    {
        $data['quantity_reserved'] ??= 0;
        $data['quantity_used'] ??= 0;

        return DB::transaction(function () use ($wo, $data) {
            $wo = self::lockedWorkOrder($wo->id);
            self::assertCompany($wo);
            self::assertItemReferences($wo, $data);

            return WorkOrderItem::updateOrCreate(
                ['work_order_id' => $wo->id, 'product_id' => $data['product_id']],
                $data
            );
        });
    }

    public static function generate(WorkOrder $wo): WorkOrder
    {
        return DB::transaction(function () use ($wo) {
            $wo = self::lockedWorkOrder($wo->id);
            self::assertCompany($wo);
            abort_if($wo->status !== 'draft', 422, 'SPK must be draft to generate.');

            $wo->update([
                'status' => 'generated',
                'code' => $wo->code ?? NumberSequenceService::generate('spk', 'SPK', self::companyId()),
            ]);

            AuditService::log('work_order', 'generated', ['code' => $wo->code], $wo);

            return $wo->fresh();
        });
    }

    public static function assign(WorkOrder $wo, int $technicianId, int $assignedBy): WorkOrder
    {
        abort_if(! in_array($wo->status, ['generated', 'draft', 'rejected']), 422, 'SPK must be generated, draft, or rejected to assign.');

        return DB::transaction(function () use ($wo, $technicianId, $assignedBy) {
            $wo = self::lockedWorkOrder($wo->id);
            self::assertCompany($wo);

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
        abort_if(! $wo->assigned_to, 422, 'SPK has no assigned technician.');

        return DB::transaction(function () use ($wo) {
            $wo = self::lockedWorkOrder($wo->id);
            self::assertCompany($wo);
            $wo->update(['status' => 'in_progress', 'started_at' => now()]);

            AuditService::log('work_order', 'started', ['code' => $wo->code], $wo);

            return $wo->fresh();
        });
    }

    public static function submitForReview(WorkOrder $wo): WorkOrder
    {
        abort_if($wo->status !== 'in_progress', 422, 'SPK must be in progress to submit.');

        if ($wo->getMedia('evidence')->count() === 0) {
            abort(422, 'Evidence required before submission.');
        }

        return DB::transaction(function () use ($wo) {
            $wo = self::lockedWorkOrder($wo->id);
            self::assertCompany($wo);
            $wo->update(['status' => 'waiting_review']);

            AuditService::log('work_order', 'submitted_for_review', ['code' => $wo->code], $wo);

            return $wo->fresh();
        });
    }

    public static function approve(WorkOrder $wo): WorkOrder
    {
        abort_if($wo->status !== 'waiting_review', 422, 'SPK must be waiting review to approve.');

        return DB::transaction(function () use ($wo) {
            $wo = self::lockedWorkOrder($wo->id);
            self::assertCompany($wo);
            $wo->update([
                'status' => 'completed',
                'completed_at' => now(),
            ]);

            CompleteSpkAction::execute($wo);

            AuditService::log('work_order', 'approved', ['code' => $wo->code], $wo);

            return $wo->fresh();
        });
    }

    public static function reject(WorkOrder $wo, string $reason): WorkOrder
    {
        abort_if($wo->status !== 'waiting_review', 422, 'SPK must be waiting review to reject.');

        return DB::transaction(function () use ($wo, $reason) {
            $wo = self::lockedWorkOrder($wo->id);
            self::assertCompany($wo);
            CompleteSpkAction::releaseReservedStock($wo);

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
            $wo = self::lockedWorkOrder($wo->id);
            self::assertCompany($wo);
            CompleteSpkAction::releaseReservedStock($wo);

            $wo->update(['status' => 'cancelled']);

            AuditService::log('work_order', 'cancelled', [
                'code' => $wo->code, 'reason' => $reason,
            ], $wo);

            return $wo->fresh();
        });
    }

    /** @param array<string, mixed> $data */
    private static function assertItemReferences(WorkOrder $wo, array $data): void
    {
        $product = Product::withoutCompany()
            ->where('company_id', $wo->company_id)
            ->where('is_active', true)
            ->lockForUpdate()
            ->findOrFail($data['product_id'] ?? null);

        $assetId = $data['network_asset_id'] ?? null;
        if (! $assetId) {
            abort_if($product->type === 'asset', 422, 'Serialized asset item requires a selected network asset.');

            return;
        }

        $asset = NetworkAsset::withoutCompany()
            ->where('company_id', $wo->company_id)
            ->lockForUpdate()
            ->findOrFail($assetId);

        abort_unless((int) $asset->product_id === (int) $product->id, 422, 'Selected network asset must match the SPK item product.');
        abort_unless($asset->status === 'available', 422, 'Selected network asset must be available.');
        abort_unless($product->type === 'asset', 422, 'Selected product must be a serialized asset.');

        if ($wo->subscription_id && $asset->subscription_id !== null) {
            abort_unless((int) $asset->subscription_id === (int) $wo->subscription_id, 422, 'Selected network asset must belong to the SPK subscription.');
        }

        abort_if(
            NetworkAssetInstallation::withoutCompany()
                ->where('company_id', $wo->company_id)
                ->where('network_asset_id', $asset->id)
                ->whereNull('removed_at')
                ->lockForUpdate()
                ->exists(),
            422,
            'Selected network asset already has an active installation.'
        );
    }

    private static function companyId(): int
    {
        $companyId = CompanyService::currentId();
        abort_if($companyId === null, 403, 'Company context is required.');

        return $companyId;
    }

    private static function lockedWorkOrder(int $id): WorkOrder
    {
        return WorkOrder::withoutCompany()->lockForUpdate()->findOrFail($id);
    }

    private static function assertCompany(WorkOrder $wo): void
    {
        abort_unless($wo->company_id === self::companyId(), 404);
    }
}
