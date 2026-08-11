<?php

namespace App\Queries\Admin;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class UserHistoryQuery
{
    private const LIMIT = 50;

    /**
     * @param  array{billing: bool, tickets: bool, spk: bool}  $access
     * @return array{linked_customer: array<string, mixed>|null, invoices: array<int, array<string, mixed>>, tickets: array<int, array<string, mixed>>, work_orders: array<int, array<string, mixed>>}
     */
    public static function execute(User $user, array $access): array
    {
        $customers = DB::table('customers')
            ->where('company_id', $user->company_id)
            ->where('user_id', $user->id)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get(['id', 'code', 'name']);
        $customerIds = $customers->pluck('id');

        // ponytail: cap each tab at 50 rows; add tab-specific server pagination when a user routinely exceeds it.
        $invoices = $access['billing']
            ? DB::table('invoices')
                ->where('company_id', $user->company_id)
                ->whereNull('deleted_at')
                ->where(function ($query) use ($customerIds, $user): void {
                    $query->where('created_by', $user->id);
                    if ($customerIds->isNotEmpty()) {
                        $query->orWhereIn('customer_id', $customerIds);
                    }
                })
                ->latest('created_at')
                ->limit(self::LIMIT)
                ->get(['id', 'number', 'status', 'issue_date', 'due_date', 'total', 'paid_amount', 'created_at'])
                ->map(fn (object $row): array => (array) $row)
                ->all()
            : [];

        $tickets = $access['tickets']
            ? DB::table('tickets')
                ->where('company_id', $user->company_id)
                ->whereNull('deleted_at')
                ->where(function ($query) use ($customerIds, $user): void {
                    $query->where('created_by', $user->id)
                        ->orWhere('assigned_to', $user->id);
                    if ($customerIds->isNotEmpty()) {
                        $query->orWhereIn('customer_id', $customerIds);
                    }
                })
                ->latest('created_at')
                ->limit(self::LIMIT)
                ->get(['id', 'code', 'title', 'priority', 'status', 'resolved_at', 'created_at'])
                ->map(fn (object $row): array => (array) $row)
                ->all()
            : [];

        $workOrders = $access['spk']
            ? DB::table('work_orders')
                ->where('company_id', $user->company_id)
                ->whereNull('deleted_at')
                ->where(function ($query) use ($customerIds, $user): void {
                    $query->where('created_by', $user->id)
                        ->orWhere('assigned_to', $user->id);
                    if ($customerIds->isNotEmpty()) {
                        $query->orWhereIn('customer_id', $customerIds);
                    }
                })
                ->latest('created_at')
                ->limit(self::LIMIT)
                ->get(['id', 'code', 'title', 'type', 'priority', 'status', 'scheduled_date', 'completed_at', 'created_at'])
                ->map(fn (object $row): array => (array) $row)
                ->all()
            : [];

        $linkedCustomer = $customers->first();

        return [
            'linked_customer' => $linkedCustomer ? (array) $linkedCustomer : null,
            'invoices' => $invoices,
            'tickets' => $tickets,
            'work_orders' => $workOrders,
        ];
    }
}
