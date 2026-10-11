<?php

namespace App\Queries\Admin;

use App\Models\User;
use App\Services\Core\CompanyService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Modules\Inventory\Models\InventoryRequest;
use Modules\SPK\Models\WorkOrder;

/**
 * Read model untuk Approval Inbox (SPK waiting_review dan Inventory Request submitted).
 *
 * Query selalu dibatasi tenant company dari aktor sebelum filter, pagination, atau count
 * diaplikasikan. Kelayakan (maker-checker) ditegakkan per-baris dan dipakai konsisten
 * oleh list, summary counter, serta badge sehingga aktor tidak bisa mengintip atau
 * membocorkan baris yang tidak berhak mereka tindak.
 */
class ApprovalInboxQuery
{
    public const SORTABLE = ['code', 'title', 'type', 'priority', 'created_at'];

    private const PER_PAGE_OPTIONS = [10, 25, 50];

    /**
     * Base company-scoped query for SPK waiting for review, strictly constrained
     * by maker-checker eligibility for the current actor.
     *
     * @return Builder<WorkOrder>
     */
    public static function base(Request $request, bool $withRelations = true): Builder
    {
        $user = $request->user();
        if ($user === null || ! self::isEligibleForSpk($user)) {
            return WorkOrder::query()->whereRaw('1 = 0');
        }

        $query = WorkOrder::query()
            ->forCompany(CompanyService::idFor($user))
            ->where('status', 'waiting_review');

        if ($withRelations) {
            $query->with(['creator:id,name', 'items.product:id,name']);
        }

        return self::applyMakerCheckerEligibility($query, $user);
    }

    /**
     * Base company-scoped query for Inventory Request submitted.
     *
     * @return Builder<InventoryRequest>
     */
    public static function inventoryBase(Request $request, bool $withRelations = true): Builder
    {
        return InventoryRequestApprovalAdapter::base($request, $withRelations);
    }

    /**
     * Filtered SPK query.
     *
     * @return Builder<WorkOrder>
     */
    public static function spkFiltered(Request $request): Builder
    {
        $query = self::base($request, true);

        $query->when($request->input('domain'), function (Builder $q, string $v): void {
            if ($v === 'spk') {
                // Allowed domain
            } else {
                $q->whereRaw('1 = 0');
            }
        });

        $query->when($request->input('type'), function (Builder $q, string $v): void {
            $q->where('type', $v);
        });

        $creator = $request->input('creator');
        if (is_int($creator)) {
            $creator = (string) $creator;
        }

        if (is_string($creator) && ctype_digit($creator)) {
            $creatorId = (int) $creator;
            if (self::isEligibleRequester($request, $creatorId)) {
                $query->where('created_by', $creatorId);
            }
        }

        $query->when($request->input('status'), function (Builder $q, string $v): void {
            if ($v === 'waiting_review') {
                $q->where('status', 'waiting_review');
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
                    ->orWhere('title', 'like', $like);
            });
        });

        $query->when($request->input('date_from'), function (Builder $q, string $v): void {
            if (strtotime($v) !== false) {
                $q->whereDate('created_at', '>=', $v);
            }
        });

        $query->when($request->input('date_to'), function (Builder $q, string $v): void {
            if (strtotime($v) !== false) {
                $q->whereDate('created_at', '<=', $v);
            }
        });

        $query->when($request->input('priority'), function (Builder $q, string $v): void {
            if (in_array($v, ['low', 'medium', 'high', 'urgent'], true)) {
                $q->where('priority', $v);
            }
        });

        $sort = $request->input('sort');
        $sort = is_string($sort) && in_array($sort, self::SORTABLE, true) ? $sort : 'created_at';
        $dir = strtolower((string) $request->input('direction', 'desc'));
        $dir = $dir === 'asc' ? 'asc' : 'desc';

        return $query->orderBy($sort, $dir)->orderBy('id', 'asc');
    }

    /**
     * Filtered Inventory Request query.
     *
     * @return Builder<InventoryRequest>
     */
    public static function inventoryFiltered(Request $request): Builder
    {
        return InventoryRequestApprovalAdapter::filtered($request);
    }

    /**
     * Filtered query used by the inbox list and tests.
     * Mengembalikan Builder<WorkOrder>, Builder<InventoryRequest>, atau ApprovalInboxUnifiedQuery.
     *
     * @return Builder<WorkOrder>|Builder<InventoryRequest>|ApprovalInboxUnifiedQuery
     */
    public static function filtered(Request $request): mixed
    {
        $user = $request->user();
        if (! self::isEligible($user)) {
            return WorkOrder::query()->whereRaw('1 = 0');
        }

        $domain = $request->input('domain');

        $spkAllowed = ($domain === null || $domain === '' || $domain === 'spk')
            && self::isEligibleForSpk($user);

        $invAllowed = ($domain === null || $domain === '' || $domain === 'inventory_request' || $domain === 'inventory')
            && self::isEligibleForInventoryRequest($user);

        if (! $spkAllowed && ! $invAllowed) {
            return WorkOrder::query()->whereRaw('1 = 0');
        }

        if ($spkAllowed && ! $invAllowed) {
            return self::spkFiltered($request);
        }

        if (! $spkAllowed) {
            return self::inventoryFiltered($request);
        }

        return new ApprovalInboxUnifiedQuery(
            self::spkFiltered($request),
            self::inventoryFiltered($request),
            $request
        );
    }

    public static function perPage(Request $request): int
    {
        $perPage = (int) $request->input('per_page', 10);

        return in_array($perPage, self::PER_PAGE_OPTIONS, true) ? $perPage : 10;
    }

    /**
     * Whether the actor may sit on the approval queue at all.
     */
    public static function isEligible(?object $user): bool
    {
        return self::isEligibleForSpk($user) || self::isEligibleForInventoryRequest($user);
    }

    /**
     * Cek apakah aktor berhak melihat approval SPK.
     */
    public static function isEligibleForSpk(?object $user): bool
    {
        return $user !== null && ($user->can('spk.approve') || $user->can('spk.reject'));
    }

    /**
     * Cek apakah aktor berhak melihat approval Inventory Request.
     */
    public static function isEligibleForInventoryRequest(?object $user): bool
    {
        return InventoryRequestApprovalAdapter::isEligible($user);
    }

    /**
     * Maker-checker eligibility for a single waiting_review SPK row.
     */
    public static function eligibleFor(WorkOrder $workOrder, ?object $user): bool
    {
        if ($user === null || ! self::isEligibleForSpk($user)) {
            return false;
        }

        if ($workOrder->getAttribute('status') !== 'waiting_review') {
            return false;
        }

        $createdBy = $workOrder->getAttribute('created_by');
        if ($createdBy !== null && (int) $createdBy === (int) $user->id) {
            return false;
        }

        $assignedTo = $workOrder->getAttribute('assigned_to');
        if ($assignedTo !== null && (int) $assignedTo === (int) $user->id) {
            return false;
        }

        return true;
    }

    /**
     * Maker-checker eligibility for a single submitted Inventory Request row.
     */
    public static function eligibleForInventoryRequest(InventoryRequest $request, ?object $user): bool
    {
        return InventoryRequestApprovalAdapter::eligibleFor($request, $user);
    }

    /**
     * Determine if a user ID is an eligible requester in SPK or Inventory Request for current actor.
     */
    public static function isEligibleRequester(Request $request, int $userId): bool
    {
        $user = $request->user();
        if (! self::isEligible($user)) {
            return false;
        }

        $cacheKey = '_approval_eligible_requester_'.$userId;
        if ($request->attributes->has($cacheKey)) {
            return (bool) $request->attributes->get($cacheKey);
        }

        $spkMatch = self::isEligibleForSpk($user) && self::base($request, false)
            ->where('created_by', $userId)
            ->exists();

        if ($spkMatch) {
            $request->attributes->set($cacheKey, true);

            return true;
        }

        $invMatch = self::isEligibleForInventoryRequest($user) && self::inventoryBase($request, false)
            ->where('requested_by', $userId)
            ->exists();

        $request->attributes->set($cacheKey, $invMatch);

        return $invMatch;
    }

    public static function spkWaitingCount(Request $request): int
    {
        $cacheKey = '__approval_spk_waiting_count_'.spl_object_hash($request);
        if ($request->attributes->has($cacheKey)) {
            return (int) $request->attributes->get($cacheKey);
        }

        $count = self::base($request, false)->count();
        $request->attributes->set($cacheKey, $count);

        return $count;
    }

    public static function inventoryWaitingCount(Request $request): int
    {
        $cacheKey = '__approval_inv_waiting_count_'.spl_object_hash($request);
        if ($request->attributes->has($cacheKey)) {
            return (int) $request->attributes->get($cacheKey);
        }

        $count = self::inventoryBase($request, false)->count();
        $request->attributes->set($cacheKey, $count);

        return $count;
    }

    /**
     * Summary counters scoped to company + eligibility.
     *
     * @return array{waiting_for_me: int, processed: int}
     */
    public static function summary(Request $request): array
    {
        $user = $request->user();

        if (! self::isEligible($user)) {
            return ['waiting_for_me' => 0, 'processed' => 0];
        }

        $cacheKey = '__approval_inbox_summary_'.spl_object_hash($request);
        if ($request->attributes->has($cacheKey)) {
            /** @var array{waiting_for_me: int, processed: int} */
            return $request->attributes->get($cacheKey);
        }

        $domain = $request->input('domain');

        $spkAllowed = ($domain === null || $domain === '' || $domain === 'spk')
            && self::isEligibleForSpk($user);

        $inventoryAllowed = ($domain === null || $domain === '' || $domain === 'inventory_request' || $domain === 'inventory')
            && self::isEligibleForInventoryRequest($user);

        if (! $spkAllowed && ! $inventoryAllowed) {
            $result = ['waiting_for_me' => 0, 'processed' => 0];
            $request->attributes->set($cacheKey, $result);

            return $result;
        }

        $waiting = 0;
        $processed = 0;

        if ($spkAllowed) {
            $waiting += self::spkWaitingCount($request);

            $processedQuery = WorkOrder::query()
                ->forCompany(CompanyService::idFor($user))
                ->whereIn('status', ['completed', 'rejected']);

            $processed += self::applyMakerCheckerEligibility($processedQuery, $user)
                ->count();
        }

        if ($inventoryAllowed) {
            $waiting += self::inventoryWaitingCount($request);

            $processedInvQuery = InventoryRequestApprovalAdapter::processed($user);
            $processed += $processedInvQuery->count();
        }

        $result = [
            'waiting_for_me' => (int) $waiting,
            'processed' => (int) $processed,
        ];

        $request->attributes->set($cacheKey, $result);

        return $result;
    }

    /**
     * Lazy badge count: strictly mirrors summary.waiting_for_me.
     *
     * Returns null when the actor cannot approve/reject any domain, so the
     * UI omits the badge entirely rather than showing 0.
     */
    public static function badgeCount(Request $request): ?int
    {
        $user = $request->user();

        if (! self::isEligible($user)) {
            return null;
        }

        $domain = $request->input('domain');
        if (is_string($domain) && $domain !== '' && ! in_array($domain, ['spk', 'inventory_request', 'inventory'], true)) {
            return 0;
        }

        $summary = self::summary($request);

        return $summary['waiting_for_me'];
    }

    /**
     * Requesters derived strictly from eligible waiting items for the actor.
     *
     * @return Collection<int, array{id: int, name: string}>
     */
    public static function requesterOptions(Request $request): Collection
    {
        $user = $request->user();

        if (! self::isEligible($user)) {
            return collect();
        }

        $domain = $request->input('domain');

        $spkAllowed = ($domain === null || $domain === '' || $domain === 'spk')
            && self::isEligibleForSpk($user);

        $inventoryAllowed = ($domain === null || $domain === '' || $domain === 'inventory_request' || $domain === 'inventory')
            && self::isEligibleForInventoryRequest($user);

        if (! $spkAllowed && ! $inventoryAllowed) {
            return collect();
        }

        return User::query()
            ->where('company_id', CompanyService::idFor($user))
            ->where(function (Builder $query) use ($request, $spkAllowed, $inventoryAllowed): void {
                if ($spkAllowed) {
                    $query->whereIn('id', self::base($request, false)->select('created_by'));
                }

                if ($inventoryAllowed) {
                    $query->orWhereIn('id', self::inventoryBase($request, false)->select('requested_by'));
                }
            })
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $u): array => [
                'id' => (int) $u->id,
                'name' => (string) $u->name,
            ])
            ->values();
    }

    /**
     * Types derived strictly from eligible waiting items for the actor.
     *
     * @return Collection<int, string>
     */
    public static function typeOptions(Request $request): Collection
    {
        $user = $request->user();

        if (! self::isEligible($user)) {
            return collect();
        }

        $domain = $request->input('domain');

        $spkAllowed = ($domain === null || $domain === '' || $domain === 'spk')
            && self::isEligibleForSpk($user);

        $inventoryAllowed = ($domain === null || $domain === '' || $domain === 'inventory_request' || $domain === 'inventory')
            && self::isEligibleForInventoryRequest($user);

        if (! $spkAllowed && ! $inventoryAllowed) {
            return collect();
        }

        $types = collect();

        if ($spkAllowed) {
            $spkTypes = self::base($request, false)
                ->distinct()
                ->pluck('type')
                ->filter();
            $types = $types->concat($spkTypes);
        }

        if ($inventoryAllowed) {
            $invTypes = InventoryRequestApprovalAdapter::typeOptions($request);
            $types = $types->concat($invTypes);
        }

        return $types->unique()->sort()->values();
    }

    /**
     * Terapkan batas maker-checker pada query SPK waiting_review.
     *
     * @param  Builder<WorkOrder>  $query
     * @return Builder<WorkOrder>
     */
    public static function applyMakerCheckerEligibility(Builder $query, object $user): Builder
    {
        return $query
            ->where(function (Builder $q) use ($user): void {
                $q->whereNull('created_by')
                    ->orWhere('created_by', '!=', $user->id);
            })
            ->where(function (Builder $q) use ($user): void {
                $q->whereNull('assigned_to')
                    ->orWhere('assigned_to', '!=', $user->id);
            });
    }
}
