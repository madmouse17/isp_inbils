<?php

namespace Modules\Inventory\Services;

use App\Models\User;
use App\Services\Core\AuditService;
use App\Services\Core\CompanyService;
use App\Support\Notifications\StaffRecipients;
use App\Support\PublicId;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Modules\Inventory\Models\InventoryRequest;
use Modules\Inventory\Models\MaterialReturnCase;
use Modules\Inventory\Models\MaterialReturnEvent;
use Modules\Inventory\Models\MaterialReturnLine;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Notifications\MaterialReturnDeclaredNotification;
use Modules\Inventory\Notifications\MaterialReturnExceptionNotification;
use Modules\Inventory\Notifications\MaterialReturnRejectedNotification;
use Modules\SPK\Actions\CompleteSpkAction;
use Modules\SPK\Models\WorkOrder;

class MaterialReturnService
{
    /**
     * Server-side paginator for Material Return cases.
     *
     * @param  array<string, mixed>  $filters
     */
    public static function paginate(User $user, array $filters = []): LengthAwarePaginator
    {
        $companyId = CompanyService::currentId() ?? CompanyService::idFor($user);

        $query = MaterialReturnCase::withoutCompany()
            ->where('company_id', $companyId)
            ->with(['technician', 'warehouseLocation', 'inventoryRequest', 'workOrder', 'lines.product']);

        if (! $user->hasPermissionTo('inventory.return.view.all')) {
            $query->where('technician_id', $user->id);
        } else {
            if (! empty($filters['technician_id'])) {
                $query->where('technician_id', PublicId::resolveOne(User::class, $filters['technician_id']) ?? 0);
            }
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['inventory_request_id'])) {
            $query->where('inventory_request_id', PublicId::resolveOne(InventoryRequest::class, $filters['inventory_request_id']) ?? 0);
        }

        if (! empty($filters['work_order_id'])) {
            $query->where('work_order_id', PublicId::resolveOne(WorkOrder::class, $filters['work_order_id']) ?? 0);
        }

        if (! empty($filters['search'])) {
            $search = '%'.$filters['search'].'%';
            $query->where(function (Builder $q) use ($search) {
                $q->whereHas('inventoryRequest', fn ($ir) => $ir->where('code', 'like', $search))
                    ->orWhereHas('technician', fn ($t) => $t->where('name', 'like', $search))
                    ->orWhere('public_id', 'like', $search);
            });
        }

        $sortField = $filters['sort'] ?? 'created_at';
        $sortDirection = strtolower($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $allowedSorts = ['id', 'status', 'created_at', 'declared_at', 'received_at'];
        if (! in_array($sortField, $allowedSorts, true)) {
            $sortField = 'created_at';
        }

        $query->orderBy($sortField, $sortDirection);
        $perPage = min(max((int) ($filters['per_page'] ?? 15), 5), 100);

        return $query->paginate($perPage);
    }

    /**
     * Get badge counts for user actionable tabs without leaking cross-tenant data.
     *
     * @return array<string, int|null>
     */
    public static function getBadgeCounts(User $user): array
    {
        $companyId = CompanyService::currentId() ?? CompanyService::idFor($user);

        $myPendingCount = MaterialReturnCase::withoutCompany()
            ->where('company_id', $companyId)
            ->where('technician_id', $user->id)
            ->whereIn('status', ['awaiting_declaration', 'correction_required'])
            ->count();

        $warehouseQueueCount = null;
        if ($user->hasPermissionTo('inventory.return.receive') || $user->hasPermissionTo('inventory.return.resolve')) {
            $warehouseQueueCount = MaterialReturnCase::withoutCompany()
                ->where('company_id', $companyId)
                ->whereIn('status', ['awaiting_receipt', 'exception_open'])
                ->count();
        }

        return [
            'my_pending_returns' => $myPendingCount,
            'warehouse_return_queue' => $warehouseQueueCount,
        ];
    }

    /**
     * Create or retrieve material return case upon Inventory Request issue.
     */
    public static function createForIssuedRequest(InventoryRequest $request): MaterialReturnCase
    {
        return DB::transaction(function () use ($request) {
            $existing = MaterialReturnCase::withoutCompany()
                ->where('company_id', $request->company_id)
                ->where('inventory_request_id', $request->id)
                ->first();

            if ($existing) {
                return $existing;
            }

            $case = MaterialReturnCase::create([
                'company_id' => $request->company_id,
                'inventory_request_id' => $request->id,
                'work_order_id' => $request->source_type === 'work_order' ? $request->source_id : null,
                'warehouse_location_id' => $request->warehouse_location_id,
                'technician_id' => $request->requested_by,
                'status' => 'awaiting_declaration',
                'declaration_version' => 1,
            ]);

            $request->loadMissing('items.product');

            foreach ($request->items as $item) {
                $issuedQty = (float) $item->quantity_issued;
                if ($issuedQty <= 0) {
                    continue;
                }

                MaterialReturnLine::create([
                    'company_id' => $request->company_id,
                    'material_return_case_id' => $case->id,
                    'inventory_request_item_id' => $item->id,
                    'product_id' => $item->product_id,
                    'issued_qty' => $issuedQty,
                    'actual_used_qty' => 0,
                    'declared_good_return_qty' => 0,
                    'declared_discrepancy_qty' => 0,
                    'accepted_good_return_qty' => 0,
                    'damaged_return_qty' => 0,
                    'lost_qty' => 0,
                    'other_discrepancy_qty' => 0,
                ]);
            }

            MaterialReturnEvent::create([
                'company_id' => $request->company_id,
                'material_return_case_id' => $case->id,
                'from_status' => null,
                'to_status' => 'awaiting_declaration',
                'action' => 'created',
                'actor_id' => $request->issued_by ?? $request->requested_by,
                'reason' => 'Kasus pengembalian material dibuat otomatis dari serah terima permintaan barang: '.$request->code,
                'payload' => [
                    'inventory_request_id' => $request->id,
                    'code' => $request->code,
                ],
            ]);

            return $case;
        }, 3);
    }

    /**
     * Technician declares material usage and physical return/discrepancy.
     *
     * @param  array{lines: array<int, array<string, mixed>>, reason?: ?string, idempotency_key?: ?string}  $data
     */
    public static function declareUsage(MaterialReturnCase $case, User $actor, array $data): MaterialReturnCase
    {
        abort_unless($actor->can('inventory.return.declare') || $actor->hasPermissionTo('inventory.return.declare'), 403, 'Anda tidak memiliki hak akses untuk mendeklarasikan sisa material.');

        $companyId = CompanyService::currentId() ?? CompanyService::idFor($actor);

        return DB::transaction(function () use ($case, $actor, $data, $companyId) {
            $c = MaterialReturnCase::withoutCompany()
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->findOrFail($case->id);

            $payloadHash = self::hashPayload($data);
            $idempotencyKey = $data['idempotency_key'] ?? null;
            if ($idempotencyKey && $c->idempotency_key === $idempotencyKey) {
                abort_if($c->payload_hash !== null && $c->payload_hash !== $payloadHash, 409, 'Konflik: kunci idempotensi sudah dipakai dengan data yang berbeda.');

                return $c->fresh(['lines.product', 'events.actor', 'technician', 'warehouseLocation']);
            }

            abort_unless(in_array($c->status, ['awaiting_declaration', 'correction_required'], true), 422, 'Status kasus pengembalian tidak mengizinkan deklarasi material.');
            abort_unless((int) $c->technician_id === (int) $actor->id, 403, 'Hanya teknisi pemilik barang titipan yang berhak mendeklarasikan sisa barang.');

            if ($idempotencyKey) {
                $duplicate = MaterialReturnCase::withoutCompany()
                    ->where('company_id', $companyId)
                    ->where('idempotency_key', $idempotencyKey)
                    ->where('id', '!=', $c->id)
                    ->exists();
                abort_if($duplicate, 409, 'Kunci idempotensi sudah dipakai pada kasus pengembalian lain.');
            }

            /** @var Collection<int, MaterialReturnLine> $lines */
            $lines = $c->lines()->lockForUpdate()->get()->keyBy('id');
            abort_if($lines->isEmpty(), 422, 'Kasus pengembalian tidak memiliki baris material.');

            $inputLines = collect($data['lines'])->keyBy('id');
            abort_unless($inputLines->count() === $lines->count(), 422, 'Seluruh baris material harus dideklarasikan secara lengkap.');

            foreach ($lines as $lineId => $line) {
                $input = $inputLines->get($lineId);
                abort_unless($input !== null, 422, "Data deklarasi untuk barang {$line->public_id} tidak ditemukan.");

                $used = (float) ($input['actual_used_qty'] ?? 0);
                $goodReturn = (float) ($input['declared_good_return_qty'] ?? 0);
                $discrepancy = (float) ($input['declared_discrepancy_qty'] ?? 0);

                abort_if($used < 0 || $goodReturn < 0 || $discrepancy < 0, 422, 'Nilai jumlah deklarasi tidak boleh negatif.');

                $product = Product::withoutCompany()->find($line->product_id);
                abort_if($product && $product->type === 'asset', 422, 'Aset berserial tidak boleh dideklarasikan lewat pengembalian material massal.');

                $issued = (float) $line->issued_qty;
                $sumDeclared = round($used + $goodReturn + $discrepancy, 2);

                if (abs($issued - $sumDeclared) > 0.001) {
                    abort(422, "Jumlah tidak cocok pada baris {$line->public_id}: diserahkan ({$issued}) harus sama dengan terpakai ({$used}) + dikembalikan ({$goodReturn}) + selisih ({$discrepancy}).");
                }

                $discrepancyCode = $input['discrepancy_code'] ?? null;
                $discrepancyReason = $input['discrepancy_reason'] ?? null;
                if ($discrepancy > 0) {
                    abort_unless(! empty($discrepancyCode) && in_array($discrepancyCode, ['damaged', 'lost', 'other'], true), 422, 'Kode dan alasan selisih (rusak, hilang, atau lainnya) wajib diisi jika ada selisih material.');
                }

                $line->update([
                    'actual_used_qty' => $used,
                    'declared_good_return_qty' => $goodReturn,
                    'declared_discrepancy_qty' => $discrepancy,
                    'discrepancy_code' => $discrepancyCode,
                    'discrepancy_reason' => $discrepancyReason,
                ]);
            }

            $fromStatus = $c->status;
            $newStatus = 'awaiting_receipt';

            $c->update([
                'status' => $newStatus,
                'declared_by' => $actor->id,
                'declared_at' => now(),
                'declaration_version' => $c->declaration_version + 1,
                'idempotency_key' => $idempotencyKey,
                'payload_hash' => $payloadHash,
            ]);

            MaterialReturnEvent::create([
                'company_id' => $companyId,
                'material_return_case_id' => $c->id,
                'from_status' => $fromStatus,
                'to_status' => $newStatus,
                'action' => 'declared',
                'actor_id' => $actor->id,
                'reason' => $data['reason'] ?? 'Teknisi mendeklarasikan pemakaian dan pengembalian material.',
                'payload' => [
                    'version' => $c->declaration_version,
                    'lines' => $data['lines'],
                ],
            ]);

            AuditService::log('material_return_case', 'declared', [
                'case_id' => $c->id,
                'declared_by' => $actor->id,
                'version' => $c->declaration_version,
            ], $c);

            DB::afterCommit(function () use ($c, $companyId, $actor) {
                // The technician waits at the desk, so the warehouse hears of it; not the technician, who cannot receive their own return.
                $receivers = StaffRecipients::holding($companyId, 'inventory.return.receive', $actor->id);
                if ($receivers->isNotEmpty()) {
                    Notification::send($receivers, new MaterialReturnDeclaredNotification($c->id, $c->work_order_id, $companyId, $c->inventory_request_id));
                }
            });

            return $c->fresh(['lines.product', 'events.actor', 'technician', 'warehouseLocation']);
        }, 3);
    }

    /**
     * Warehouse physically accepts the return handover.
     * Accepted good returns increase stock immediately.
     * Damaged, lost, or other discrepancies produce delta 0 stock movement.
     *
     * @param  array{lines: array<int, array<string, mixed>>, reason?: ?string}  $data
     */
    public static function receiveAccept(MaterialReturnCase $case, User $receiver, array $data): MaterialReturnCase
    {
        abort_unless($receiver->can('inventory.return.receive') || $receiver->hasPermissionTo('inventory.return.receive'), 403, 'Anda tidak memiliki hak akses untuk menerima fisik pengembalian material.');

        $companyId = CompanyService::currentId() ?? CompanyService::idFor($receiver);

        return DB::transaction(function () use ($case, $receiver, $data, $companyId) {
            // Lock order: SPK, case, lines, then stock. SPK approval takes the SPK row first and reads these lines.
            $workOrder = self::lockedWorkOrderOf($case, $companyId);

            $c = MaterialReturnCase::withoutCompany()
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->findOrFail($case->id);

            abort_unless($c->status === 'awaiting_receipt', 422, 'Kasus pengembalian harus berstatus menunggu penerimaan gudang untuk diterima.');
            abort_if((int) $c->technician_id === (int) $receiver->id, 403, 'Maker-checker: teknisi pemilik barang tidak boleh menerima fisiknya sendiri di gudang.');

            $warehouseLocationId = $c->warehouse_location_id;
            abort_unless(! empty($warehouseLocationId), 422, 'Lokasi gudang penerima tidak valid.');

            /** @var Collection<int, MaterialReturnLine> $lines */
            $lines = $c->lines()->lockForUpdate()->get()->keyBy('id');
            $inputLines = collect($data['lines'])->keyBy('id');
            abort_unless($inputLines->count() === $lines->count(), 422, 'Verifikasi fisik seluruh baris barang harus dilakukan lengkap.');

            $hasException = false;
            $requestCode = $c->inventoryRequest?->code;

            foreach ($lines as $lineId => $line) {
                $input = $inputLines->get($lineId);
                abort_unless($input !== null, 422, "Data verifikasi fisik baris {$line->public_id} tidak ditemukan.");

                $acceptedGood = (float) ($input['accepted_good_return_qty'] ?? 0);
                $damaged = (float) ($input['damaged_return_qty'] ?? 0);
                $lost = (float) ($input['lost_qty'] ?? 0);
                $other = (float) ($input['other_discrepancy_qty'] ?? 0);

                abort_if($acceptedGood < 0 || $damaged < 0 || $lost < 0 || $other < 0, 422, 'Jumlah penerimaan tidak boleh negatif.');

                $used = (float) $line->actual_used_qty;
                $issued = (float) $line->issued_qty;
                $sumPhysical = round($used + $acceptedGood + $damaged + $lost + $other, 2);

                if (abs($issued - $sumPhysical) > 0.001) {
                    abort(422, "Jumlah tidak cocok pada baris {$line->public_id}: diserahkan ({$issued}) harus sama dengan terpakai ({$used}) + diterima ({$acceptedGood}) + rusak ({$damaged}) + hilang ({$lost}) + lainnya ({$other}).");
                }

                if ($damaged > 0 || $lost > 0 || $other > 0) {
                    $hasException = true;
                }

                // Increment good physical stock ONLY for accepted_good_return_qty
                if ($acceptedGood > 0) {
                    StockService::receive(
                        $line->product_id,
                        $warehouseLocationId,
                        $acceptedGood,
                        'Penerimaan Fisik Pengembalian Material'.($requestCode === null ? '' : ': '.$requestCode),
                        MaterialReturnLine::class,
                        $line->id
                    );
                }

                $line->update([
                    'accepted_good_return_qty' => $acceptedGood,
                    'damaged_return_qty' => $damaged,
                    'lost_qty' => $lost,
                    'other_discrepancy_qty' => $other,
                    'discrepancy_code' => $input['discrepancy_code'] ?? $line->discrepancy_code,
                    'discrepancy_reason' => $input['discrepancy_reason'] ?? $line->discrepancy_reason,
                ]);
            }

            $fromStatus = $c->status;
            $toStatus = $hasException ? 'exception_open' : 'reconciled';

            $c->update([
                'status' => $toStatus,
                'received_by' => $receiver->id,
                'received_at' => now(),
                'reconciled_by' => $hasException ? null : $receiver->id,
                'reconciled_at' => $hasException ? null : now(),
            ]);

            MaterialReturnEvent::create([
                'company_id' => $companyId,
                'material_return_case_id' => $c->id,
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
                'action' => 'received_accepted',
                'actor_id' => $receiver->id,
                'reason' => $data['reason'] ?? ($hasException ? 'Penerimaan fisik selesai dengan selisih, kerusakan, atau kehilangan barang.' : 'Penerimaan fisik selesai sempurna dan telah direkonsiliasi.'),
                'payload' => [
                    'has_exception' => $hasException,
                    'lines' => $data['lines'],
                ],
            ]);

            AuditService::log('material_return_case', 'received_accepted', [
                'case_id' => $c->id,
                'received_by' => $receiver->id,
                'status' => $toStatus,
            ], $c);

            if ($toStatus === 'reconciled' && $workOrder !== null) {
                CompleteSpkAction::reconcileUsedQuantitiesFromMaterialReturns($workOrder);
            }

            if ($hasException) {
                DB::afterCommit(function () use ($c, $companyId, $receiver) {
                    // Not the receiver, who found the difference and so cannot be the one who resolves it.
                    $resolvers = StaffRecipients::holding($companyId, 'inventory.return.resolve', $receiver->id);
                    if ($resolvers->isEmpty()) {
                        $resolvers = StaffRecipients::holding($companyId, 'inventory.request.approve', $receiver->id);
                    }
                    if ($resolvers->isNotEmpty()) {
                        Notification::send($resolvers, new MaterialReturnExceptionNotification($c->id, $c->work_order_id, $companyId, $c->inventory_request_id));
                    }
                });
            }

            return $c->fresh(['lines.product', 'events.actor', 'technician', 'warehouseLocation', 'receiver', 'reconciler']);
        }, 3);
    }

    /**
     * Warehouse physically rejects the handover (atomic no-partial reject).
     * Transitions case to correction_required, zero stock delta.
     */
    public static function receiveReject(MaterialReturnCase $case, User $rejecter, string $reason): MaterialReturnCase
    {
        abort_unless($rejecter->can('inventory.return.receive') || $rejecter->hasPermissionTo('inventory.return.receive'), 403, 'Anda tidak memiliki hak akses untuk menolak serah terima pengembalian material.');

        $companyId = CompanyService::currentId() ?? CompanyService::idFor($rejecter);

        return DB::transaction(function () use ($case, $rejecter, $reason, $companyId) {
            $c = MaterialReturnCase::withoutCompany()
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->findOrFail($case->id);

            abort_unless($c->status === 'awaiting_receipt', 422, 'Kasus pengembalian harus berstatus menunggu penerimaan gudang untuk ditolak.');
            abort_if((int) $c->technician_id === (int) $rejecter->id, 403, 'Maker-checker: teknisi tidak boleh menolak serah terima barangnya sendiri.');
            abort_if(empty(trim($reason)), 422, 'Alasan penolakan serah terima fisik wajib diisi.');

            $fromStatus = $c->status;
            $toStatus = 'correction_required';

            $c->update([
                'status' => $toStatus,
                'rejected_by' => $rejecter->id,
                'rejected_at' => now(),
                'rejection_reason' => $reason,
            ]);

            MaterialReturnEvent::create([
                'company_id' => $companyId,
                'material_return_case_id' => $c->id,
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
                'action' => 'received_rejected',
                'actor_id' => $rejecter->id,
                'reason' => $reason,
                'payload' => null,
            ]);

            AuditService::log('material_return_case', 'received_rejected', [
                'case_id' => $c->id,
                'rejected_by' => $rejecter->id,
                'reason' => $reason,
            ], $c);

            DB::afterCommit(function () use ($c, $reason, $companyId) {
                $c->technician->notify(new MaterialReturnRejectedNotification($c->id, $reason, $companyId, $c->inventory_request_id));
            });

            return $c->fresh(['lines.product', 'events.actor', 'technician', 'warehouseLocation', 'rejecter']);
        }, 3);
    }

    /**
     * Authoritative resolver resolves exception_open case.
     * Receiver cannot be resolver (maker-checker).
     */
    public static function resolveException(MaterialReturnCase $case, User $resolver, string $resolutionNote): MaterialReturnCase
    {
        abort_unless($resolver->can('inventory.return.resolve') || $resolver->hasPermissionTo('inventory.return.resolve'), 403, 'Anda tidak memiliki hak akses untuk menyelesaikan selisih pengembalian material.');

        $companyId = CompanyService::currentId() ?? CompanyService::idFor($resolver);

        return DB::transaction(function () use ($case, $resolver, $resolutionNote, $companyId) {
            // Lock order: SPK, case, lines. The reconcile below rewrites the SPK's used quantities.
            $workOrder = self::lockedWorkOrderOf($case, $companyId);

            $c = MaterialReturnCase::withoutCompany()
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->findOrFail($case->id);

            abort_unless($c->status === 'exception_open', 422, 'Hanya kasus pengembalian yang selisihnya masih perlu diselesaikan yang dapat diselesaikan.');
            abort_if((int) $c->technician_id === (int) $resolver->id, 403, 'Teknisi tidak berhak menyelesaikan selisih barangnya sendiri.');
            abort_if((int) $c->received_by === (int) $resolver->id, 403, 'Penerima fisik gudang tidak boleh menyelesaikan selisih yang ia terima sendiri.');
            abort_if(empty(trim($resolutionNote)), 422, 'Catatan penyelesaian selisih wajib diisi.');

            $fromStatus = $c->status;
            $toStatus = 'resolved';

            $c->update([
                'status' => $toStatus,
                'resolved_by' => $resolver->id,
                'resolved_at' => now(),
                'resolution_note' => $resolutionNote,
            ]);

            MaterialReturnEvent::create([
                'company_id' => $companyId,
                'material_return_case_id' => $c->id,
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
                'action' => 'resolved',
                'actor_id' => $resolver->id,
                'reason' => $resolutionNote,
                'payload' => null,
            ]);

            AuditService::log('material_return_case', 'resolved', [
                'case_id' => $c->id,
                'resolved_by' => $resolver->id,
                'resolution_note' => $resolutionNote,
            ], $c);

            if ($workOrder !== null) {
                CompleteSpkAction::reconcileUsedQuantitiesFromMaterialReturns($workOrder);
            }

            return $c->fresh(['lines.product', 'events.actor', 'technician', 'warehouseLocation', 'resolver']);
        }, 3);
    }

    /**
     * The SPK a return belongs to: the one its case names, else the source of the request it came from. Neither link
     * changes once the case exists, so a plain read says which SPK row to lock before the case row.
     */
    private static function workOrderIdOf(MaterialReturnCase $case): ?int
    {
        if ($case->work_order_id !== null) {
            return (int) $case->work_order_id;
        }

        $source = InventoryRequest::withoutCompany()
            ->where('company_id', $case->company_id)
            ->whereKey($case->inventory_request_id)
            ->first(['source_type', 'source_id']);

        return $source?->source_type === 'work_order' && $source->source_id !== null ? (int) $source->source_id : null;
    }

    /** The SPK of a return, locked before the case row. Null when the return has none or it is gone. */
    private static function lockedWorkOrderOf(MaterialReturnCase $case, int $companyId): ?WorkOrder
    {
        $workOrderId = self::workOrderIdOf($case);

        return $workOrderId === null ? null : WorkOrder::withoutCompany()->where('company_id', $companyId)->lockForUpdate()->find($workOrderId);
    }

    /**
     * Compute a deterministic sha256 hash of the payload for conflict checking.
     *
     * @param  array<string, mixed>  $data
     */
    private static function hashPayload(array $data): string
    {
        $normalized = self::normalizePayload($data);

        return hash('sha256', (string) json_encode($normalized));
    }

    /**
     * Recursively sort array keys to generate a deterministic canonical structure.
     */
    private static function normalizePayload(mixed $data): mixed
    {
        if (! is_array($data)) {
            return $data;
        }

        // Exclude ephemeral idempotency_key itself from the hash content
        unset($data['idempotency_key']);

        foreach ($data as $key => $value) {
            $data[$key] = self::normalizePayload($value);
        }

        ksort($data);

        return $data;
    }
}
