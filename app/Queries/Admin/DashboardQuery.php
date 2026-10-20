<?php

namespace App\Queries\Admin;

use App\Models\User;
use App\Services\Core\CompanyService;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Arr;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\PaymentAttempt;
use Modules\Billing\Services\PaymentAttemptService;
use Modules\Inventory\Models\MaterialReturnCase;
use Modules\Inventory\Models\Stock;
use Modules\Inventory\Services\InventoryRequestService;
use Modules\Inventory\Services\MaterialReturnService;
use Modules\Ticketing\Models\Ticket;

/**
 * Read model behind the persona dashboard: one card per queue or exception the actor may act on.
 *
 * A card exists only when the actor holds the permission (or passes the eligibility check) that
 * already guards its destination page; absent means "not for you", never zero. Counts are live and
 * scoped to the actor's company. Queue cards delegate to the queue read models and badge services
 * behind the sidebar; the ticket, invoice, online payment and low-stock cards restate their
 * destination list's predicate, and DashboardExceptionCountsTest pins each to that list. Persona
 * is the derived group order (operations, approvals, warehouse, finance), never a role name.
 */
final class DashboardQuery
{
    public const NOTIFICATION_LIMIT = 5;

    private const SLA_ITEM_LIMIT = 5;

    /** The statuses BillingService::receivables() treats as open; checkOverdue() only flips sent and partial. */
    private const OPEN_INVOICE_STATUSES = ['sent', 'partial', 'overdue'];

    /**
     * @return list<array<string, mixed>>
     */
    public static function sections(Request $request): array
    {
        $user = $request->user();
        abort_unless($user instanceof User && CompanyService::idFor($user) !== null, 403, 'Konteks perusahaan diperlukan.');

        $inventory = self::inventoryBadges($user);

        return array_values(array_filter([
            self::myWork($request, $user),
            self::dispatchUnassigned($request, $user),
            self::slaBreached($user),
            self::slaDueSoon($user),
            self::customerReplied($user),
            self::approvals($request, $user),
            self::warehouseRequests($user, $inventory['requests']),
            self::returnQueue($user, $inventory['returns']),
            self::lowStock($user),
            self::overdueInvoices($user),
            self::onlinePaymentExceptions($user),
            self::draftInvoices($user),
            self::onlinePaymentPending($user),
        ]));
    }

    /**
     * @return array{latest: Collection<int, DatabaseNotification>, unreadCount: int}
     */
    public static function notifications(User $user): array
    {
        /** @var Collection<int, DatabaseNotification> $latest */
        $latest = $user->notifications()->latest()->limit(self::NOTIFICATION_LIMIT)->get();

        return ['latest' => $latest, 'unreadCount' => $user->unreadNotifications()->count()];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function myWork(Request $request, User $user): ?array
    {
        if (! MyWorkQuery::isEligible($user)) {
            return null;
        }

        $summary = MyWorkQuery::summary($request);

        return self::section('my_work', 'operations', 'Pekerjaan saya', $summary['total'], route('admin.my-work.index'), 'info', [
            'breakdown' => Arr::only($summary, ['assigned', 'in_progress', 'waiting_action']),
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function dispatchUnassigned(Request $request, User $user): ?array
    {
        if (! DispatchQueueQuery::isEligible($user)) {
            return null;
        }

        $summary = DispatchQueueQuery::summary($request);

        return self::section('dispatch_unassigned', 'operations', 'Belum ditugaskan', $summary['unassigned'], route('admin.dispatch.index', ['status' => 'unassigned']), 'warning', [
            'breakdown' => ['tickets' => $summary['ticket_unassigned'], 'spk' => $summary['spk_unassigned']],
        ]);
    }

    /**
     * Open tickets past their SLA deadline, plus the oldest few. ticket.view alone is not enough: the
     * actor must also handle, dispatch or report on tickets.
     *
     * @return array<string, mixed>|null
     */
    private static function slaBreached(User $user): ?array
    {
        if (! self::watchesSlaQueue($user)) {
            return null;
        }

        $breached = self::breachedTickets($user);
        $count = (clone $breached)->count();
        $items = $count === 0
            ? []
            : $breached->orderBy('sla_deadline')->orderBy('id')->limit(self::SLA_ITEM_LIMIT)
                ->get(['id', 'public_id', 'code', 'title', 'priority', 'status', 'sla_deadline'])
                ->map(fn (Ticket $ticket): array => [
                    'id' => $ticket->public_id,
                    'code' => $ticket->code,
                    'title' => $ticket->title,
                    'priority' => $ticket->priority,
                    'status' => $ticket->status,
                    'sla_deadline' => $ticket->sla_deadline?->toISOString(),
                    'href' => route('admin.tickets.show', $ticket),
                ])
                ->all();

        return self::section('sla_breached', 'operations', 'SLA terlampaui', $count, route('admin.tickets.index', ['sla_breached' => 1]), 'danger', [
            'items' => $items,
        ]);
    }

    /**
     * Unfinished tickets whose response or resolution target falls due within the next half hour: the near miss
     * before the breach card. Same audience and scope as that card; the list behind it is ?due_soon=1.
     *
     * @return array<string, mixed>|null
     */
    private static function slaDueSoon(User $user): ?array
    {
        if (! self::watchesSlaQueue($user)) {
            return null;
        }

        $count = self::ticketQueue($user)->dueSoon()->count();

        return self::section('sla_due_soon', 'operations', 'Hampir terlampaui (≤ 30 menit)', $count, route('admin.tickets.index', ['due_soon' => 1]), 'warning');
    }

    /**
     * Unfinished tickets whose newest public comment is the customer's own, so nobody on staff has answered since. Same
     * audience and scope as the breach card; the list behind it is ?awaiting_reply=1.
     *
     * @return array<string, mixed>|null
     */
    private static function customerReplied(User $user): ?array
    {
        if (! self::watchesSlaQueue($user)) {
            return null;
        }

        $count = self::ticketQueue($user)->awaitingStaffReply()->count();

        return self::section('customer_replied', 'operations', 'Pelanggan membalas', $count, route('admin.tickets.index', ['awaiting_reply' => 1]), 'info');
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function approvals(Request $request, User $user): ?array
    {
        if (! ApprovalInboxQuery::isEligible($user)) {
            return null;
        }

        // Per-domain counts instead of summary(): summary() honours the request's ?domain filter, which a
        // dashboard card must ignore. Both counts keep the maker-checker rule and the per-request cache.
        $waiting = (ApprovalInboxQuery::isEligibleForSpk($user) ? ApprovalInboxQuery::spkWaitingCount($request) : 0)
            + (ApprovalInboxQuery::isEligibleForInventoryRequest($user) ? ApprovalInboxQuery::inventoryWaitingCount($request) : 0);

        return self::section('approvals', 'approvals', 'Menunggu persetujuan saya', $waiting, route('admin.approvals.index'), 'warning');
    }

    /**
     * @param  array<string, int|null>|null  $requests
     * @return array<string, mixed>|null
     */
    private static function warehouseRequests(User $user, ?array $requests): ?array
    {
        $queue = $requests['warehouse_queue'] ?? null;

        // The warehouse tab only lists the whole queue for inventory.request.view.all.
        if ($queue === null || ! $user->can('inventory.request.view.all')) {
            return null;
        }

        return self::section('warehouse_requests', 'warehouse', 'Antrean permintaan gudang', $queue, route('admin.inventory.requests.index', ['tab' => 'warehouse']), 'info');
    }

    /**
     * @param  array<string, int|null>|null  $returns
     * @return array<string, mixed>|null
     */
    private static function returnQueue(User $user, ?array $returns): ?array
    {
        $queue = $returns['warehouse_return_queue'] ?? null;

        if ($queue === null || ! $user->can('inventory.return.view.all')) {
            return null;
        }

        $exceptions = MaterialReturnCase::query()->forCompany(CompanyService::idFor($user))->where('status', 'exception_open')->count();
        // The returns tab opens on the queue the actor works: receipts first, exceptions for resolvers.
        $status = $user->can('inventory.return.receive') ? 'awaiting_receipt' : 'exception_open';

        return self::section('return_queue', 'warehouse', 'Antrean retur material', $queue, route('admin.inventory.requests.index', ['tab' => 'returns', 'return_status' => $status]), $exceptions > 0 ? 'danger' : 'warning', [
            'breakdown' => ['exceptions' => $exceptions],
        ]);
    }

    /**
     * Stock rows (product x location) the Stocks list shows for ?low_stock=1, with the list's own predicate:
     * rows of soft-deleted or inactive products stay in, as they do there.
     *
     * @return array<string, mixed>|null
     */
    private static function lowStock(User $user): ?array
    {
        if (! $user->can('inventory.view')) {
            return null;
        }

        $count = Stock::query()
            ->forCompany(CompanyService::idFor($user))
            ->whereHas('product', fn ($product) => $product->withoutGlobalScope(SoftDeletingScope::class)->whereColumn('stocks.quantity', '<=', 'products.min_stock'))
            ->count();

        return self::section('low_stock', 'warehouse', 'Stok di bawah minimum', $count, route('admin.stocks.index', ['low_stock' => 1]), 'warning');
    }

    /**
     * Open invoices past their due date with a balance left, computed from the dates and amounts so the
     * count does not wait for the billing:check-overdue scheduler to flip the status.
     *
     * @return array<string, mixed>|null
     */
    private static function overdueInvoices(User $user): ?array
    {
        if (! $user->can('billing.view')) {
            return null;
        }

        $overdue = Invoice::query()
            ->forCompany(CompanyService::idFor($user))
            ->whereIn('status', self::OPEN_INVOICE_STATUSES)
            ->where('due_date', '<', now()->toDateString())
            ->whereColumn('paid_amount', '<', 'total');

        $count = (clone $overdue)->count();
        $outstanding = $count === 0
            ? '0.00'
            : Money::round(Money::sub((clone $overdue)->sum('total'), $overdue->sum('paid_amount')));

        return self::section('overdue_invoices', 'finance', 'Tagihan jatuh tempo', $count, route('admin.invoices.index', ['status' => 'overdue']), 'danger', [
            'outstanding' => $outstanding,
        ]);
    }

    /**
     * Invoices still in draft: what the billing staff owe a send (K17). The count is what the invoice list shows for
     * ?status=draft, so the card needs billing.send to act on it and billing.view to open that list.
     *
     * @return array<string, mixed>|null
     */
    private static function draftInvoices(User $user): ?array
    {
        if (! $user->can('billing.send') || ! $user->can('billing.view')) {
            return null;
        }

        $drafts = Invoice::query()->forCompany(CompanyService::idFor($user))->where('status', 'draft');
        $count = (clone $drafts)->count();
        $total = $count === 0 ? '0.00' : Money::round($drafts->sum('total'));

        return self::section('draft_invoices', 'finance', 'Tagihan draf menunggu dikirim', $count, route('admin.invoices.index', ['status' => 'draft']), 'warning', [
            'total' => $total,
        ]);
    }

    /**
     * Online payments only a person can settle: money Midtrans took that Inbils could not book, or booked money it took
     * back. The count is the Tunggakan page's own (PaymentAttemptService::openExceptions); the actor needs
     * billing.payment.record, the permission the exception bell goes by, and billing.view to open that page.
     *
     * @return array<string, mixed>|null
     */
    private static function onlinePaymentExceptions(User $user): ?array
    {
        if (! self::settlesOnlinePayments($user)) {
            return null;
        }

        $count = PaymentAttemptService::openExceptions()->forCompany(CompanyService::idFor($user))->count();

        return self::section('online_payment_exceptions', 'finance', 'Pembayaran online bermasalah', $count, route('admin.billing.receivables'), 'danger');
    }

    /**
     * Invoices with an online payment page opened more than an hour ago that is still waiting: the customer may have
     * given up, or still be paying. One count per invoice, as the invoice list shows for ?online_pending=1.
     *
     * @return array<string, mixed>|null
     */
    private static function onlinePaymentPending(User $user): ?array
    {
        if (! self::settlesOnlinePayments($user)) {
            return null;
        }

        $companyId = CompanyService::idFor($user);
        $count = Invoice::query()
            ->forCompany($companyId)
            ->whereIn('invoices.id', PaymentAttempt::query()->stalePending()->forCompany($companyId)->select('invoice_id'))
            ->count();

        return self::section('online_payment_pending', 'finance', 'Pembayaran online tertunda > 1 jam', $count, route('admin.invoices.index', ['online_pending' => 1]), 'warning');
    }

    /**
     * The inventory badge services, called once and only for an actor who can open the requests page,
     * because the returns list is a tab of that page.
     *
     * @return array{requests: array<string, int|null>|null, returns: array<string, int|null>|null}
     */
    private static function inventoryBadges(User $user): array
    {
        if (! $user->can('inventory.request.view.own') && ! $user->can('inventory.request.view.all')) {
            return ['requests' => null, 'returns' => null];
        }

        $viewsReturns = $user->can('inventory.return.view.own') || $user->can('inventory.return.view.all');

        return [
            'requests' => InventoryRequestService::getBadgeCounts($user),
            'returns' => $viewsReturns ? MaterialReturnService::getBadgeCounts($user) : null,
        ];
    }

    /**
     * Tickets scoped like the Tickets list: a handler without ticket.assign only lists tickets assigned to
     * them, and nothing while inactive (TicketController::filteredQuery).
     *
     * @return Builder<Ticket>
     */
    private static function ticketQueue(User $user): Builder
    {
        $query = Ticket::query()->forCompany(CompanyService::idFor($user));

        if (self::handlesTickets($user) && ! $user->can('ticket.assign')) {
            if ($user->is_active) {
                $query->where('assigned_to', $user->id);
            } else {
                $query->whereIn('id', []);
            }
        }

        return $query;
    }

    /** @return Builder<Ticket> */
    private static function breachedTickets(User $user): Builder
    {
        return self::ticketQueue($user)
            ->where('sla_deadline', '<', now())
            ->whereNotIn('status', ['resolved', 'closed']);
    }

    private static function handlesTickets(User $user): bool
    {
        return $user->can('ticket.start') || $user->can('ticket.resolve') || $user->can('ticket.close');
    }

    private static function watchesSla(User $user): bool
    {
        return $user->can('ticket.assign') || $user->can('ticket.reassign') || self::handlesTickets($user) || $user->can('report.sla.view');
    }

    /** The three ticket queue cards share one audience: ticket.view alone is not enough, as for the breach card. */
    private static function watchesSlaQueue(User $user): bool
    {
        return $user->can('ticket.view') && self::watchesSla($user);
    }

    /** Online payment cards: billing.view opens the pages, billing.payment.record is who settles what they list. */
    private static function settlesOnlinePayments(User $user): bool
    {
        return $user->can('billing.payment.record') && $user->can('billing.view');
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private static function section(string $key, string $group, string $title, int $count, string $href, string $severity, array $extra = []): array
    {
        return [
            'key' => $key,
            'group' => $group,
            'title' => $title,
            'count' => $count,
            'href' => $href,
            'severity' => $count === 0 ? 'neutral' : $severity,
            ...$extra,
        ];
    }
}
