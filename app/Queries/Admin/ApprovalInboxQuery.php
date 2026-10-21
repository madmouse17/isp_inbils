<?php

namespace App\Queries\Admin;

use App\Models\User;
use App\Services\Core\CompanyService;
use App\Support\FieldCheckIn;
use App\Support\Money;
use App\Support\PublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Modules\Inventory\Models\InventoryRequest;
use Modules\Inventory\Models\InventoryRequestItem;
use Modules\Inventory\Models\Stock;
use Modules\SPK\Models\WorkOrder;
use Modules\SPK\Models\WorkOrderItem;
use Modules\SPK\Support\SpkApprovalImpact;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

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
            $query->with(['assignee:id,public_id,name', 'items.product:id,public_id,name,unit_id', 'items.product.unit:id,name,symbol']);
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

        // The requester of an SPK is the technician it is assigned to: the one who did the work and submitted it.
        if (($requesterId = self::requesterFilter($request)) !== null) {
            $query->where('assigned_to', $requesterId);
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

        // The date of the request is the moment the technician submitted the SPK, not the day the office made it.
        $query->when($request->input('date_from'), function (Builder $q, string $v): void {
            if (strtotime($v) !== false) {
                $q->where(function (Builder $sq) use ($v): void {
                    $sq->whereDate('submitted_at', '>=', $v)
                        ->orWhere(fn (Builder $legacy) => $legacy->whereNull('submitted_at')->whereDate('created_at', '>=', $v));
                });
            }
        });

        $query->when($request->input('date_to'), function (Builder $q, string $v): void {
            if (strtotime($v) !== false) {
                $q->where(function (Builder $sq) use ($v): void {
                    $sq->whereDate('submitted_at', '<=', $v)
                        ->orWhere(fn (Builder $legacy) => $legacy->whereNull('submitted_at')->whereDate('created_at', '<=', $v));
                });
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
     * The user the `creator` filter names (a public id), when that user is an eligible requester for the actor; any
     * other value (an integer, another company's user, a user with nothing waiting) filters nothing.
     */
    public static function requesterFilter(Request $request): ?int
    {
        $userId = PublicId::resolveOne(User::class, $request->input('creator'));

        return $userId !== null && self::isEligibleRequester($request, $userId) ? $userId : null;
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
            ->where('assigned_to', $userId)
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
     * @return Collection<int, array{id: string, name: string}>
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
                    $query->whereIn('id', self::base($request, false)->select('assigned_to'));
                }

                if ($inventoryAllowed) {
                    $query->orWhereIn('id', self::inventoryBase($request, false)->select('requested_by'));
                }
            })
            ->orderBy('name')
            ->get(['id', 'public_id', 'name'])
            ->map(fn (User $u): array => [
                'id' => (string) $u->public_id,
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

    /**
     * What the review sheet loads when it opens, for exactly the rows the inbox lists to this actor: the same
     * eligibility and maker-checker rules (the base queries) and, on top of them, the view policy, because the detail
     * shows the visit, the check-in and the stock. An unknown id, another company's row, a row that is no longer waiting,
     * a row the actor may not act on or open: all null, so nothing tells them apart.
     *
     * @return array{impact: list<string>, evidence: list<array{id: string, url: string, caption: ?string}>, technician: ?array{name: string}, check_in: ?array<string, mixed>, items: list<array{name: string, quantity: string, unit: ?string, available: ?string}>}|null
     */
    public static function review(Request $request, mixed $compositeId): ?array
    {
        $user = $request->user();

        if ($user === null || ! is_string($compositeId) || substr_count($compositeId, ':') !== 1) {
            return null;
        }

        [$domain, $publicId] = explode(':', $compositeId);

        return match ($domain) {
            'spk' => self::spkReview($request, $user, $publicId),
            'inventory_request' => self::inventoryReview($request, $user, $publicId),
            default => null,
        };
    }

    /**
     * How much of each line of a request can still be reserved: the stock minus what is reserved, at the place the
     * approval checks (the item's source, else the request's warehouse; InventoryRequestService::approve). Keyed by the
     * public id of the item; a line with no place to take it from has no entry. One query for the whole request.
     *
     * @return array<string, string>
     */
    public static function inventoryStockAvailable(InventoryRequest $inventoryRequest): array
    {
        $inventoryRequest->loadMissing('items');
        $placeOf = fn (InventoryRequestItem $item): ?int => $item->source_location_id ?? $inventoryRequest->warehouse_location_id;
        $items = $inventoryRequest->items->filter(fn (InventoryRequestItem $item): bool => $placeOf($item) !== null);

        if ($items->isEmpty()) {
            return [];
        }

        $stocks = Stock::withoutCompany()
            ->where('company_id', $inventoryRequest->company_id)
            ->whereIn('product_id', $items->pluck('product_id')->unique())
            ->whereIn('location_id', $items->map($placeOf)->unique())
            ->get(['product_id', 'location_id', 'quantity', 'reserved_quantity'])
            ->keyBy(fn (Stock $stock): string => $stock->product_id.':'.$stock->location_id);

        return $items->mapWithKeys(function (InventoryRequestItem $item) use ($stocks, $placeOf): array {
            $stock = $stocks->get($item->product_id.':'.$placeOf($item));
            $available = $stock === null ? '0' : Money::sub($stock->quantity, $stock->reserved_quantity);

            return [$item->public_id => Money::compare($available, '0') < 0 ? '0.00' : Money::round($available)];
        })->all();
    }

    /** @return array<string, mixed>|null */
    private static function spkReview(Request $request, User $user, string $publicId): ?array
    {
        /** @var WorkOrder|null $workOrder */
        $workOrder = self::base($request, false)->wherePublicId($publicId)->first();

        if ($workOrder === null || ! $user->can('view', $workOrder)) {
            return null;
        }

        $workOrder->load(['assignee:id,public_id,name', 'items.product.unit']);
        $checkIn = FieldCheckIn::recordFor($workOrder, 'work_order');

        return [
            'impact' => SpkApprovalImpact::for($workOrder),
            // Three thumbnails are enough to judge the work; the rest open on the SPK page.
            'evidence' => $workOrder->getMedia('evidence')
                ->filter(fn (Media $media): bool => str_starts_with((string) $media->mime_type, 'image/'))
                ->take(3)
                ->map(fn (Media $media): array => [
                    'id' => (string) $media->getAttribute('public_id'),
                    'url' => route('admin.media.show', $media, false),
                    'caption' => $media->getCustomProperty('caption'),
                ])
                ->values()
                ->all(),
            'technician' => $workOrder->assignee === null ? null : ['name' => $workOrder->assignee->name],
            // Only what the sheet words itself: the technician's own coordinates never leave the server.
            'check_in' => $checkIn === null ? null : Arr::only($checkIn, ['status', 'distance_m', 'accuracy_m', 'reason']),
            'items' => $workOrder->items->map(fn (WorkOrderItem $item): array => [
                'name' => $item->product?->name ?? '-',
                'quantity' => (string) $item->quantity_used,
                'unit' => $item->product?->unit?->symbol ?? $item->product?->unit?->name,
                'available' => null,
            ])->values()->all(),
        ];
    }

    /** @return array<string, mixed>|null */
    private static function inventoryReview(Request $request, User $user, string $publicId): ?array
    {
        /** @var InventoryRequest|null $inventoryRequest */
        $inventoryRequest = self::inventoryBase($request, false)->wherePublicId($publicId)->first();

        if ($inventoryRequest === null || ! $user->can('view', $inventoryRequest)) {
            return null;
        }

        $inventoryRequest->load('items.product.unit');
        $available = self::inventoryStockAvailable($inventoryRequest);

        return [
            'impact' => [],
            'evidence' => [],
            'technician' => null,
            'check_in' => null,
            'items' => $inventoryRequest->items->map(fn (InventoryRequestItem $item): array => [
                'name' => $item->product?->name ?? '-',
                'quantity' => (string) $item->quantity_requested,
                'unit' => $item->product?->unit?->symbol ?? $item->product?->unit?->name,
                'available' => $available[$item->public_id] ?? null,
            ])->values()->all(),
        ];
    }
}
