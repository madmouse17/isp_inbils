<?php

namespace App\Queries\Admin;

use App\Models\Core\EmployeeProfile;
use App\Services\Core\CompanyService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Modules\SPK\Models\WorkOrder;
use Modules\Ticketing\Models\Ticket;

class MyWorkQuery
{
    public const SORTABLE = ['created_at', 'code', 'title', 'priority', 'status'];

    public const PER_PAGE_OPTIONS = [10, 25, 50];

    public static function isEligible(?object $user): bool
    {
        return self::isEligibleForTicket($user) || self::isEligibleForSpk($user);
    }

    public static function isEligibleForTicket(?object $user): bool
    {
        return $user !== null
            && ($user->can('ticket.start') || $user->can('ticket.resolve') || $user->can('ticket.close'))
            && self::hasActiveCompanyEmployeeProfile($user);
    }

    public static function isEligibleForSpk(?object $user): bool
    {
        return $user !== null
            && ($user->can('spk.start') || $user->can('spk.submit'))
            && self::hasActiveCompanyEmployeeProfile($user);
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
            ->where('assigned_to', $user->id)
            ->where(function (Builder $query) use ($user) {
                if ($user->can('ticket.start')) {
                    $query->orWhere('status', 'assigned');
                }
                if ($user->can('ticket.resolve')) {
                    $query->orWhere('status', 'on_progress');
                }
                if ($user->can('ticket.close')) {
                    $query->orWhere('status', 'resolved');
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
            ->where('assigned_to', $user->id)
            ->where(function (Builder $query) use ($user) {
                if ($user->can('spk.start')) {
                    $query->orWhere('status', 'assigned');
                }
                if ($user->can('spk.submit')) {
                    $query->orWhere('status', 'in_progress');
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
        if ($status === 'in_progress') {
            $query->where('status', 'on_progress');
        } elseif ($status === 'assigned') {
            $query->where('status', 'assigned');
        } elseif ($status === 'resolved') {
            self::applyWaitingActionFilter($query, 'ticket');
        } elseif ($status !== null && $status !== '' && $status !== 'all') {
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
        if ($status === 'in_progress') {
            $query->where('status', 'in_progress');
        } elseif ($status === 'assigned') {
            $query->where('status', 'assigned');
        } elseif ($status === 'resolved') {
            self::applyWaitingActionFilter($query, 'spk');
        } elseif ($status !== null && $status !== '' && $status !== 'all') {
            $query->where('status', $status);
        }

        $sort = (string) $request->input('sort', 'created_at');
        $sort = in_array($sort, self::SORTABLE, true) ? $sort : 'created_at';
        $direction = strtolower((string) $request->input('direction', 'desc')) === 'asc' ? 'asc' : 'desc';

        return $query->orderBy($sort, $direction)->orderBy('id', 'desc');
    }

    /**
     * Filtered query used by the my-work queue list and tests.
     * Mengembalikan Builder<Ticket>, Builder<WorkOrder>, atau MyWorkUnifiedQuery.
     *
     * @return Builder<Ticket>|Builder<WorkOrder>|MyWorkUnifiedQuery
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

        return new MyWorkUnifiedQuery(
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
     * Badge count for tasks assigned to current actor.
     * Returns null if user is not eligible for operational work.
     */
    public static function badgeCount(Request $request): ?int
    {
        $user = $request->user();
        if (! self::isEligible($user)) {
            return null;
        }

        $ticketCount = self::isEligibleForTicket($user)
            ? self::ticketBase($request, false)->count()
            : 0;

        $spkCount = self::isEligibleForSpk($user)
            ? self::spkBase($request, false)->count()
            : 0;

        return $ticketCount + $spkCount;
    }

    /**
     * Summary counters scoped to company + current actor eligibility.
     *
     * @return array{total: int, assigned: int, in_progress: int, waiting_action: int}
     */
    public static function summary(Request $request): array
    {
        $user = $request->user();
        $ticketEligible = self::isEligibleForTicket($user);
        $spkEligible = self::isEligibleForSpk($user);

        $ticketAssigned = $ticketEligible ? self::ticketBase($request, false)->where('status', 'assigned')->count() : 0;
        $ticketInProgress = $ticketEligible ? self::ticketBase($request, false)->where('status', 'on_progress')->count() : 0;
        $ticketResolved = $ticketEligible
            ? self::applyWaitingActionFilter(self::ticketBase($request, false), 'ticket')->count()
            : 0;

        $spkAssigned = $spkEligible ? self::spkBase($request, false)->where('status', 'assigned')->count() : 0;
        $spkInProgress = $spkEligible
            ? self::applyWaitingActionFilter(self::spkBase($request, false), 'spk')->count()
            : 0;

        $total = ($ticketAssigned + $ticketInProgress + $ticketResolved) + ($spkAssigned + $spkInProgress);

        return [
            'total' => $total,
            'assigned' => $ticketAssigned + $spkAssigned,
            'in_progress' => $ticketInProgress + $spkInProgress,
            'waiting_action' => $ticketResolved + $spkInProgress,
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

    private static function applyWaitingActionFilter(Builder $query, string $domain): Builder
    {
        return $query->where('status', $domain === 'ticket' ? 'resolved' : 'in_progress');
    }

    private static function hasActiveCompanyEmployeeProfile(?object $user): bool
    {
        $companyId = $user === null ? null : CompanyService::idFor($user);

        // Explicit company and user: the answer must not depend on who is signed in.
        return $user !== null
            && $user->is_active
            && $companyId !== null
            && EmployeeProfile::withoutCompany()
                ->where('user_id', $user->id)
                ->where('company_id', $companyId)
                ->where('status', 'active')
                ->exists();
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
