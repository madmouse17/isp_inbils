<?php

namespace App\Queries\Admin;

use App\Models\Core\Customer;
use Illuminate\Support\Facades\DB;

class CustomerHistoryQuery
{
    private const LIMIT = 50;

    /**
     * @param  array{billing: bool, tickets: bool, spk: bool}  $access
     * @return array{invoices: array<int, array<string, mixed>>, tickets: array<int, array<string, mixed>>, work_orders: array<int, array<string, mixed>>}
     */
    public static function execute(Customer $customer, array $access): array
    {
        return [
            'invoices' => $access['billing'] ? DB::table('invoices')
                ->where('company_id', $customer->company_id)
                ->where('customer_id', $customer->id)
                ->whereNull('deleted_at')
                ->latest('issue_date')
                ->limit(self::LIMIT)
                ->get(['id', 'number', 'status', 'issue_date', 'due_date', 'total', 'paid_amount'])
                ->map(fn (object $row): array => (array) $row)->all() : [],
            'tickets' => $access['tickets'] ? DB::table('tickets')
                ->where('company_id', $customer->company_id)
                ->where('customer_id', $customer->id)
                ->whereNull('deleted_at')
                ->latest('created_at')
                ->limit(self::LIMIT)
                ->get(['id', 'code', 'title', 'priority', 'status', 'created_at'])
                ->map(fn (object $row): array => (array) $row)->all() : [],
            'work_orders' => $access['spk'] ? DB::table('work_orders')
                ->where('company_id', $customer->company_id)
                ->where('customer_id', $customer->id)
                ->whereNull('deleted_at')
                ->latest('created_at')
                ->limit(self::LIMIT)
                ->get(['id', 'code', 'title', 'type', 'priority', 'status', 'scheduled_date'])
                ->map(fn (object $row): array => (array) $row)->all() : [],
        ];
    }
}
