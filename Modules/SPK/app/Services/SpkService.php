<?php

namespace Modules\SPK\Services;

use App\Models\Core\Customer;
use App\Models\Core\EmployeeProfile;
use App\Models\User;
use App\Services\Core\AuditService;
use App\Services\Core\CompanyService;
use App\Services\Core\NumberSequenceService;
use App\Support\FieldCheckIn;
use Carbon\CarbonInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\DatabaseNotification;
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
use Modules\SPK\Notifications\TechnicianEnRouteNotification;
use Modules\SPK\Notifications\VisitCompletedNotification;
use Modules\SPK\Notifications\WorkOrderApprovedNotification;
use Modules\SPK\Notifications\WorkOrderRejectedNotification;
use Modules\SPK\Notifications\WorkOrderScheduledNotification;
use Modules\SPK\Support\WorkOrderSite;
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
            abort_if($wo->status !== 'draft', 422, 'SPK harus berstatus draf untuk diterbitkan.');

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
                'Status penugasan semula wajib dikirim.'
            );
            abort_unless(
                $expectedAssignedTo === null || is_int($expectedAssignedTo),
                422,
                'Penanggung jawab semula harus berupa angka atau kosong.'
            );
            self::assertCanonicalExpectedUpdatedAt($expectedUpdatedAt);
            $wo = self::lockedWorkOrder($wo->id);
            self::assertCompany($wo);
            abort_if(! in_array($wo->status, ['generated', 'draft', 'rejected', 'assigned']), 422, 'SPK harus berstatus draf, menunggu penugasan, ditolak, atau ditugaskan untuk bisa ditugaskan.');

            $ability = $wo->assigned_to === null ? 'spk.assign' : 'spk.reassign';
            self::assertAssignmentActor($assignedBy, $wo->company_id, $ability);

            if ($expectedAssignedTo === null) {
                $assignmentMatches = $wo->assigned_to === null;
            } else {
                $assignmentMatches = (int) $wo->assigned_to === (int) $expectedAssignedTo;
            }

            if (! $assignmentMatches) {
                abort(409, 'Konflik penugasan: SPK ini sudah ditugaskan oleh pengguna lain.');
            }

            $canonicalUpdatedAt = $wo->updated_at?->utc()->format('Y-m-d\TH:i:s.v\Z');
            if ($expectedUpdatedAt === null || $canonicalUpdatedAt !== $expectedUpdatedAt) {
                throw new ConflictHttpException(
                    'Konflik penugasan: SPK ini sudah ditugaskan oleh pengguna lain.',
                    null,
                    409
                );
            }

            $companySettings = data_get($wo->company, 'settings', []);
            if (($companySettings['forbid_self_assignment'] ?? false) && (int) $assignedBy === (int) $technicianId) {
                abort(422, 'Kebijakan perusahaan melarang menugaskan diri sendiri.');
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
            $changes = ['status' => 'assigned', 'assigned_to' => $technician->id];
            // The trip, the start and the finish belong to one technician's one run of the work: a new technician, or the
            // rework after a rejection, starts all three over, so a reworked visit shows nothing of its earlier run above a
            // stepper that begins at "Ditugaskan" again. The earlier start stays in the `started` activity.
            if ($previousTechnicianId !== $technician->id || $wo->status === 'rejected') {
                $changes['en_route_at'] = null;
                $changes['started_at'] = null;
                $changes['submitted_at'] = null;
            }
            $wo->update($changes);

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

    /**
     * "Berangkat": the assigned technician is on the way. A timestamp inside `assigned`, not a status, and it happens once:
     * a second call changes nothing and tells the customer nothing. The optional fix is kept as the `en_route` activity,
     * apart from the check-in of the start.
     *
     * @param  array<string, mixed>|null  $checkIn
     */
    public static function markEnRoute(WorkOrder $wo, ?array $checkIn = null): WorkOrder
    {
        $expectedStatus = $wo->status;
        self::assertAssignedTechnician($wo, 'spk.start');

        return DB::transaction(function () use ($wo, $expectedStatus, $checkIn) {
            $wo = self::lockedWorkOrder($wo->id);
            self::assertCompany($wo);
            self::assertAssignedTechnician($wo, 'spk.start');
            self::throwStatusConflictIfChanged($wo, $expectedStatus, 'assigned', 'Konflik: status SPK sudah diubah oleh pengguna lain.');
            abort_if($wo->status !== 'assigned', 422, 'SPK harus berstatus ditugaskan agar teknisi bisa berangkat.');

            if ($wo->en_route_at !== null) {
                return $wo;
            }

            $leftAt = now();
            $wo->update(['en_route_at' => $leftAt]);
            $wo->loadMissing(['location', 'subscription.installationAddress', 'subscription.customer', 'assignee']);

            AuditService::log(
                'work_order',
                'en_route',
                $checkIn === null ? [] : FieldCheckIn::properties($checkIn, FieldCheckIn::site(WorkOrderSite::site($wo))),
                $wo
            );
            self::notifyEnRoute($wo, $leftAt);

            return $wo->fresh();
        });
    }

    /**
     * The customer's app hears about the trip when the SPK belongs to one of their subscriptions: that is what the app
     * lists and opens. Only a customer with a login can read it. One trip, one notification: its id is made of the SPK and
     * the moment of "Berangkat", so a trip that is recorded again finds its row and adds nothing.
     */
    private static function notifyEnRoute(WorkOrder $wo, CarbonInterface $leftAt): void
    {
        $customer = $wo->subscription?->customer;
        $firstName = $wo->technicianFirstName();

        if ($customer === null || $customer->user_id === null || $firstName === null) {
            return;
        }

        $notification = new TechnicianEnRouteNotification($wo->company_id, $wo->id, $firstName, $leftAt);

        if (DatabaseNotification::query()->whereKey($notification->id)->exists()) {
            return;
        }

        DB::afterCommit(fn () => self::notifySafely($customer, $notification));
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
            self::throwStatusConflictIfChanged($wo, $expectedStatus, 'assigned', 'Konflik: status SPK sudah diubah oleh pengguna lain.');
            abort_if($wo->status !== 'assigned', 422, 'SPK harus berstatus ditugaskan untuk bisa dimulai.');
            $wo->update(['status' => 'in_progress', 'started_at' => now()]);

            AuditService::log('work_order', 'started', ['code' => $wo->code], $wo);

            if ($checkIn !== null) {
                $wo->loadMissing(['location', 'subscription.installationAddress']);
                AuditService::log(
                    'work_order',
                    'check_in',
                    FieldCheckIn::properties($checkIn, FieldCheckIn::site(WorkOrderSite::site($wo))),
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
            abort_if($item->network_asset_id !== null, 422, 'Baris aset berserial dikelola oleh kantor.');

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
            abort(422, 'Bukti pekerjaan wajib diunggah sebelum SPK diajukan.');
        }

        // Check if there are associated inventory requests. If any, all must have completed declaration.
        self::assertMaterialReturnDeclarationComplete($wo);

        return DB::transaction(function () use ($wo, $expectedStatus) {
            $wo = self::lockedWorkOrder($wo->id);
            self::assertCompany($wo);
            self::assertAssignedTechnician($wo, 'spk.submit');
            self::throwStatusConflictIfChanged($wo, $expectedStatus, 'in_progress', 'Konflik: status SPK sudah diubah oleh pengguna lain.');
            abort_if($wo->status !== 'in_progress', 422, 'SPK harus berstatus dikerjakan untuk bisa diajukan.');
            abort_if($wo->getMedia('evidence')->count() === 0, 422, 'Bukti pekerjaan wajib diunggah sebelum SPK diajukan.');
            $wo->update(['status' => 'waiting_review', 'submitted_at' => $wo->submitted_at ?? now()]);

            AuditService::log('work_order', 'submitted_for_review', ['code' => $wo->code], $wo);

            return $wo->fresh();
        });
    }

    public static function approve(WorkOrder $wo): WorkOrder
    {
        self::assertCapability('spk.approve');
        abort_if($wo->status !== 'waiting_review', 422, 'SPK harus berstatus menunggu review untuk bisa disetujui.');
        self::assertMaterialReturnReconciledOrResolved($wo);

        return DB::transaction(function () use ($wo) {
            $wo = self::lockedWorkOrder($wo->id);
            abort_if($wo->status !== 'waiting_review', 422, 'SPK harus berstatus menunggu review untuk bisa disetujui.');
            self::assertCompany($wo);
            self::assertCapability('spk.approve');
            abort_if(Auth::id() === $wo->created_by, 422, 'Pembuat SPK tidak boleh menyetujui SPK sendiri.');
            abort_if(Auth::id() === $wo->assigned_to, 422, 'Teknisi yang ditugaskan tidak boleh menyetujui SPK sendiri.');
            self::assertMaterialReturnReconciledOrResolved($wo);
            $wo->update([
                'status' => 'completed',
                'completed_at' => now(),
                'approved_by' => Auth::id(),
                'approved_at' => now(),
            ]);

            CompleteSpkAction::execute($wo);

            AuditService::log('work_order', 'approved', ['code' => $wo->code], $wo);
            self::notifyApproved($wo);

            return $wo->fresh();
        });
    }

    public static function reject(WorkOrder $wo, string $reason): WorkOrder
    {
        self::assertCapability('spk.reject');
        abort_if($wo->status !== 'waiting_review', 422, 'SPK harus berstatus menunggu review untuk bisa ditolak.');

        return DB::transaction(function () use ($wo, $reason) {
            $wo = self::lockedWorkOrder($wo->id);
            abort_if($wo->status !== 'waiting_review', 422, 'SPK harus berstatus menunggu review untuk bisa ditolak.');
            self::assertCompany($wo);
            self::assertCapability('spk.reject');
            abort_if(Auth::id() === $wo->created_by, 422, 'Pembuat SPK tidak boleh menolak SPK sendiri.');
            abort_if(Auth::id() === $wo->assigned_to, 422, 'Teknisi yang ditugaskan tidak boleh menolak SPK sendiri.');
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
            self::notifyRejected($wo, $reason);

            return $wo->fresh();
        });
    }

    /**
     * The technician hears the verdict and, when the SPK belongs to one of the customer's subscriptions (what the app
     * lists), the customer hears the visit is done. Both go out after the approval commits.
     */
    private static function notifyApproved(WorkOrder $wo): void
    {
        $wo->loadMissing(['assignee', 'subscription.customer']);
        $technician = $wo->assignee;
        $customer = $wo->subscription?->customer;

        DB::afterCommit(function () use ($wo, $technician, $customer): void {
            self::notifySafely($technician, new WorkOrderApprovedNotification($wo->company_id, $wo->id, $wo->code));

            if ($customer?->user_id !== null && in_array($wo->type, ['installation', 'maintenance'], true)) {
                self::notifySafely($customer, new VisitCompletedNotification($wo->company_id, $wo->id));
            }
        });
    }

    private static function notifyRejected(WorkOrder $wo, string $reason): void
    {
        $wo->loadMissing('assignee');
        $technician = $wo->assignee;

        DB::afterCommit(fn () => self::notifySafely($technician, new WorkOrderRejectedNotification($wo->company_id, $wo->id, $wo->code, $reason)));
    }

    /** A notification that cannot be queued is reported, never allowed to undo a review that has already committed. */
    private static function notifySafely(?object $notifiable, object $notification): void
    {
        if ($notifiable !== null) {
            rescue(fn () => Notification::send($notifiable, $notification));
        }
    }

    public static function cancel(WorkOrder $wo, string $reason): WorkOrder
    {
        self::assertCapability('spk.cancel');
        abort_if(in_array($wo->status, ['completed', 'cancelled']), 422, 'SPK sudah selesai atau dibatalkan.');

        return DB::transaction(function () use ($wo, $reason) {
            $wo = self::lockedWorkOrder($wo->id);
            self::assertCompany($wo);
            self::assertCapability('spk.cancel');
            abort_if(in_array($wo->status, ['completed', 'cancelled']), 422, 'SPK sudah selesai atau dibatalkan.');
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
            abort_if($product->type === 'asset', 422, 'Item aset berserial wajib memilih aset jaringan.');

            return;
        }

        $asset = NetworkAsset::withoutCompany()
            ->where('company_id', $wo->company_id)
            ->lockForUpdate()
            ->findOrFail($assetId);

        abort_unless((int) $asset->product_id === (int) $product->id, 422, 'Aset jaringan yang dipilih harus sesuai dengan barang pada item SPK.');
        abort_unless($asset->status === 'available', 422, 'Aset jaringan yang dipilih harus berstatus tersedia.');
        abort_unless($product->type === 'asset', 422, 'Barang yang dipilih harus berupa aset berserial.');

        if ($wo->subscription_id && $asset->subscription_id !== null) {
            abort_unless((int) $asset->subscription_id === (int) $wo->subscription_id, 422, 'Aset jaringan yang dipilih harus milik langganan pada SPK ini.');
        }

        abort_if(
            NetworkAssetInstallation::withoutCompany()
                ->where('company_id', $wo->company_id)
                ->where('network_asset_id', $asset->id)
                ->whereNull('removed_at')
                ->lockForUpdate()
                ->exists(),
            422,
            'Aset jaringan yang dipilih sedang terpasang.'
        );
    }

    private static function companyId(): int
    {
        $companyId = CompanyService::currentId();
        abort_if($companyId === null, 403, 'Konteks perusahaan diperlukan.');

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
            'Seluruh sisa material dari permintaan barang harus dideklarasikan terlebih dahulu sebelum SPK diajukan.'
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
            'SPK belum bisa disetujui sampai seluruh retur material berstatus direkonsiliasi atau diselesaikan.'
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
            'Waktu pembaruan semula harus berformat UTC baku.'
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
