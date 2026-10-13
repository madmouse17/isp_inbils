<?php

namespace Modules\SPK\Services;

use App\Models\Core\Customer;
use App\Models\Core\EmployeeProfile;
use App\Models\User;
use App\Services\Core\AuditService;
use App\Services\Core\CompanyService;
use App\Services\Core\NumberSequenceService;
use App\Support\FieldCheckIn;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Modules\Inventory\Models\Product;
use Modules\NetworkAsset\Models\NetworkAsset;
use Modules\NetworkAsset\Models\NetworkAssetInstallation;
use Modules\SPK\Actions\CompleteSpkAction;
use Modules\SPK\Models\WorkOrder;
use Modules\SPK\Models\WorkOrderAssignment;
use Modules\SPK\Models\WorkOrderItem;
use Modules\SPK\Notifications\WorkOrderScheduledNotification;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class SpkService
{
    private const MISSING_EXPECTED_ASSIGNED_TO = '__missing_expected_assigned_to__';

    private const MISSING_EXPECTED_UPDATED_AT = '__missing_expected_updated_at__';

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
                'code' => $wo->code ?? NumberSequenceService::generate('spk', self::companyId()),
            ]);

            AuditService::log('work_order', 'generated', ['code' => $wo->code], $wo);

            return $wo->fresh();
        });
    }

    public static function assign(WorkOrder $wo, int $technicianId, int $assignedBy, mixed $expectedAssignedTo = self::MISSING_EXPECTED_ASSIGNED_TO, mixed $expectedUpdatedAt = self::MISSING_EXPECTED_UPDATED_AT): WorkOrder
    {
        return DB::transaction(function () use ($wo, $technicianId, $assignedBy, $expectedAssignedTo, $expectedUpdatedAt) {
            abort_if(
                $expectedAssignedTo === self::MISSING_EXPECTED_ASSIGNED_TO
                || $expectedUpdatedAt === self::MISSING_EXPECTED_UPDATED_AT,
                422,
                'Expected assignment state is required.'
            );
            abort_unless(
                $expectedAssignedTo === null || is_int($expectedAssignedTo),
                422,
                'Expected assignee must be an integer or null.'
            );
            self::assertCanonicalExpectedUpdatedAt($expectedUpdatedAt);
            $wo = self::lockedWorkOrder($wo->id);
            self::assertCompany($wo);
            abort_if(! in_array($wo->status, ['generated', 'draft', 'rejected', 'assigned']), 422, 'SPK must be generated, draft, rejected, or assigned to assign.');

            $ability = $wo->assigned_to === null ? 'spk.assign' : 'spk.reassign';
            self::assertAssignmentActor($assignedBy, $wo->company_id, $ability);

            if ($expectedAssignedTo === null) {
                $assignmentMatches = $wo->assigned_to === null;
            } else {
                $assignmentMatches = (int) $wo->assigned_to === (int) $expectedAssignedTo;
            }

            if (! $assignmentMatches) {
                abort(409, 'Work order assignment conflict: item has been assigned by another user.');
            }

            $canonicalUpdatedAt = $wo->updated_at?->utc()->format('Y-m-d\TH:i:s.v\Z');
            if ($expectedUpdatedAt === null || $canonicalUpdatedAt !== $expectedUpdatedAt) {
                throw new ConflictHttpException(
                    'Work order assignment conflict: item has been assigned by another user.',
                    null,
                    409
                );
            }

            $companySettings = data_get($wo->company, 'settings', []);
            if (($companySettings['forbid_self_assignment'] ?? false) && (int) $assignedBy === (int) $technicianId) {
                abort(422, 'Self-assignment is forbidden by company policy.');
            }

            $technician = User::query()
                ->where('company_id', $wo->company_id)
                ->where('is_active', true)
                ->whereHas('employeeProfile', fn ($query) => $query->where('company_id', $wo->company_id)->where('status', 'active'))
                ->where(function ($query) {
                    $query->whereHas('permissions', fn ($permissionQuery) => $permissionQuery->whereIn('name', ['spk.start', 'spk.submit']))
                        ->orWhereHas('roles.permissions', fn ($permissionQuery) => $permissionQuery->whereIn('name', ['spk.start', 'spk.submit']));
                })
                ->findOrFail($technicianId);

            // Deactivate previous active assignment
            WorkOrderAssignment::where('work_order_id', $wo->id)
                ->whereNull('unassigned_at')
                ->update(['unassigned_at' => now(), 'unassigned_by' => $assignedBy]);

            WorkOrderAssignment::create([
                'work_order_id' => $wo->id,
                'technician_id' => $technician->id,
                'assigned_by' => $assignedBy,
                'assigned_at' => now(),
            ]);

            $previousTechnicianId = $wo->assigned_to;
            $wo->update(['status' => 'assigned', 'assigned_to' => $technician->id]);

            AuditService::log('work_order', 'assigned', [
                'technician_id' => $technician->id,
                'assigned_by' => $assignedBy,
            ], $wo);

            self::notifyScheduled($wo, $technician, $previousTechnicianId);

            return $wo->fresh();
        });
    }

    /**
     * WhatsApp about an assignment, queued after the surrounding transaction commits: to the technician whenever
     * the technician changes, and to the customer once, on the first assignment, when the SPK has a customer and
     * a visit date.
     */
    private static function notifyScheduled(WorkOrder $wo, User $technician, ?int $previousTechnicianId): void
    {
        if ($previousTechnicianId !== $technician->id) {
            Notification::send($technician, new WorkOrderScheduledNotification($wo->company_id, $wo->id, 'technician'));
        }

        $customer = $previousTechnicianId === null && $wo->scheduled_date !== null && $wo->customer_id !== null
            ? Customer::query()->find($wo->customer_id)
            : null;

        if ($customer !== null) {
            Notification::send($customer, new WorkOrderScheduledNotification($wo->company_id, $wo->id, 'customer'));
        }
    }

    /** @param array<string, mixed>|null $checkIn */
    public static function start(WorkOrder $wo, ?array $checkIn = null): WorkOrder
    {
        $expectedStatus = $wo->status;
        self::assertAssignedTechnician($wo, 'spk.start');

        return DB::transaction(function () use ($wo, $expectedStatus, $checkIn) {
            $wo = self::lockedWorkOrder($wo->id);
            self::assertCompany($wo);
            self::assertAssignedTechnician($wo, 'spk.start');
            self::throwStatusConflictIfChanged($wo, $expectedStatus, 'assigned', 'SPK start conflict: item state changed by another user.');
            abort_if($wo->status !== 'assigned', 422, 'SPK must be assigned to start.');
            $wo->update(['status' => 'in_progress', 'started_at' => now()]);

            AuditService::log('work_order', 'started', ['code' => $wo->code], $wo);

            if ($checkIn !== null) {
                $wo->loadMissing(['location', 'subscription.installationAddress']);
                AuditService::log(
                    'work_order',
                    'check_in',
                    FieldCheckIn::properties($checkIn, FieldCheckIn::site($wo->location, $wo->subscription?->installationAddress)),
                    $wo
                );
            }

            return $wo->fresh();
        });
    }

    /**
     * @param  array{file: UploadedFile, caption?: ?string, captured_at?: ?string}  $data
     */
    public static function addEvidence(WorkOrder $wo, User $actor, array $data, ?string $idempotencyKey = null): Media
    {
        return DB::transaction(function () use ($wo, $actor, $data, $idempotencyKey) {
            $wo = self::lockedWorkOrder($wo->id);
            self::assertCompany($wo);
            Gate::forUser($actor)->authorize('uploadEvidence', $wo);

            if ($idempotencyKey !== null) {
                $existing = $wo->getMedia('evidence')->first(
                    fn (Media $media): bool => $media->getCustomProperty('idempotency_key') === $idempotencyKey
                );
                if ($existing instanceof Media) {
                    return $existing;
                }
            }

            $file = $data['file'];
            $properties = [
                'company_id' => $wo->company_id,
                'type' => str((string) $file->getMimeType())->startsWith('image/') ? 'photo' : 'document',
                'caption' => $data['caption'] ?? null,
                'uploaded_by' => $actor->id,
            ];

            if (($data['captured_at'] ?? null) !== null) {
                $properties['captured_at'] = $data['captured_at'];
            }
            if ($idempotencyKey !== null) {
                $properties['idempotency_key'] = $idempotencyKey;
            }

            $media = $wo->addMedia($file)
                ->withCustomProperties($properties)
                ->toMediaCollection('evidence');

            AuditService::log('work_order', 'evidence_uploaded', ['media_id' => $media->id, 'uploaded_by' => $actor->id], $wo);

            return $media;
        });
    }

    public static function removeEvidence(WorkOrder $wo, int $mediaId, User $actor): void
    {
        DB::transaction(function () use ($wo, $mediaId, $actor): void {
            $wo = self::lockedWorkOrder($wo->id);
            self::assertCompany($wo);
            $media = $wo->getMedia('evidence')->first(fn (Media $item): bool => (int) $item->id === $mediaId);
            abort_unless($media instanceof Media, 404);
            Gate::forUser($actor)->authorize('removeEvidence', [$wo, $media]);

            $media->delete();

            AuditService::log('work_order', 'evidence_removed', ['media_id' => $mediaId, 'removed_by' => $actor->id], $wo);
        });
    }

    /**
     * What the assigned technician used on site: sets the used quantity of a product's line (one line per product,
     * as on the web) and leaves a planned reservation on it alone. Only while the SPK is in progress, so the manager
     * reviewing the SPK sees the lines as the technician left them.
     *
     * @param  array{product_id: int, quantity: numeric-string|float|int}  $data
     */
    public static function addMaterial(WorkOrder $wo, User $actor, array $data): WorkOrderItem
    {
        return DB::transaction(function () use ($wo, $actor, $data): WorkOrderItem {
            $wo = self::lockedWorkOrder($wo->id);
            self::assertCompany($wo);
            Gate::forUser($actor)->authorize('submit', $wo);
            self::assertItemReferences($wo, ['product_id' => $data['product_id']]);

            $item = WorkOrderItem::query()->firstOrNew(['work_order_id' => $wo->id, 'product_id' => $data['product_id']]);
            $item->quantity_used = $data['quantity'];
            $item->save();

            AuditService::log('work_order', 'item_recorded', ['product_id' => $item->product_id, 'quantity_used' => $item->quantity_used, 'recorded_by' => $actor->id], $wo);

            return $item;
        });
    }

    /**
     * Takes back what the technician recorded and never erases the manager's plan: the used quantity goes to 0, and
     * the line is deleted only when nothing else is on it. A reservation or a note keeps it; a serialized asset line
     * is not the app's to touch (it cannot add one either). Withdrawing twice changes nothing.
     */
    public static function removeMaterial(WorkOrder $wo, int $itemId, User $actor): void
    {
        DB::transaction(function () use ($wo, $itemId, $actor): void {
            $wo = self::lockedWorkOrder($wo->id);
            self::assertCompany($wo);
            Gate::forUser($actor)->authorize('submit', $wo);
            $item = $wo->items()->find($itemId);
            abort_unless($item instanceof WorkOrderItem, 404);
            abort_if($item->network_asset_id !== null, 422, 'A serialized asset line is managed by the office.');

            if ((float) $item->quantity_reserved > 0 || filled($item->note)) {
                $previous = $item->quantity_used;
                $item->update(['quantity_used' => 0]);

                if ($item->wasChanged('quantity_used')) {
                    AuditService::log('work_order', 'item_usage_cleared', ['product_id' => $item->product_id, 'previous_quantity_used' => $previous, 'cleared_by' => $actor->id], $wo);
                }

                return;
            }

            $item->delete();

            AuditService::log('work_order', 'item_removed', ['product_id' => $item->product_id, 'removed_by' => $actor->id], $wo);
        });
    }

    public static function submitForReview(WorkOrder $wo): WorkOrder
    {
        $expectedStatus = $wo->status;
        self::assertAssignedTechnician($wo, 'spk.submit');

        if ($wo->getMedia('evidence')->count() === 0) {
            abort(422, 'Evidence required before submission.');
        }

        // Check if there are associated inventory requests. If any, all must have completed declaration.
        self::assertMaterialReturnDeclarationComplete($wo);

        return DB::transaction(function () use ($wo, $expectedStatus) {
            $wo = self::lockedWorkOrder($wo->id);
            self::assertCompany($wo);
            self::assertAssignedTechnician($wo, 'spk.submit');
            self::throwStatusConflictIfChanged($wo, $expectedStatus, 'in_progress', 'SPK submit conflict: item state changed by another user.');
            abort_if($wo->status !== 'in_progress', 422, 'SPK must be in progress to submit.');
            abort_if($wo->getMedia('evidence')->count() === 0, 422, 'Evidence required before submission.');
            $wo->update(['status' => 'waiting_review']);

            AuditService::log('work_order', 'submitted_for_review', ['code' => $wo->code], $wo);

            return $wo->fresh();
        });
    }

    public static function approve(WorkOrder $wo): WorkOrder
    {
        self::assertCapability('spk.approve');
        abort_if($wo->status !== 'waiting_review', 422, 'SPK must be waiting review to approve.');
        self::assertMaterialReturnReconciledOrResolved($wo);

        return DB::transaction(function () use ($wo) {
            $wo = self::lockedWorkOrder($wo->id);
            abort_if($wo->status !== 'waiting_review', 422, 'SPK must be waiting review to approve.');
            self::assertCompany($wo);
            self::assertCapability('spk.approve');
            abort_if(Auth::id() === $wo->created_by, 422, 'Creator cannot approve own SPK.');
            abort_if(Auth::id() === $wo->assigned_to, 422, 'Assigned technician cannot approve own SPK.');
            self::assertMaterialReturnReconciledOrResolved($wo);
            $wo->update([
                'status' => 'completed',
                'completed_at' => now(),
                'approved_by' => Auth::id(),
                'approved_at' => now(),
            ]);

            CompleteSpkAction::execute($wo);

            AuditService::log('work_order', 'approved', ['code' => $wo->code], $wo);

            return $wo->fresh();
        });
    }

    public static function reject(WorkOrder $wo, string $reason): WorkOrder
    {
        self::assertCapability('spk.reject');
        abort_if($wo->status !== 'waiting_review', 422, 'SPK must be waiting review to reject.');

        return DB::transaction(function () use ($wo, $reason) {
            $wo = self::lockedWorkOrder($wo->id);
            abort_if($wo->status !== 'waiting_review', 422, 'SPK must be waiting review to reject.');
            self::assertCompany($wo);
            self::assertCapability('spk.reject');
            abort_if(Auth::id() === $wo->created_by, 422, 'Creator cannot reject own SPK.');
            abort_if(Auth::id() === $wo->assigned_to, 422, 'Assigned technician cannot reject own SPK.');
            CompleteSpkAction::releaseReservedStock($wo);

            $wo->update([
                'status' => 'rejected',
                'rejection_reason' => $reason,
                'rejected_by' => Auth::id(),
                'rejected_at' => now(),
            ]);

            AuditService::log('work_order', 'rejected', [
                'code' => $wo->code, 'reason' => $reason,
            ], $wo);

            return $wo->fresh();
        });
    }

    public static function cancel(WorkOrder $wo, string $reason): WorkOrder
    {
        self::assertCapability('spk.cancel');
        abort_if(in_array($wo->status, ['completed', 'cancelled']), 422, 'SPK already completed or cancelled.');

        return DB::transaction(function () use ($wo, $reason) {
            $wo = self::lockedWorkOrder($wo->id);
            self::assertCompany($wo);
            self::assertCapability('spk.cancel');
            abort_if(in_array($wo->status, ['completed', 'cancelled']), 422, 'SPK already completed or cancelled.');
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
        return WorkOrder::withoutCompany()
            ->where('company_id', self::companyId())
            ->lockForUpdate()
            ->findOrFail($id);
    }

    private static function assertMaterialReturnDeclarationComplete(WorkOrder $wo): void
    {
        // Find if any issued inventory requests exist for this work order
        $issuedRequestIds = DB::table('inventory_requests')
            ->where('company_id', $wo->company_id)
            ->where('source_type', 'work_order')
            ->where('source_id', $wo->id)
            ->where('status', 'issued')
            ->pluck('id');

        if ($issuedRequestIds->isEmpty()) {
            return;
        }

        // Check if there are material return cases awaiting declaration
        $pendingDeclarationCount = DB::table('material_return_cases')
            ->where('company_id', $wo->company_id)
            ->whereIn('inventory_request_id', $issuedRequestIds)
            ->whereIn('status', ['awaiting_declaration', 'correction_required'])
            ->count();

        abort_if(
            $pendingDeclarationCount > 0,
            422,
            'Seluruh sisa material dari Inventory Request harus dideklarasikan terlebih dahulu sebelum submit SPK.'
        );
    }

    private static function assertMaterialReturnReconciledOrResolved(WorkOrder $wo): void
    {
        $issuedRequestIds = DB::table('inventory_requests')
            ->where('company_id', $wo->company_id)
            ->where('source_type', 'work_order')
            ->where('source_id', $wo->id)
            ->where('status', 'issued')
            ->pluck('id');

        if ($issuedRequestIds->isEmpty()) {
            return;
        }

        $unreconciledCount = DB::table('material_return_cases')
            ->where('company_id', $wo->company_id)
            ->whereIn('inventory_request_id', $issuedRequestIds)
            ->whereNotIn('status', ['reconciled', 'resolved'])
            ->count();

        abort_if(
            $unreconciledCount > 0,
            422,
            'SPK tidak dapat disetujui (fail-closed) sampai seluruh retur material berstatus reconciled atau resolved.'
        );
    }

    private static function assertCompany(WorkOrder $wo): void
    {
        abort_unless($wo->company_id === self::companyId(), 404);
    }

    private static function assertCanonicalExpectedUpdatedAt(mixed $expectedUpdatedAt): void
    {
        abort_unless(
            is_string($expectedUpdatedAt)
            && ($parsed = \DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i:s.v\\Z', $expectedUpdatedAt)) !== false
            && $parsed->format('Y-m-d\\TH:i:s.v\\Z') === $expectedUpdatedAt,
            422,
            'Expected updated timestamp must use canonical UTC format.'
        );
    }

    private static function throwStatusConflictIfChanged(WorkOrder $wo, mixed $expectedStatus, string $requiredStatus, string $message): void
    {
        if ($expectedStatus === $requiredStatus && $wo->status !== $requiredStatus) {
            throw new ConflictHttpException($message);
        }
    }

    private static function assertCapability(string $ability): void
    {
        abort_unless(Gate::allows($ability), 403);
    }

    private static function assertAssignmentActor(int $assignedBy, int $companyId, string $ability): void
    {
        $actor = Auth::user();
        abort_unless(
            $actor instanceof User
            && $actor->is_active
            && (int) $actor->id === $assignedBy
            && CompanyService::idFor($actor) === $companyId
            && $actor->can($ability),
            403
        );
    }

    private static function assertAssignedTechnician(WorkOrder $wo, string $ability): void
    {
        self::assertCapability($ability);
        $actor = Auth::user();
        abort_unless(
            $actor !== null
            && $actor->is_active
            && CompanyService::idFor($actor) === (int) $wo->company_id
            && $wo->assigned_to !== null
            && (int) $wo->assigned_to === (int) $actor->id
            && EmployeeProfile::query()
                ->where('company_id', $wo->company_id)
                ->where('user_id', $actor->id)
                ->where('status', 'active')
                ->whereHas('user', fn ($query) => $query->where('is_active', true))
                ->where(function ($query) use ($ability) {
                    $query->whereHas('user.permissions', fn ($permissionQuery) => $permissionQuery->where('name', $ability))
                        ->orWhereHas('user.roles.permissions', fn ($permissionQuery) => $permissionQuery->where('name', $ability));
                })
                ->exists(),
            403
        );
    }
}
