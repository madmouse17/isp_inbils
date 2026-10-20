<?php

namespace Modules\SPK\Support;

use App\Actions\CreateInvoiceFromSpkAction;
use App\Models\User;
use App\Services\Core\IntegrationConfig;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Modules\NetworkAsset\Models\NetworkAccount;
use Modules\NetworkAsset\Services\Radius\Realm;
use Modules\SPK\Models\WorkOrder;
use Modules\SPK\Models\WorkOrderItem;

/**
 * What approving an SPK does, and what is still left to do after it, in words for the office.
 *
 * `for()` feeds the confirmation of the SPK page and of the review sheet. Each line restates one rule of
 * SpkService::approve and CompleteSpkAction, so a line is only there when the approval really does that: change one of
 * those rules and the matching line changes here.
 *
 * `nextSteps()` feeds the card on a completed installation: the invoice the approval left and the PPPoE account, for
 * the people who may see or act on each.
 */
final class SpkApprovalImpact
{
    /**
     * @return list<string>
     */
    public static function for(WorkOrder $wo): array
    {
        $wo->loadMissing(['company', 'customer', 'subscription.customer', 'subscription.packageVersion.speedProfile', 'items.product']);

        return array_values(array_filter([
            self::activation($wo),
            self::invoice($wo),
            self::networkAccount($wo),
            self::materials($wo),
        ]));
    }

    /**
     * @return array{
     *     invoice: array{id: string, number: string, status: string, total: string, due_date: ?string}|null,
     *     can_view_invoice: bool,
     *     billable: bool,
     *     can_send_invoice: bool,
     *     can_create_invoice: bool,
     *     network_account: array{username: string}|null,
     *     can_view_network_account: bool,
     *     can_manage_network_account: bool,
     * }|null
     */
    public static function nextSteps(WorkOrder $wo, User $user): ?array
    {
        if ($wo->type !== 'installation' || $wo->status !== 'completed') {
            return null;
        }

        $canViewInvoice = $user->can('billing.view');
        $account = $wo->subscription_id === null ? null : NetworkAccount::query()->where('subscription_id', $wo->subscription_id)->first(['id', 'username']);
        // Without RADIUS no account is made on its own, so a missing one is no step: nothing would apply it.
        $canViewAccount = $wo->subscription_id !== null
            && $user->can('network_asset.account.view')
            && ($account !== null || app(IntegrationConfig::class)->isEnabled('radius', (int) $wo->company_id));

        if (! $canViewInvoice && ! $canViewAccount) {
            return null;
        }

        $invoice = $canViewInvoice ? self::invoiceOf($wo) : null;
        // An SPK that bills nothing has no invoice to wait for, and one that was billed once is never billed again.
        $billable = $canViewInvoice && $invoice === null && CreateInvoiceFromSpkAction::linesFor($wo) !== [];

        return [
            'invoice' => $invoice === null ? null : [
                'id' => (string) $invoice->public_id,
                'number' => (string) $invoice->number,
                'status' => (string) $invoice->status,
                'total' => (string) $invoice->total,
                'due_date' => $invoice->due_date === null ? null : substr((string) $invoice->due_date, 0, 10),
            ],
            'can_view_invoice' => $canViewInvoice,
            'billable' => $billable,
            'can_send_invoice' => $canViewInvoice && $user->can('billing.send'),
            'can_create_invoice' => $billable && $user->can('billing.create'),
            'network_account' => $canViewAccount && $account !== null ? ['username' => $account->username] : null,
            'can_view_network_account' => $canViewAccount,
            'can_manage_network_account' => $canViewAccount && $user->can('network_asset.account.manage'),
        ];
    }

    /** CompleteSpkAction::activateSubscription: any pending subscription of the SPK is activated, and the customer follows. */
    private static function activation(WorkOrder $wo): ?string
    {
        $subscription = $wo->subscription;

        if ($subscription === null || $subscription->trashed() || $subscription->status !== 'pending') {
            return null;
        }

        $customer = $subscription->customer ?? $wo->customer;

        return "Pelanggan {$customer?->code} dan langganan {$subscription->code} menjadi aktif.";
    }

    /** CompleteSpkAction::createInvoice and CreateInvoiceFromSpkAction::afterApproval: only an installation is billed by its approval. */
    private static function invoice(WorkOrder $wo): ?string
    {
        if ($wo->type !== 'installation') {
            return null;
        }

        $lines = CreateInvoiceFromSpkAction::linesFor($wo);

        if ($lines === []) {
            return 'Tidak ada tagihan pemasangan (tidak ada biaya yang ditagihkan).';
        }

        $total = array_reduce($lines, fn (string $sum, array $line): string => Money::add($sum, Money::add($line['line_total'], $line['tax_amount'])), '0');
        $amount = 'Rp '.Money::display($total);

        return filter_var($wo->company?->settings['auto_send_installation_invoice'] ?? false, FILTER_VALIDATE_BOOL)
            ? "Tagihan pemasangan {$amount} dibuat dan langsung dikirim ke pelanggan."
            : "Tagihan pemasangan {$amount} dibuat sebagai draf. Kirim dari halaman tagihan.";
    }

    /**
     * NetworkAccountService::provisionForInstallation: an installation that has no account yet gets one when the company
     * serves RADIUS. create() refuses a speed profile without a RADIUS name and a company code that is no realm; it
     * then makes none, so those two cases leave the line out.
     */
    private static function networkAccount(WorkOrder $wo): ?string
    {
        $subscription = $wo->subscription;

        if ($wo->type !== 'installation' || $subscription === null || $subscription->trashed() || $subscription->status === 'terminated') {
            return null;
        }

        if (NetworkAccount::query()->where('subscription_id', $subscription->id)->exists()
            || ! app(IntegrationConfig::class)->isEnabled('radius', (int) $subscription->company_id)
            || blank($subscription->packageVersion?->speedProfile?->getAttribute('radius_profile_name'))
            || Realm::fromCode($wo->company?->code) === null) {
            return null;
        }

        $customer = $subscription->customer ?? $wo->customer;

        return "Akun PPPoE dibuat dari kode pelanggan {$customer?->code}.";
    }

    /**
     * CompleteSpkAction::consumeStock: what the SPK used comes out of stock, except what an inventory request handed over
     * already took out of the warehouse. A product counts once, however many items of the SPK name it.
     */
    private static function materials(WorkOrder $wo): ?string
    {
        $used = [];

        foreach ($wo->items as $item) {
            /** @var WorkOrderItem $item */
            if (Money::gt((string) $item->quantity_used, '0')) {
                $used[(int) $item->product_id] = Money::add($used[(int) $item->product_id] ?? '0', (string) $item->quantity_used);
            }
        }

        $handedOver = self::handedOver($wo);
        $count = 0;

        foreach ($used as $productId => $quantity) {
            $locationId = $handedOver[$productId]['location_id'] ?? $wo->location_id;

            if ($locationId !== null && Money::compare($quantity, $handedOver[$productId]['quantity'] ?? '0') > 0) {
                $count++;
            }
        }

        return $count === 0 ? null : "Material terpakai dikurangi dari stok: {$count} barang.";
    }

    /**
     * What the handed-over inventory requests of this SPK took out of stock, per product, and the place it left. The
     * read CompleteSpkAction::issuedCoverage makes while it approves, without its locks.
     *
     * @return array<int, array{quantity: string, location_id: ?int}>
     */
    private static function handedOver(WorkOrder $wo): array
    {
        $warehouses = DB::table('inventory_requests')
            ->where('company_id', $wo->company_id)
            ->where('source_type', 'work_order')
            ->where('source_id', $wo->id)
            ->where('status', 'issued')
            ->orderBy('id')
            ->pluck('warehouse_location_id', 'id');

        if ($warehouses->isEmpty()) {
            return [];
        }

        $coverage = [];
        $rows = DB::table('inventory_request_items')
            ->whereIn('inventory_request_id', $warehouses->keys())
            ->where('quantity_issued', '>', 0)
            ->orderBy('id')
            ->get(['inventory_request_id', 'product_id', 'source_location_id', 'quantity_issued']);

        foreach ($rows as $row) {
            $productId = (int) $row->product_id;
            $coverage[$productId] = [
                'quantity' => Money::add($coverage[$productId]['quantity'] ?? '0', $row->quantity_issued),
                'location_id' => $row->source_location_id ?? $warehouses[$row->inventory_request_id],
            ];
        }

        return $coverage;
    }

    /** The invoice of this SPK: a live one first, else the cancelled one (create-from-spk refuses an SPK that was ever billed). */
    private static function invoiceOf(WorkOrder $wo): ?object
    {
        $invoices = DB::table('invoices')
            ->where('company_id', $wo->company_id)
            ->where('work_order_id', $wo->id)
            ->whereNull('deleted_at')
            ->orderByDesc('id')
            ->get(['public_id', 'number', 'status', 'total', 'due_date']);

        return $invoices->first(fn (object $invoice): bool => $invoice->status !== 'cancelled') ?? $invoices->first();
    }
}
