<?php

use App\Support\AddressLine;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    private const CHUNK = 500;

    private const COLUMNS = [
        'customer_name_snapshot',
        'customer_address_snapshot',
        'customer_tax_id_snapshot',
        'package_name_snapshot',
    ];

    /**
     * Invoices keep the customer and package as they were when the invoice left draft (spec master-lifecycle D4).
     * Existing non-draft invoices get today's live values once; rows that already have a snapshot are never touched,
     * so a run that stopped half way is repaired by running it again. Raw queries only: no company scope, and
     * trashed customers, addresses and packages still count.
     */
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (! Schema::hasColumn('invoices', 'customer_name_snapshot')) {
                $table->string('customer_name_snapshot')->nullable();
            }

            if (! Schema::hasColumn('invoices', 'customer_address_snapshot')) {
                $table->text('customer_address_snapshot')->nullable();
            }

            if (! Schema::hasColumn('invoices', 'customer_tax_id_snapshot')) {
                $table->string('customer_tax_id_snapshot')->nullable();
            }

            if (! Schema::hasColumn('invoices', 'package_name_snapshot')) {
                $table->string('package_name_snapshot')->nullable();
            }
        });

        DB::table('invoices')
            ->where('status', '<>', 'draft')
            ->whereNull('customer_name_snapshot')
            ->orderBy('id')
            ->chunkById(self::CHUNK, fn ($invoices) => $this->backfill($invoices->all()));
    }

    public function down(): void
    {
        $present = array_values(array_filter(self::COLUMNS, fn (string $column) => Schema::hasColumn('invoices', $column)));

        if ($present !== []) {
            Schema::table('invoices', fn (Blueprint $table) => $table->dropColumn($present));
        }
    }

    /** @param list<object> $invoices */
    private function backfill(array $invoices): void
    {
        $customers = DB::table('customers')->whereIn('id', array_column($invoices, 'customer_id'))->get(['id', 'name', 'tax_id'])->keyBy('id');
        $subscriptions = DB::table('service_subscriptions')
            ->whereIn('id', array_filter(array_column($invoices, 'subscription_id')))
            ->get(['id', 'installation_address_id', 'service_package_id'])->keyBy('id');
        $packages = DB::table('service_packages')
            ->whereIn('id', $subscriptions->pluck('service_package_id')->filter()->unique())
            ->pluck('name', 'id');
        $addresses = $this->addresses($customers->keys()->all(), $subscriptions->pluck('installation_address_id')->filter()->unique()->all());

        DB::transaction(function () use ($invoices, $customers, $subscriptions, $packages, $addresses): void {
            foreach ($invoices as $invoice) {
                $customer = $customers[$invoice->customer_id];
                $subscription = $subscriptions[$invoice->subscription_id] ?? null;
                $address = ($subscription?->installation_address_id ? $addresses['byId'][$subscription->installation_address_id] ?? null : null)
                    ?? $addresses['primary'][$invoice->customer_id] ?? null;

                DB::table('invoices')->where('id', $invoice->id)->whereNull('customer_name_snapshot')->update([
                    'customer_name_snapshot' => $customer->name,
                    'customer_address_snapshot' => AddressLine::of($address),
                    'customer_tax_id_snapshot' => $customer->tax_id,
                    'package_name_snapshot' => $subscription ? $packages[$subscription->service_package_id] ?? null : null,
                ]);
            }
        });
    }

    /**
     * @param  list<int>  $customerIds
     * @param  list<int>  $addressIds
     * @return array{byId: array<int, object>, primary: array<int, object>}
     */
    private function addresses(array $customerIds, array $addressIds): array
    {
        $rows = DB::table('customer_addresses as a')
            ->leftJoin('indonesia_villages as v', 'v.code', '=', 'a.village_code')
            ->leftJoin('indonesia_districts as d', 'd.code', '=', 'a.district_code')
            ->leftJoin('indonesia_cities as c', 'c.code', '=', 'a.city_code')
            ->leftJoin('indonesia_provinces as p', 'p.code', '=', 'a.province_code')
            ->where(fn ($query) => $query->whereIn('a.id', $addressIds)->orWhere(fn ($primary) => $primary->whereIn('a.customer_id', $customerIds)->where('a.is_primary', true)))
            ->orderByRaw('a.deleted_at IS NOT NULL')
            ->orderBy('a.id')
            ->get(['a.id', 'a.customer_id', 'a.address', 'a.city', 'a.postal_code', 'a.is_primary', 'v.name as village_name', 'd.name as district_name', 'c.name as city_name', 'p.name as province_name']);

        $byId = $primary = [];

        foreach ($rows as $row) {
            $byId[$row->id] = $row;

            if ($row->is_primary) {
                $primary[$row->customer_id] ??= $row;
            }
        }

        return ['byId' => $byId, 'primary' => $primary];
    }
};
