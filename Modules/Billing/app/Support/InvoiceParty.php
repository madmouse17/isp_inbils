<?php

namespace Modules\Billing\Support;

use App\Models\Core\CustomerAddress;
use App\Models\Core\ServiceSubscription;
use App\Services\Core\CompanyService;
use App\Support\AddressLine;
use Modules\Billing\Models\Invoice;

/**
 * Who an invoice is addressed to and which package it bills, as it prints. A draft shows the live customer, address and
 * package. When the invoice leaves draft, freeze() copies them into its snapshot columns; every reader goes through of(),
 * so a later rename or move never changes an issued invoice (spec master-lifecycle D4).
 *
 * @phpstan-type Party array{customer_name: string|null, customer_address: string|null, customer_tax_id: string|null, package_name: string|null}
 */
final class InvoiceParty
{
    /** Party key => the invoice column that keeps it. */
    private const COLUMNS = [
        'customer_name' => 'customer_name_snapshot',
        'customer_address' => 'customer_address_snapshot',
        'customer_tax_id' => 'customer_tax_id_snapshot',
        'package_name' => 'package_name_snapshot',
    ];

    /**
     * The party read from the live records: the subscription's installation address, else the customer's primary one.
     * A relation the caller loaded is used as it is, so a batch that eager-loads them pays no query per invoice.
     *
     * @return Party
     */
    public static function live(Invoice $invoice): array
    {
        // The invoice's own company, not the caller's: a console or queue caller has none, and the tenant scope fails closed.
        return CompanyService::runAs((int) $invoice->company_id, function () use ($invoice): array {
            $customer = $invoice->customer;
            /** @var ServiceSubscription|null $subscription */
            $subscription = $invoice->subscription;
            /** @var CustomerAddress|null $address */
            $address = $subscription?->installationAddress
                ?? $customer?->addresses()->withTrashed()->where('is_primary', true)->orderByRaw('deleted_at is not null')->orderBy('id')->first();

            return [
                'customer_name' => $customer?->name,
                'customer_address' => self::addressLine($address),
                'customer_tax_id' => $customer?->tax_id,
                'package_name' => $subscription?->servicePackage?->name,
            ];
        });
    }

    /**
     * What the invoice prints: the snapshot of an issued invoice, whole, because a missing tax id or package is a
     * fact of that day; the live party of a draft.
     *
     * @return Party
     */
    public static function of(Invoice $invoice): array
    {
        if ($invoice->customer_name_snapshot === null) {
            return self::live($invoice);
        }

        return array_map(fn (string $column): ?string => $invoice->getAttribute($column), self::COLUMNS);
    }

    /** Fills the snapshot columns from the live party, once: an invoice that has one keeps it. Does not save. */
    public static function freeze(Invoice $invoice): void
    {
        if ($invoice->customer_name_snapshot !== null) {
            return;
        }

        foreach (self::live($invoice) as $key => $value) {
            $invoice->setAttribute(self::COLUMNS[$key], $value);
        }
    }

    private static function addressLine(?CustomerAddress $address): ?string
    {
        return AddressLine::of($address === null ? null : [
            'address' => $address->address,
            'village_name' => data_get($address, 'village.name'),
            'district_name' => data_get($address, 'district.name'),
            'city_name' => data_get($address, 'regionCity.name'),
            'city' => $address->city,
            'province_name' => data_get($address, 'province.name'),
            'postal_code' => $address->postal_code,
        ]);
    }
}
