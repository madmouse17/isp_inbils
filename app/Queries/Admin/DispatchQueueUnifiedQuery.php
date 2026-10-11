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
use Modules\SPK\Models\WorkOrder;
use Modules\Ticketing\Models\Ticket;
use Traversable;

/**
 * Unified query wrapper yang menggabungkan Ticket dan SPK
 * untuk endpoint centralized Dispatch Queue tanpa generic engine.
 */
class DispatchQueueUnifiedQuery implements Countable, IteratorAggregate
{
    private const MAX_FETCH_ITEMS = 1000;

    /**
     * @param  Builder<Ticket>  $ticketQuery
     * @param  Builder<WorkOrder>  $spkQuery
     */
    public function __construct(
        private Builder $ticketQuery,
        private Builder $spkQuery,
        private Request $request,
    ) {}

    private ?int $cachedCount = null;

    public function count(): int
    {
        if ($this->cachedCount !== null) {
            return $this->cachedCount;
        }

        return $this->cachedCount = (int) ($this->ticketQuery->count() + $this->spkQuery->count());
    }

    /**
     * @return Collection<int, Ticket|WorkOrder>
     */
    public function get(): Collection
    {
        /** @var Collection<int, Ticket> $ticketItems */
        $ticketItems = (clone $this->ticketQuery)->take(self::MAX_FETCH_ITEMS)->get();
        /** @var Collection<int, WorkOrder> $spkItems */
        $spkItems = (clone $this->spkQuery)->take(self::MAX_FETCH_ITEMS)->get();

        $combined = $ticketItems->concat($spkItems);

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
        $page = min($page, (int) ceil(self::MAX_FETCH_ITEMS / max(1, $perPage)));
        $total = $this->count();
        $maxNeeded = min($page * $perPage, self::MAX_FETCH_ITEMS);

        // Bounded fetch: bounded to maximum possible candidates for the requested page
        $ticketItems = (clone $this->ticketQuery)->take($maxNeeded)->get();
        $spkItems = (clone $this->spkQuery)->take($maxNeeded)->get();

        $combined = $ticketItems->concat($spkItems);
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
     * @param  Collection<int, Ticket|WorkOrder>  $collection
     * @return Collection<int, Ticket|WorkOrder>
     */
    private function sortCollection(Collection $collection): Collection
    {
        $sort = (string) $this->request->input('sort', 'created_at');
        $sort = in_array($sort, DispatchQueueQuery::SORTABLE, true) ? $sort : 'created_at';
        $dir = strtolower((string) $this->request->input('direction', 'desc'));
        $descending = $dir !== 'asc';

        return $collection->sort(function (Ticket|WorkOrder $a, Ticket|WorkOrder $b) use ($sort, $descending): int {
            $valA = $this->getItemSortValue($a, $sort);
            $valB = $this->getItemSortValue($b, $sort);

            $cmp = $this->compareValues($valA, $valB);
            if ($cmp !== 0) {
                return $descending ? -$cmp : $cmp;
            }

            // Tie-breaker 1: domain determinism
            $domainA = $a instanceof Ticket ? 'ticket' : 'spk';
            $domainB = $b instanceof Ticket ? 'ticket' : 'spk';
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

    private function getItemSortValue(Ticket|WorkOrder $item, string $sort): mixed
    {
        $createdAt = $item->getAttribute('created_at');
        $timestamp = $createdAt instanceof Carbon ? $createdAt->timestamp : 0;

        return match ($sort) {
            'code' => (string) $item->code,
            'title' => (string) $item->title,
            'priority' => (string) $item->priority,
            'status' => (string) $item->status,
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
