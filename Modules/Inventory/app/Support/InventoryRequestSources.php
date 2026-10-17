<?php

namespace Modules\Inventory\Support;

use App\Models\User;
use App\Support\SearchOptions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\OptionalProp;
use Modules\SPK\Models\WorkOrder;
use Modules\Ticketing\Models\Ticket;

/**
 * The SPK and tickets a request for goods may name as its source. The pickers of the form (`workOrderOptions`,
 * `ticketOptions`) and the check of the submitted id read the same query, so what is not offered is not accepted.
 * A person gets the records the SPK and ticket lists would show them: nothing without the view permission, and only
 * their own assignments for a technician or handler who cannot assign. The same rule is written in
 * `WorkOrderController` and `TicketController`; a `visibleTo()` scope on both models would let the three share it.
 */
final class InventoryRequestSources
{
    /** @return Builder<WorkOrder> */
    public static function workOrderBase(User $user): Builder
    {
        $base = WorkOrder::query();

        if (! $user->can('spk.view')) {
            return $base->whereKey([]);
        }

        if (($user->can('spk.start') || $user->can('spk.submit')) && ! $user->can('spk.assign')) {
            $base->where('assigned_to', $user->id);
        }

        return $base;
    }

    /** @return Builder<Ticket> */
    public static function ticketBase(User $user): Builder
    {
        $base = Ticket::query();

        if (! $user->can('ticket.view')) {
            return $base->whereKey([]);
        }

        if (($user->can('ticket.start') || $user->can('ticket.resolve') || $user->can('ticket.close')) && ! $user->can('ticket.assign')) {
            $base->where('assigned_to', $user->id);
        }

        return $base;
    }

    public static function workOrders(Request $request): OptionalProp
    {
        $user = $request->user();

        if ($user === null) {
            return self::none();
        }

        return SearchOptions::for(
            $request,
            'workOrder',
            self::workOrderBase($user)->with('customer:id,name')->latest('id'),
            ['code', 'title'],
            fn (WorkOrder $workOrder): array => [
                'value' => $workOrder->public_id,
                'label' => $workOrder->code.' - '.$workOrder->title,
                'description' => $workOrder->customer?->name,
            ],
        );
    }

    public static function tickets(Request $request): OptionalProp
    {
        $user = $request->user();

        if ($user === null) {
            return self::none();
        }

        return SearchOptions::for(
            $request,
            'ticket',
            self::ticketBase($user)->with('customer:id,name')->latest('id'),
            ['code', 'title'],
            fn (Ticket $ticket): array => [
                'value' => $ticket->public_id,
                'label' => $ticket->code.' - '.$ticket->title,
                'description' => $ticket->customer?->name,
            ],
        );
    }

    private static function none(): OptionalProp
    {
        return Inertia::optional(fn (): array => []);
    }
}
