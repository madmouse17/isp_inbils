<?php

namespace App\Queries\Admin;

use App\Models\User;
use App\Services\Core\CompanyService;
use App\Support\Notifications\StaffRecipients;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Modules\SPK\Models\WorkOrder;
use Modules\Ticketing\Models\Ticket;
use Modules\Ticketing\Services\TicketService;

class DispatchQueueQuery
{
    public const SORTABLE = ['created_at', 'code', 'title', 'priority', 'status'];

    public const PER_PAGE_OPTIONS = [10, 25, 50];

    /** What counts as work a person holds now (the workload of the pickers): a ticket not yet resolved, an SPK not yet done. */
    private const ACTIVE_TICKET_STATUSES = ['assigned', 'on_progress'];

    private const ACTIVE_SPK_STATUSES = ['assigned', 'in_progress'];

    public static function isEligible(?object $user): bool
    {
        return self::isEligibleForTicket($user) || self::isEligibleForSpk($user);
    }

    public static function isEligibleForTicket(?object $user): bool
    {
        return $user !== null
            && $user->is_active
            && ($user->can('ticket.assign') || $user->can('ticket.reassign'));
    }

    public static function canAssignTicket(?object $user): bool
    {
        return $user !== null && $user->can('ticket.assign');
    }

    public static function canReassignTicket(?object $user): bool
    {
        return $user !== null && $user->can('ticket.reassign');
    }

    public static function isEligibleForSpk(?object $user): bool
    {
        return $user !== null
            && $user->is_active
            && ($user->can('spk.assign') || $user->can('spk.reassign'));
    }

    public static function canAssignSpk(?object $user): bool
    {
        return $user !== null && $user->can('spk.assign');
    }

    public static function canReassignSpk(?object $user): bool
    {
        return $user !== null && $user->can('spk.reassign');
    }

    /**
     * @return Builder<Ticket>
     */
    public static function ticketBase(Request $request, bool $withRelations = true): Builder
    {
        $user = $request->user();
        if (! self::isEligibleForTicket($user)) {
            return Ticket::query()->whereRaw('1 = 0');
        }

        $query = Ticket::forCompany(CompanyService::idFor($user))
            ->whereIn('status', ['open', 'assigned']);

        $query->where(function (Builder $query) use ($user) {
            if ($user->can('ticket.assign')) {
                $query->orWhereNull('assigned_to');
            }
            if ($user->can('ticket.reassign')) {
                $query->orWhereNotNull('assigned_to');
            }
        });

        if ($withRelations) {
            $query->with([
                'customer:id,public_id,code,name',
                'assignee:id,public_id,name',
                'category:id,public_id,name',
            ]);
        }

        return $query;
    }

    /**
     * @return Builder<WorkOrder>
     */
    public static function spkBase(Request $request, bool $withRelations = true): Builder
    {
        $user = $request->user();
        if (! self::isEligibleForSpk($user)) {
            return WorkOrder::query()->whereRaw('1 = 0');
        }

        $query = WorkOrder::forCompany(CompanyService::idFor($user))
            ->whereIn('status', ['generated', 'draft', 'rejected', 'assigned'])
            ->where(function (Builder $query) use ($user) {
                if ($user->can('spk.assign')) {
                    $query->orWhereNull('assigned_to');
                }
                if ($user->can('spk.reassign')) {
                    $query->orWhereNotNull('assigned_to');
                }
            });

        if ($withRelations) {
            $query->with([
                'customer:id,public_id,code,name',
                'assignee:id,public_id,name',
                'creator:id,public_id,name',
                'items.product:id,public_id,name',
            ]);
        }

        return $query;
    }

    /**
     * @return Builder<Ticket>
     */
    public static function ticketFiltered(Request $request): Builder
    {
        $query = self::ticketBase($request, true);
        self::applyCommonFilters($query, $request);

        $status = $request->input('status');
        if ($status === null || $status === '') {
            if (self::canAssignTicket($request->user()) && ! self::canReassignTicket($request->user())) {
                $query->whereNull('assigned_to');
            } elseif (! self::canAssignTicket($request->user()) && self::canReassignTicket($request->user())) {
                $query->whereNotNull('assigned_to');
            }
        } elseif ($status === 'unassigned') {
            $query->whereNull('assigned_to');
        } elseif ($status === 'assigned') {
            $query->whereNotNull('assigned_to');
        } elseif ($status !== 'all') {
            $query->where('status', $status);
        }

        $sort = (string) $request->input('sort', 'created_at');
        $sort = in_array($sort, self::SORTABLE, true) ? $sort : 'created_at';
        $direction = strtolower((string) $request->input('direction', 'desc')) === 'asc' ? 'asc' : 'desc';

        return $query->orderBy($sort, $direction)->orderBy('id', 'desc');
    }

    /**
     * @return Builder<WorkOrder>
     */
    public static function spkFiltered(Request $request): Builder
    {
        $query = self::spkBase($request, true);
        self::applyCommonFilters($query, $request);

        $status = $request->input('status');
        if ($status === null || $status === '') {
            if (self::canAssignSpk($request->user()) && ! self::canReassignSpk($request->user())) {
                $query->whereNull('assigned_to');
            } elseif (! self::canAssignSpk($request->user()) && self::canReassignSpk($request->user())) {
                $query->whereNotNull('assigned_to');
            }
        } elseif ($status === 'unassigned') {
            $query->whereNull('assigned_to');
        } elseif ($status === 'assigned') {
            $query->whereNotNull('assigned_to');
        } elseif ($status !== 'all') {
            $query->where('status', $status);
        }

        $sort = (string) $request->input('sort', 'created_at');
        $sort = in_array($sort, self::SORTABLE, true) ? $sort : 'created_at';
        $direction = strtolower((string) $request->input('direction', 'desc')) === 'asc' ? 'asc' : 'desc';

        return $query->orderBy($sort, $direction)->orderBy('id', 'desc');
    }

    /**
     * Filtered query used by the dispatch queue list and tests.
     * Mengembalikan Builder<Ticket>, Builder<WorkOrder>, atau DispatchQueueUnifiedQuery.
     *
     * @return Builder<Ticket>|Builder<WorkOrder>|DispatchQueueUnifiedQuery
     */
    public static function filtered(Request $request): mixed
    {
        $user = $request->user();
        if (! self::isEligible($user)) {
            return Ticket::query()->whereRaw('1 = 0');
        }

        $domain = $request->input('domain');

        $ticketAllowed = ($domain === null || $domain === '' || $domain === 'ticket')
            && self::isEligibleForTicket($user);

        $spkAllowed = ($domain === null || $domain === '' || $domain === 'spk')
            && self::isEligibleForSpk($user);

        if (! $ticketAllowed && ! $spkAllowed) {
            return Ticket::query()->whereRaw('1 = 0');
        }

        if ($ticketAllowed && ! $spkAllowed) {
            return self::ticketFiltered($request);
        }

        if (! $ticketAllowed) {
            return self::spkFiltered($request);
        }

        return new DispatchQueueUnifiedQuery(
            self::ticketFiltered($request),
            self::spkFiltered($request),
            $request
        );
    }

    public static function perPage(Request $request): int
    {
        $perPage = (int) $request->input('per_page', 10);

        return in_array($perPage, self::PER_PAGE_OPTIONS, true) ? $perPage : 10;
    }

    /**
     * Badge count for unassigned dispatch tasks.
     * Returns null if user is not eligible for dispatch.
     */
    public static function badgeCount(Request $request): ?int
    {
        $user = $request->user();
        if (! self::isEligible($user)) {
            return null;
        }

        $ticketCount = self::canAssignTicket($user)
            ? self::ticketBase($request, false)->whereNull('assigned_to')->count()
            : 0;

        $spkCount = self::canAssignSpk($user)
            ? self::spkBase($request, false)->whereNull('assigned_to')->count()
            : 0;

        return $ticketCount + $spkCount;
    }

    /**
     * Summary counters scoped to company + eligibility.
     *
     * @return array{unassigned: int, ticket_unassigned: int, spk_unassigned: int, assigned: int}
     */
    public static function summary(Request $request): array
    {
        $user = $request->user();

        $ticketUnassigned = self::canAssignTicket($user)
            ? self::ticketBase($request, false)->whereNull('assigned_to')->count()
            : 0;

        $spkUnassigned = self::canAssignSpk($user)
            ? self::spkBase($request, false)->whereNull('assigned_to')->count()
            : 0;

        $ticketAssigned = self::canReassignTicket($user)
            ? self::ticketBase($request, false)->whereNotNull('assigned_to')->count()
            : 0;

        $spkAssigned = self::canReassignSpk($user)
            ? self::spkBase($request, false)->whereNotNull('assigned_to')->count()
            : 0;

        return [
            'unassigned' => $ticketUnassigned + $spkUnassigned,
            'ticket_unassigned' => $ticketUnassigned,
            'spk_unassigned' => $spkUnassigned,
            'assigned' => $ticketAssigned + $spkAssigned,
        ];
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function domainOptions(Request $request): array
    {
        $user = $request->user();
        $options = [];

        if (self::isEligibleForTicket($user)) {
            $options[] = ['value' => 'ticket', 'label' => 'Tiket'];
        }

        if (self::isEligibleForSpk($user)) {
            $options[] = ['value' => 'spk', 'label' => 'SPK'];
        }

        return $options;
    }

    /**
     * Who a ticket can be given to: the active members of the company who hold a permission that works a ticket, each with the
     * work they hold now, for the picker to show and to suggest by. No email: a name and a workload are what the choice needs.
     *
     * @return array<int, array{id: string, name: string, load: int}>
     */
    public static function eligibleHandlers(Request $request): array
    {
        $user = $request->user();
        if (! self::canAssignTicket($user) && ! self::canReassignTicket($user)) {
            return [];
        }

        $companyId = CompanyService::idFor($user);
        if ($companyId === null) {
            return [];
        }

        return self::withLoad(
            StaffRecipients::members($companyId)
                ->withPermissionIn(TicketService::HANDLER_PERMISSIONS, $companyId)
                ->orderBy('users.name')
                ->get(['users.id', 'users.public_id', 'users.name']),
            $companyId,
        );
    }

    /**
     * Who an SPK can be given to: active technicians of the company, with the work each holds now.
     *
     * @return array<int, array{id: string, name: string, load: int}>
     */
    public static function eligibleTechnicians(Request $request): array
    {
        $user = $request->user();
        if (! self::canAssignSpk($user) && ! self::canReassignSpk($user)) {
            return [];
        }

        $companyId = CompanyService::idFor($user);
        if ($companyId === null) {
            return [];
        }

        return self::withLoad(
            User::query()
                ->where('company_id', $companyId)
                ->where('is_active', true)
                ->whereHas('employeeProfile', fn (Builder $profileQuery) => $profileQuery
                    ->where('company_id', $companyId)
                    ->where('status', 'active'))
                ->withPermissionIn(['spk.start', 'spk.submit'], $companyId)
                ->orderBy('name')
                ->get(['id', 'public_id', 'name']),
            $companyId,
        );
    }

    /**
     * The work each person holds now: the tickets assigned to them that are not resolved plus the SPKs assigned to them that are
     * not done. One grouped query for each kind whatever the number of people, on the `(company_id, assigned_to)` indexes.
     *
     * @param  EloquentCollection<int, User>  $people
     * @return array<int, array{id: string, name: string, load: int}>
     */
    private static function withLoad(EloquentCollection $people, int $companyId): array
    {
        $ids = $people->modelKeys();

        if ($ids === []) {
            return [];
        }

        $count = static fn (Builder $query, array $statuses) => $query
            ->whereIn('assigned_to', $ids)
            ->whereIn('status', $statuses)
            ->selectRaw('assigned_to, count(*) as total')
            ->groupBy('assigned_to')
            ->pluck('total', 'assigned_to');

        $tickets = $count(Ticket::forCompany($companyId), self::ACTIVE_TICKET_STATUSES);
        $spks = $count(WorkOrder::forCompany($companyId), self::ACTIVE_SPK_STATUSES);

        return $people
            ->map(fn (User $person): array => [
                'id' => (string) $person->public_id,
                'name' => $person->name,
                'load' => (int) ($tickets[$person->id] ?? 0) + (int) ($spks[$person->id] ?? 0),
            ])
            ->all();
    }

    private static function applyCommonFilters(Builder $query, Request $request): void
    {
        $search = trim((string) $request->input('search', ''));
        if ($search !== '') {
            $query->where(function (Builder $q) use ($search) {
                $q->where('code', 'like', "%{$search}%")
                    ->orWhere('title', 'like', "%{$search}%")
                    ->orWhereHas('customer', function (Builder $cq) use ($search) {
                        $cq->where('name', 'like', "%{$search}%")
                            ->orWhere('code', 'like', "%{$search}%");
                    });
            });
        }

        if ($request->filled('priority')) {
            $query->where('priority', $request->input('priority'));
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->input('date_to'));
        }
    }
}
