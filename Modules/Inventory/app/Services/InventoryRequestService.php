<?php

namespace Modules\Inventory\Services;

use App\Models\Core\Location;
use App\Models\User;
use App\Services\Core\AuditService;
use App\Services\Core\CompanyService;
use App\Services\Core\NumberSequenceService;
use App\Support\Notifications\StaffRecipients;
use App\Support\PublicId;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Modules\Inventory\Models\InventoryRequest;
use Modules\Inventory\Models\InventoryRequestItem;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Notifications\InventoryRequestApprovedNotification;
use Modules\Inventory\Notifications\InventoryRequestReadyNotification;
use Modules\Inventory\Notifications\InventoryRequestRejectedNotification;
use Modules\Inventory\Notifications\InventoryRequestSubmittedNotification;
use Modules\Inventory\Support\InventoryRequestSources;
use Modules\SPK\Models\WorkOrder;

class InventoryRequestService
{
    /**
     * Server-side paginator for Inventory Requests with role/permission & tab support.
     *
     * @param  array<string, mixed>  $filters
     */
    public static function paginate(User $user, array $filters = []): LengthAwarePaginator
    {
        $companyId = CompanyService::currentId() ?? CompanyService::idFor($user);
        $tab = $filters['tab'] ?? 'all'; // 'my' or 'warehouse' or 'all'

        $query = InventoryRequest::withoutCompany()
            ->where('company_id', $companyId)
            ->with(['requester', 'approver', 'warehouseLocation', 'items.product', 'items.sourceLocation']);

        // Scope based on permissions and requested tab
        if (! $user->hasPermissionTo('inventory.request.view.all')) {
            // Can only view own
            $query->where('requested_by', $user->id);
        } else {
            if ($tab === 'my') {
                $query->where('requested_by', $user->id);
            } elseif ($tab === 'warehouse') {
                // Active warehouse queue statuses
                $query->whereIn('status', ['submitted', 'approved_reserved', 'preparing', 'ready_for_pickup']);
            }
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['requester_id'])) {
            $query->where('requested_by', PublicId::resolveOne(User::class, $filters['requester_id']) ?? 0);
        }

        if (! empty($filters['warehouse_location_id'])) {
            $query->where('warehouse_location_id', PublicId::resolveOne(Location::class, $filters['warehouse_location_id']) ?? 0);
        }

        if (! empty($filters['needed_from'])) {
            $query->whereDate('needed_at', '>=', $filters['needed_from']);
        }

        if (! empty($filters['needed_to'])) {
            $query->whereDate('needed_at', '<=', $filters['needed_to']);
        }

        if (! empty($filters['search'])) {
            $search = '%'.$filters['search'].'%';
            $query->where(function (Builder $q) use ($search) {
                $q->where('code', 'like', $search)
                    ->orWhere('reason', 'like', $search)
                    ->orWhereHas('requester', fn ($uq) => $uq->where('name', 'like', $search));
            });
        }

        $sortField = $filters['sort'] ?? 'created_at';
        $sortDirection = strtolower($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        $allowedSorts = ['id', 'code', 'status', 'needed_at', 'created_at'];
        if (! in_array($sortField, $allowedSorts, true)) {
            $sortField = 'created_at';
        }

        $query->orderBy($sortField, $sortDirection);

        $perPage = min(max((int) ($filters['per_page'] ?? 15), 5), 100);

        $paginator = $query->paginate($perPage);
        InventoryRequest::loadSources(new Collection($paginator->items()));

        return $paginator;
    }

    /**
     * Get badge counts for user actionable tabs without leaking cross-tenant data. `warehouse_queue` is everything in
     * the four queue statuses; `warehouse_actionable` is what this person can act on now (see actionableQueueCount).
     *
     * @return array<string, int|null>
     */
    public static function getBadgeCounts(User $user): array
    {
        $companyId = CompanyService::currentId() ?? CompanyService::idFor($user);

        $myCount = InventoryRequest::withoutCompany()
            ->where('company_id', $companyId)
            ->where('requested_by', $user->id)
            ->whereIn('status', ['draft', 'ready_for_pickup'])
            ->count();

        $warehouseQueueCount = null;
        if ($user->hasPermissionTo('inventory.request.approve') || $user->hasPermissionTo('inventory.request.prepare') || $user->hasPermissionTo('inventory.request.issue')) {
            $warehouseQueueCount = InventoryRequest::withoutCompany()
                ->where('company_id', $companyId)
                ->whereIn('status', ['submitted', 'approved_reserved', 'preparing', 'ready_for_pickup'])
                ->count();
        }

        return [
            'my_requests' => $myCount,
            'warehouse_queue' => $warehouseQueueCount,
            'warehouse_actionable' => self::actionableQueueCount($user),
        ];
    }

    /**
     * How many requests of the warehouse queue this person can act on now: approve or reject one that waits, prepare an
     * approved one, hand over one that is ready. A request that waits for someone else, or that the maker-checker rules
     * keep from this person (the requester never decides or hands over, the approver never prepares or hands over), is
     * not counted. It follows InventoryRequestPolicy in SQL, so it costs one query however long the queue is. Null when
     * the person may do none of those things, as `warehouse_queue` is.
     */
    public static function actionableQueueCount(User $user): ?int
    {
        $canDecide = $user->hasPermissionTo('inventory.request.approve') || $user->hasPermissionTo('inventory.request.reject');
        $canPrepare = $user->hasPermissionTo('inventory.request.prepare');
        $canIssue = $user->hasPermissionTo('inventory.request.issue');

        if (! $canDecide && ! $canPrepare && ! $canIssue) {
            return null;
        }

        // The approver column is empty until approval: a plain `!=` would drop those rows, so the NULL is named.
        $approverIsAnother = fn (Builder $query) => $query->whereNull('approved_by')->orWhere('approved_by', '!=', $user->id);

        return InventoryRequest::withoutCompany()
            ->where('company_id', CompanyService::currentId() ?? CompanyService::idFor($user))
            ->where(function (Builder $queue) use ($user, $canDecide, $canPrepare, $canIssue, $approverIsAnother): void {
                $queue
                    ->when($canDecide, fn (Builder $q) => $q->orWhere(fn (Builder $r) => $r->where('status', 'submitted')->where('requested_by', '!=', $user->id)))
                    ->when($canPrepare, fn (Builder $q) => $q
                        ->orWhere(fn (Builder $r) => $r->where('status', 'approved_reserved')->where($approverIsAnother))
                        ->orWhere('status', 'preparing'))
                    ->when($canIssue, fn (Builder $q) => $q->orWhere(fn (Builder $r) => $r
                        ->where('status', 'ready_for_pickup')
                        ->where('requested_by', '!=', $user->id)
                        ->where($approverIsAnother)));
            })
            ->count();
    }

    /**
     * Create a new Inventory Request (draft or submitted).
     *
     * `idempotency_key` (the API's Idempotency-Key) makes a replay answer the request it created, even after the cache
     * replay record is gone; a replay racing the first call lands on the unique index and resolves to that same row.
     *
     * @param  array<string, mixed>  $data
     */
    public static function create(User $requester, array $data): InventoryRequest
    {
        $companyId = CompanyService::currentId() ?? CompanyService::idFor($requester);

        try {
            return self::createInTransaction($requester, $data, $companyId);
        } catch (UniqueConstraintViolationException $e) {
            return self::requestOfKey($requester, $companyId, $data['idempotency_key'] ?? null) ?? throw $e;
        }
    }

    /**
     * Look up a request this requester already made under an Idempotency-Key.
     */
    private static function requestOfKey(User $requester, ?int $companyId, ?string $key): ?InventoryRequest
    {
        if ($key === null) {
            return null;
        }

        return InventoryRequest::withoutCompany()
            ->where('company_id', $companyId)
            ->where('requested_by', $requester->id)
            ->where('idempotency_key', $key)
            ->first()
            ?->load(['items.product', 'items.sourceLocation', 'requester', 'warehouseLocation']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function createInTransaction(User $requester, array $data, ?int $companyId): InventoryRequest
    {
        return DB::transaction(function () use ($requester, $data, $companyId) {
            $replay = self::requestOfKey($requester, $companyId, $data['idempotency_key'] ?? null);
            if ($replay !== null) {
                return $replay;
            }

            $code = NumberSequenceService::generate('inventory_request', $companyId);
            $submitImmediately = ! empty($data['submit']);

            $reason = trim((string) ($data['reason'] ?? ''));
            if ($reason === '') {
                $sourceType = $data['source_type'] ?? null;
                $sourceModel = InventoryRequest::sourceModels()[$sourceType] ?? null;
                $sourceCode = $sourceModel !== null && ! empty($data['source_id']) ? $sourceModel::query()->whereKey($data['source_id'])->value('code') : null;
                $reason = $sourceCode !== null
                    ? 'Permintaan material untuk '.($sourceType === 'ticket' ? 'tiket' : 'SPK').' '.$sourceCode
                    : 'Permintaan material inventaris';
            }

            $request = InventoryRequest::create([
                'company_id' => $companyId,
                'code' => $code,
                'requested_by' => $requester->id,
                'warehouse_location_id' => $data['warehouse_location_id'] ?? null,
                'source_type' => $data['source_type'] ?? null,
                'source_id' => $data['source_id'] ?? null,
                'status' => $submitImmediately ? 'submitted' : 'draft',
                'needed_at' => $data['needed_at'] ?? null,
                'reason' => $reason,
                'submitted_at' => $submitImmediately ? now() : null,
                'idempotency_key' => $data['idempotency_key'] ?? null,
            ]);

            foreach ($data['items'] as $itemData) {
                // Ensure product belongs to company and is not serialized asset in phase 1 bulk
                $product = Product::withoutCompany()
                    ->where('company_id', $companyId)
                    ->lockForUpdate()
                    ->findOrFail($itemData['product_id']);

                abort_if($product->type === 'asset', 422, 'Barang bertipe aset berserial belum didukung pada permintaan barang massal.');

                $itemSourceLocationId = $itemData['source_location_id'] ?? $request->warehouse_location_id;

                InventoryRequestItem::create([
                    'company_id' => $companyId,
                    'inventory_request_id' => $request->id,
                    'product_id' => $product->id,
                    'source_location_id' => $itemSourceLocationId,
                    'quantity_requested' => $itemData['quantity_requested'],
                    'note' => $itemData['note'] ?? null,
                ]);
            }

            AuditService::log('inventory_request', $submitImmediately ? 'created_and_submitted' : 'created_draft', [
                'code' => $request->code,
                'items_count' => count($data['items']),
                'submit' => $submitImmediately,
            ], $request);

            if ($submitImmediately) {
                DB::afterCommit(function () use ($request) {
                    self::notifyWarehouseOfSubmission($request);
                });
            }

            return $request->fresh(['items.product', 'items.sourceLocation', 'requester', 'warehouseLocation']);
        }, 3);
    }

    /**
     * Submit a draft Inventory Request.
     */
    public static function submit(InventoryRequest $request, User $actor): InventoryRequest
    {
        $companyId = CompanyService::currentId() ?? CompanyService::idFor($actor);

        return DB::transaction(function () use ($request, $actor, $companyId) {
            $req = InventoryRequest::withoutCompany()
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->findOrFail($request->id);

            abort_unless($req->status === 'draft', 422, 'Hanya permintaan berstatus draf yang dapat diajukan.');
            abort_unless((int) $req->requested_by === (int) $actor->id, 403, 'Anda bukan pembuat permintaan barang ini.');

            $itemsCount = $req->items()->count();
            abort_if($itemsCount === 0, 422, 'Permintaan barang tidak memiliki item barang.');

            $req->update([
                'status' => 'submitted',
                'submitted_at' => now(),
            ]);

            AuditService::log('inventory_request', 'submitted', [
                'code' => $req->code,
                'items_count' => $itemsCount,
            ], $req);

            DB::afterCommit(function () use ($req) {
                self::notifyWarehouseOfSubmission($req);
            });

            return $req->fresh(['items.product', 'items.sourceLocation', 'requester', 'warehouseLocation']);
        }, 3);
    }

    /**
     * Approve and atomically reserve stock for each item.
     *
     * @param  array<string, mixed>  $data
     */
    public static function approve(InventoryRequest $request, User $approver, array $data = []): InventoryRequest
    {
        $companyId = CompanyService::currentId() ?? CompanyService::idFor($approver);

        return DB::transaction(function () use ($request, $approver, $data, $companyId) {
            // Lock request header
            $req = InventoryRequest::withoutCompany()
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->findOrFail($request->id);

            abort_unless($req->status === 'submitted', 422, 'Permintaan barang bukan berstatus menunggu persetujuan.');
            abort_if((int) $req->requested_by === (int) $approver->id, 403, 'Maker-checker: pemohon tidak boleh menyetujui permintaan sendiri.');

            $warehouseLocationId = $data['warehouse_location_id'] ?? $req->warehouse_location_id;
            if ($warehouseLocationId) {
                $req->warehouse_location_id = $warehouseLocationId;
            }

            // Lock all items
            /** @var Collection<int, InventoryRequestItem> $items */
            $items = $req->items()->lockForUpdate()->get();
            abort_if($items->isEmpty(), 422, 'Permintaan barang tidak memiliki item.');

            $overrideItemsData = collect($data['items'] ?? [])->keyBy('id');

            foreach ($items as $item) {
                $approvedQty = $item->quantity_requested;
                $sourceLocationId = $item->source_location_id ?? $req->warehouse_location_id;

                if ($overrideItemsData->has($item->id)) {
                    $itemOverride = $overrideItemsData->get($item->id);
                    $approvedQty = (float) $itemOverride['quantity_approved'];
                    if (! empty($itemOverride['source_location_id'])) {
                        $sourceLocationId = (int) $itemOverride['source_location_id'];
                    }
                }

                abort_if($approvedQty <= 0, 422, 'Jumlah barang yang disetujui harus lebih besar dari 0.');
                abort_if(
                    $approvedQty > (float) $item->quantity_requested,
                    422,
                    "Jumlah barang yang disetujui ({$approvedQty}) tidak boleh melampaui jumlah yang diminta ({$item->quantity_requested})."
                );
                abort_unless(! empty($sourceLocationId), 422, 'Lokasi gudang sumber wajib ditentukan untuk reservasi.');

                // Reserve using StockService
                StockService::reserve(
                    $item->product_id,
                    $sourceLocationId,
                    $approvedQty,
                    InventoryRequestItem::class,
                    $item->id
                );

                $item->update([
                    'source_location_id' => $sourceLocationId,
                    'quantity_approved' => $approvedQty,
                ]);
            }

            $req->update([
                'status' => 'approved_reserved',
                'approved_by' => $approver->id,
                'approved_at' => now(),
                'approval_note' => $data['approval_note'] ?? null,
            ]);

            AuditService::log('inventory_request', 'approved_reserved', [
                'code' => $req->code,
                'approved_by' => $approver->id,
                'items_count' => $items->count(),
            ], $req);

            // Notify requester
            DB::afterCommit(function () use ($req, $companyId) {
                if ($req->requester) {
                    $req->requester->notify(new InventoryRequestApprovedNotification($req->id, $req->code, $companyId));
                }
            });

            return $req->fresh(['items.product', 'items.sourceLocation', 'requester', 'warehouseLocation', 'approver']);
        }, 3);
    }

    /**
     * Mark request as preparing (warehouse is packing/picking the items).
     */
    public static function prepare(InventoryRequest $request, User $preparer): InventoryRequest
    {
        $companyId = CompanyService::currentId() ?? CompanyService::idFor($preparer);

        return DB::transaction(function () use ($request, $preparer, $companyId) {
            $req = InventoryRequest::withoutCompany()
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->findOrFail($request->id);

            abort_unless($req->status === 'approved_reserved', 422, 'Hanya permintaan berstatus disetujui (stok direservasi) yang dapat mulai disiapkan.');
            abort_if((int) $req->approved_by === (int) $preparer->id, 403, 'Maker-checker: penyetujui tidak boleh menyiapkan barang.');

            $req->update([
                'status' => 'preparing',
                'prepared_by' => $preparer->id,
                'prepared_at' => now(),
            ]);

            AuditService::log('inventory_request', 'preparing', [
                'code' => $req->code,
                'prepared_by' => $preparer->id,
            ], $req);

            return $req->fresh(['items.product', 'items.sourceLocation', 'requester', 'warehouseLocation', 'preparer']);
        }, 3);
    }

    /**
     * Mark request as ready for pickup.
     */
    public static function markReady(InventoryRequest $request, User $actor): InventoryRequest
    {
        $companyId = CompanyService::currentId() ?? CompanyService::idFor($actor);

        return DB::transaction(function () use ($request, $actor, $companyId) {
            $req = InventoryRequest::withoutCompany()
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->findOrFail($request->id);

            abort_unless($req->status === 'preparing', 422, 'Hanya permintaan yang sedang disiapkan yang dapat ditandai siap diambil.');

            $req->update([
                'status' => 'ready_for_pickup',
                'ready_by' => $actor->id,
                'ready_at' => now(),
            ]);

            AuditService::log('inventory_request', 'ready_for_pickup', [
                'code' => $req->code,
                'ready_by' => $actor->id,
            ], $req);

            // Notify requester
            DB::afterCommit(function () use ($req, $companyId) {
                if ($req->requester) {
                    $req->requester->notify(new InventoryRequestReadyNotification($req->id, $req->code, $companyId));
                }
            });

            return $req->fresh(['items.product', 'items.sourceLocation', 'requester', 'warehouseLocation', 'readyUser']);
        }, 3);
    }

    /**
     * The warehouse sets the goods aside and tells the requester in one step (F8-U04): approved, then ready for pickup.
     * Both steps are recorded, as when they are taken one after the other; a request already preparing is finished by
     * markReady() alone.
     */
    public static function prepareForPickup(InventoryRequest $request, User $preparer): InventoryRequest
    {
        return DB::transaction(fn (): InventoryRequest => self::markReady(self::prepare($request, $preparer), $preparer), 3);
    }

    /**
     * Issue/Handover items to technician atomically:
     * Releases reservation and issues stock via StockService.
     * A request made for an SPK is handed over only while that SPK is open.
     */
    public static function issue(InventoryRequest $request, User $issuer): InventoryRequest
    {
        $companyId = CompanyService::currentId() ?? CompanyService::idFor($issuer);

        return DB::transaction(function () use ($request, $issuer, $companyId) {
            // Lock order: SPK, request, its items, then stock. SPK approval holds the SPK row while it reads which requests
            // were issued, so a handover takes that row first too and waits for the approval.
            $workOrder = self::lockedSourceWorkOrder($request, $companyId);

            $req = InventoryRequest::withoutCompany()
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->findOrFail($request->id);

            abort_unless($req->status === 'ready_for_pickup', 422, 'Hanya permintaan berstatus siap diambil yang dapat diserahterimakan.');
            abort_if((int) $req->requested_by === (int) $issuer->id, 403, 'Maker-checker: pemohon tidak boleh melakukan serah terima barang kepada dirinya sendiri.');
            abort_if((int) $req->approved_by === (int) $issuer->id, 403, 'Maker-checker: penyetujui tidak boleh melakukan serah terima barang.');
            abort_if(
                $req->source_type === 'work_order' && ($workOrder === null || in_array($workOrder->status, InventoryRequestSources::CLOSED_WORK_ORDER_STATUSES, true)),
                422,
                'SPK sumber sudah selesai atau dibatalkan; barang tidak bisa diserahterimakan.'
            );

            /** @var Collection<int, InventoryRequestItem> $items */
            $items = $req->items()->lockForUpdate()->get();
            abort_if($items->isEmpty(), 422, 'Permintaan barang tidak memiliki item.');

            foreach ($items as $item) {
                $qtyToIssue = (float) $item->quantity_approved;
                abort_if($qtyToIssue <= 0, 422, "Jumlah disetujui tidak valid untuk item {$item->public_id}.");

                $locationId = $item->source_location_id ?? $req->warehouse_location_id;
                abort_unless(! empty($locationId), 422, "Lokasi gudang tidak ditemukan untuk item {$item->public_id}.");

                // Atomically release reservation first
                StockService::release(
                    $item->product_id,
                    $locationId,
                    $qtyToIssue,
                    InventoryRequestItem::class,
                    $item->id
                );

                // Issue stock atomically
                StockService::issue(
                    $item->product_id,
                    $locationId,
                    $qtyToIssue,
                    'Penyerahan Permintaan Barang: '.$req->code,
                    InventoryRequestItem::class,
                    $item->id
                );

                $item->update([
                    'quantity_issued' => $qtyToIssue,
                ]);
            }

            $req->update([
                'status' => 'issued',
                'issued_by' => $issuer->id,
                'issued_at' => now(),
            ]);

            MaterialReturnService::createForIssuedRequest($req);

            AuditService::log('inventory_request', 'issued', [
                'code' => $req->code,
                'issued_by' => $issuer->id,
                'items_count' => $items->count(),
            ], $req);

            return $req->fresh(['items.product', 'items.sourceLocation', 'requester', 'warehouseLocation', 'issuer']);
        }, 3);
    }

    /**
     * Reject a submitted Inventory Request.
     */
    public static function reject(InventoryRequest $request, User $rejecter, string $reason): InventoryRequest
    {
        $companyId = CompanyService::currentId() ?? CompanyService::idFor($rejecter);

        return DB::transaction(function () use ($request, $rejecter, $reason, $companyId) {
            $req = InventoryRequest::withoutCompany()
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->findOrFail($request->id);

            abort_unless($req->status === 'submitted', 422, 'Hanya permintaan berstatus menunggu persetujuan yang dapat ditolak.');
            abort_if((int) $req->requested_by === (int) $rejecter->id, 403, 'Maker-checker: pemohon tidak boleh menolak permintaan sendiri.');

            $req->update([
                'status' => 'rejected',
                'rejected_by' => $rejecter->id,
                'rejected_at' => now(),
                'rejection_reason' => $reason,
            ]);

            AuditService::log('inventory_request', 'rejected', [
                'code' => $req->code,
                'rejected_by' => $rejecter->id,
                'reason' => $reason,
            ], $req);

            DB::afterCommit(function () use ($req, $reason, $companyId) {
                if ($req->requester) {
                    $req->requester->notify(new InventoryRequestRejectedNotification($req->id, $req->code, $reason, $companyId));
                }
            });

            return $req->fresh(['items.product', 'items.sourceLocation', 'requester', 'warehouseLocation', 'rejecter']);
        }, 3);
    }

    /**
     * Cancel an Inventory Request (only draft or submitted).
     */
    public static function cancel(InventoryRequest $request, User $canceller, ?string $reason = null): InventoryRequest
    {
        $companyId = CompanyService::currentId() ?? CompanyService::idFor($canceller);

        return DB::transaction(function () use ($request, $canceller, $reason, $companyId) {
            $req = InventoryRequest::withoutCompany()
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->findOrFail($request->id);

            abort_unless(in_array($req->status, ['draft', 'submitted'], true), 422, 'Permintaan yang sudah disetujui atau diproses tidak dapat dibatalkan melalui alur ini.');

            $req->update([
                'status' => 'cancelled',
                'cancelled_by' => $canceller->id,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
            ]);

            AuditService::log('inventory_request', 'cancelled', [
                'code' => $req->code,
                'cancelled_by' => $canceller->id,
                'reason' => $reason,
            ], $req);

            return $req->fresh(['items.product', 'items.sourceLocation', 'requester', 'warehouseLocation', 'canceller']);
        }, 3);
    }

    /**
     * The SPK a request was made for, locked. The source of a request never changes, so a plain read says which row to lock
     * before the request row itself. Null when the request has no SPK, or when it is gone.
     */
    private static function lockedSourceWorkOrder(InventoryRequest $request, int $companyId): ?WorkOrder
    {
        $source = InventoryRequest::withoutCompany()
            ->where('company_id', $companyId)
            ->whereKey($request->id)
            ->first(['source_type', 'source_id']);

        if ($source?->source_type !== 'work_order' || $source->source_id === null) {
            return null;
        }

        return WorkOrder::withoutCompany()->where('company_id', $companyId)->lockForUpdate()->find($source->source_id);
    }

    private static function notifyWarehouseOfSubmission(InventoryRequest $request): void
    {
        // Whoever may approve hears of it, but not the person who asked: they cannot approve their own request.
        $approvers = StaffRecipients::holding($request->company_id, 'inventory.request.approve', $request->requested_by);

        if ($approvers->isNotEmpty()) {
            Notification::send($approvers, new InventoryRequestSubmittedNotification($request->id, $request->code, $request->company_id));
        }
    }
}
