<?php

namespace App\Queries\Admin;

use Carbon\Carbon;
use Countable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator as LengthAwarePaginatorContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use IteratorAggregate;
use Modules\Inventory\Models\InventoryRequest;
use Modules\SPK\Models\WorkOrder;
use Traversable;

/**
 * Unified query wrapper yang menggabungkan SPK dan Inventory Request
 * untuk endpoint centralized Approval Inbox tanpa generic engine.
 */
class ApprovalInboxUnifiedQuery implements Countable, IteratorAggregate
{
    /**
     * @param  Builder<WorkOrder>  $spkQuery
     * @param  Builder<InventoryRequest>  $inventoryQuery
     */
    public function __construct(
        private Builder $spkQuery,
        private Builder $inventoryQuery,
        private Request $request,
    ) {}

    private ?int $cachedCount = null;

    public function count(): int
    {
        if ($this->cachedCount !== null) {
            return $this->cachedCount;
        }

        if ($this->hasFilters()) {
            return $this->cachedCount = (int) ($this->spkQuery->count() + $this->inventoryQuery->count());
        }

        return $this->cachedCount = (int) (
            ApprovalInboxQuery::spkWaitingCount($this->request) +
            ApprovalInboxQuery::inventoryWaitingCount($this->request)
        );
    }

    private function hasFilters(): bool
    {
        return $this->request->filled('type')
            || $this->request->filled('creator')
            || $this->request->filled('search')
            || $this->request->filled('date_from')
            || $this->request->filled('date_to')
            || $this->request->filled('priority');
    }

    /**
     * @return Collection<int, WorkOrder|InventoryRequest>
     */
    public function get(): Collection
    {
        /** @var Collection<int, WorkOrder> $spkItems */
        $spkItems = $this->spkQuery->get();
        /** @var Collection<int, InventoryRequest> $inventoryItems */
        $inventoryItems = $this->inventoryQuery->get();

        $combined = $spkItems->concat($inventoryItems);

        return $this->sortCollection($combined);
    }

    public function first(): mixed
    {
        return $this->get()->first();
    }

    public function isEmpty(): bool
    {
        return $this->count() === 0;
    }

    public function isNotEmpty(): bool
    {
        return $this->count() > 0;
    }

    public function paginate(int $perPage = 10): LengthAwarePaginatorContract
    {
        $page = max(1, Paginator::resolveCurrentPage());
        $total = $this->count();
        $maxNeeded = $page * $perPage;

        // Bounded fetch: bounded to maximum possible candidates for the requested page
        $spkItems = (clone $this->spkQuery)->take($maxNeeded)->get();
        $inventoryItems = (clone $this->inventoryQuery)->take($maxNeeded)->get();

        $combined = $spkItems->concat($inventoryItems);
        $sorted = $this->sortCollection($combined);
        $slice = $sorted->slice(($page - 1) * $perPage, $perPage)->values();

        return new LengthAwarePaginator(
            $slice,
            $total,
            $perPage,
            $page,
            [
                'path' => Paginator::resolveCurrentPath(),
                'query' => $this->request->query(),
            ]
        );
    }

    public function getIterator(): Traversable
    {
        return $this->get()->getIterator();
    }

    /**
     * @param  Collection<int, WorkOrder|InventoryRequest>  $collection
     * @return Collection<int, WorkOrder|InventoryRequest>
     */
    private function sortCollection(Collection $collection): Collection
    {
        $sort = (string) $this->request->input('sort', 'created_at');
        $sort = in_array($sort, ApprovalInboxQuery::SORTABLE, true) ? $sort : 'created_at';
        $dir = strtolower((string) $this->request->input('direction', 'desc'));
        $descending = $dir !== 'asc';

        return $collection->sort(function (WorkOrder|InventoryRequest $a, WorkOrder|InventoryRequest $b) use ($sort, $descending): int {
            $valA = $this->getItemSortValue($a, $sort);
            $valB = $this->getItemSortValue($b, $sort);

            $cmp = $this->compareValues($valA, $valB);
            if ($cmp !== 0) {
                return $descending ? -$cmp : $cmp;
            }

            // Tie-breaker 1: domain determinism
            $domainA = $a instanceof WorkOrder ? 'spk' : 'inventory_request';
            $domainB = $b instanceof WorkOrder ? 'spk' : 'inventory_request';
            $cmpDomain = strcmp($domainA, $domainB);
            if ($cmpDomain !== 0) {
                return $cmpDomain;
            }

            // Tie-breaker 2: id determinism
            $idA = (int) $a->id;
            $idB = (int) $b->id;
            if ($idA !== $idB) {
                return $idA <=> $idB;
            }

            return 0;
        })->values();
    }

    private function getItemSortValue(WorkOrder|InventoryRequest $item, string $sort): mixed
    {
        if ($item instanceof WorkOrder) {
            return match ($sort) {
                'code' => (string) $item->code,
                'title' => (string) $item->title,
                'type' => (string) $item->type,
                'priority' => (string) $item->priority,
                default => $item->created_at->timestamp ?? 0,
            };
        }

        $createdAt = $item->getAttribute('created_at');
        $timestamp = $createdAt instanceof Carbon ? $createdAt->timestamp : 0;

        return match ($sort) {
            'code' => (string) $item->code,
            'title' => ($item->reason !== null && $item->reason !== '') ? (string) $item->reason : 'Permintaan Material #'.$item->code,
            'type' => ($item->source_type !== null && $item->source_type !== '') ? (string) $item->source_type : 'inventory_request',
            'priority' => 'medium',
            default => $timestamp,
        };
    }

    private function compareValues(mixed $a, mixed $b): int
    {
        if (is_string($a) && is_string($b)) {
            return strcasecmp($a, $b);
        }

        return $a <=> $b;
    }
}
