<?php

namespace App\Queries\Admin;

use App\Models\User;
use App\Services\Core\CompanyService;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Modules\Inventory\Models\InventoryRequest;

/**
 * Adapter / Read Model untuk mengintegrasikan Inventory Request submitted
 * ke centralized Approval Inbox tanpa generic engine atau mutasi duplikat.
 */
class InventoryRequestApprovalAdapter
{
    /**
     * Cek apakah aktor berhak melihat antrean approval Inventory Request.
     */
    public static function isEligible(?object $user): bool
    {
        return $user !== null && (
            $user->can('inventory.request.approve') || $user->can('inventory.request.reject')
        );
    }

    /**
     * Cek kelayakan maker-checker untuk satu baris Inventory Request.
     * Menggunakan aturan kanonikal dari InventoryRequestPolicy.
     */
    public static function eligibleFor(InventoryRequest $request, ?object $user): bool
    {
        if ($user === null || ! self::isEligible($user)) {
            return false;
        }

        if ((int) $request->company_id !== (int) CompanyService::idFor($user)) {
            return false;
        }

        if ($request->status !== 'submitted') {
            return false;
        }

        // Maker-checker: pemohon tidak boleh menyetujui atau menolak permintaannya sendiri
        if ((int) $request->requested_by === (int) $user->id) {
            return false;
        }

        return true;
    }

    /**
     * Query dasar yang dibatasi company dan maker-checker untuk Inventory Request submitted.
     *
     * @return Builder<InventoryRequest>
     */
    public static function base(Request $request, bool $withRelations = true): Builder
    {
        $user = $request->user();
        if ($user === null || ! self::isEligible($user)) {
            return InventoryRequest::query()->whereRaw('1 = 0');
        }

        $query = InventoryRequest::query()
            ->where('company_id', CompanyService::idFor($user))
            ->where('status', 'submitted');

        if ($withRelations) {
            $query->with([
                'requester:id,name',
                'items.product:id,name',
            ]);
        }

        return self::applyMakerChecker($query, $user);
    }

    /**
     * Terapkan filter server-side pada Inventory Request.
     *
     * @return Builder<InventoryRequest>
     */
    public static function filtered(Request $request): Builder
    {
        $query = self::base($request, true);

        $query->when($request->input('domain'), function (Builder $q, string $v): void {
            if ($v === 'inventory_request' || $v === 'inventory') {
                // Domain inventory valid
            } else {
                $q->whereRaw('1 = 0');
            }
        });

        $query->when($request->input('type'), function (Builder $q, string $v): void {
            if ($v === 'inventory_request') {
                $q->where(function (Builder $sq): void {
                    $sq->whereNull('source_type')
                        ->orWhere('source_type', 'inventory_request');
                });
            } else {
                $q->where('source_type', $v);
            }
        });

        $creator = $request->input('creator');
        if (is_int($creator)) {
            $creator = (string) $creator;
        }

        if (is_string($creator) && ctype_digit($creator)) {
            $creatorId = (int) $creator;
            if (ApprovalInboxQuery::isEligibleRequester($request, $creatorId)) {
                $query->where('requested_by', $creatorId);
            }
        }

        $query->when($request->input('status'), function (Builder $q, string $v): void {
            if ($v === 'submitted' || $v === 'waiting_review') {
                $q->where('status', 'submitted');
            } else {
                $q->whereRaw('1 = 0');
            }
        });

        $query->when($request->input('search'), function (Builder $q, string $v): void {
            $term = trim($v);
            if ($term === '') {
                return;
            }
            $like = '%'.$term.'%';
            $q->where(function (Builder $sq) use ($like): void {
                $sq->where('code', 'like', $like)
                    ->orWhere('reason', 'like', $like);
            });
        });

        $query->when($request->input('date_from'), function (Builder $q, string $v): void {
            if (strtotime($v) !== false) {
                $q->where(function (Builder $sq) use ($v): void {
                    $sq->whereDate('submitted_at', '>=', $v)
                        ->orWhere(function (Builder $ssq) use ($v): void {
                            $ssq->whereNull('submitted_at')->whereDate('created_at', '>=', $v);
                        });
                });
            }
        });

        $query->when($request->input('date_to'), function (Builder $q, string $v): void {
            if (strtotime($v) !== false) {
                $q->where(function (Builder $sq) use ($v): void {
                    $sq->whereDate('submitted_at', '<=', $v)
                        ->orWhere(function (Builder $ssq) use ($v): void {
                            $ssq->whereNull('submitted_at')->whereDate('created_at', '<=', $v);
                        });
                });
            }
        });

        $query->when($request->input('priority'), function (Builder $q, string $v): void {
            // Inventory Request secara implisit berprioritas medium
            if ($v !== 'medium') {
                $q->whereRaw('1 = 0');
            }
        });

        $sort = $request->input('sort');
        $sort = is_string($sort) && in_array($sort, ApprovalInboxQuery::SORTABLE, true) ? $sort : 'created_at';
        $dir = strtolower((string) $request->input('direction', 'desc'));
        $dir = $dir === 'asc' ? 'asc' : 'desc';

        $connection = $query->getConnection();
        $driver = $connection instanceof Connection ? $connection->getDriverName() : null;
        $titleExpr = $driver === 'sqlite'
            ? "COALESCE(NULLIF(reason, ''), 'Permintaan Material #' || code)"
            : "COALESCE(NULLIF(reason, ''), CONCAT('Permintaan Material #', code))";

        match ($sort) {
            'code' => $query->orderBy('code', $dir),
            'title' => $query->orderByRaw("{$titleExpr} {$dir}"),
            'type' => $query->orderByRaw("COALESCE(NULLIF(source_type, ''), 'inventory_request') {$dir}"),
            'priority' => $query->orderByRaw("'medium' {$dir}"),
            default => $query->orderBy('created_at', $dir),
        };

        return $query->orderBy('id', 'asc');
    }

    /**
     * Batas maker-checker untuk query Inventory Request.
     *
     * @param  Builder<InventoryRequest>  $query
     * @return Builder<InventoryRequest>
     */
    public static function applyMakerChecker(Builder $query, object $user): Builder
    {
        return $query->where(function (Builder $q) use ($user): void {
            $q->whereNull('requested_by')
                ->orWhere('requested_by', '!=', $user->id);
        });
    }

    /**
     * Query item Inventory Request yang sudah diproses (approved / rejected / lanjutan).
     *
     * @return Builder<InventoryRequest>
     */
    public static function processed(object $user): Builder
    {
        $query = InventoryRequest::query()
            ->where('company_id', CompanyService::idFor($user))
            ->whereIn('status', ['approved_reserved', 'preparing', 'ready_for_pickup', 'issued', 'rejected']);

        return self::applyMakerChecker($query, $user);
    }

    /**
     * Daftar pemohon yang berasal dari baris Inventory Request eligible.
     *
     * @return Collection<int, array{id: int, name: string}>
     */
    public static function requesterOptions(Request $request): Collection
    {
        $creatorIds = self::base($request, false)
            ->distinct()
            ->whereNotNull('requested_by')
            ->pluck('requested_by')
            ->filter();

        if ($creatorIds->isEmpty()) {
            return collect();
        }

        return User::query()
            ->whereIn('id', $creatorIds)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $u): array => [
                'id' => (int) $u->id,
                'name' => (string) $u->name,
            ])
            ->values();
    }

    /**
     * Daftar tipe yang berasal dari baris Inventory Request eligible.
     *
     * @return Collection<int, string>
     */
    public static function typeOptions(Request $request): Collection
    {
        /** @var Collection<int, string> $types */
        $types = self::base($request, false)
            ->distinct()
            ->pluck('source_type')
            ->map(fn (?string $t): string => ($t !== null && $t !== '') ? (string) $t : 'inventory_request')
            ->unique()
            ->sort()
            ->values();

        return $types;
    }
}
