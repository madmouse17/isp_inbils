<?php

namespace Modules\Billing\Queries;

use App\Http\Resources\CustomerResource;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Notifications\InvoiceWhatsAppNotification;
use Modules\Billing\Services\BillingService;

/**
 * The open invoices of the company one by one, oldest due date first: what a collector works through (F5-U03). The
 * "Per pelanggan" view is BillingService::receivables(); both count age in days past the due date, so a bucket names the
 * same invoices in either view.
 *
 * shortcut: the list sorts the company's open invoices (hundreds) with a filesort over (company_id, status); add
 * (company_id, status, due_date) when one tenant passes 50k open invoices.
 */
class ReceivableInvoicesQuery
{
    /** The aging buckets of BillingService::receivables(), by the same keys. */
    public const BUCKETS = ['current', 'b1_30', 'b31_60', 'b61_90', 'b90_plus'];

    public const PER_PAGE = 25;

    /** The query-string key of the page: the customer list of the same page keeps `page`. */
    public const PAGE_NAME = 'invoices_page';

    /** The words of the buckets, for the export. */
    private const BUCKET_LABELS = ['current' => 'Belum jatuh tempo', 'b1_30' => '1-30 hari', 'b31_60' => '31-60 hari', 'b61_90' => '61-90 hari', 'b90_plus' => '>90 hari'];

    private const STATUS_LABELS = ['sent' => 'Belum dibayar', 'partial' => 'Dibayar sebagian', 'overdue' => 'Lewat jatuh tempo'];

    /** The columns of the export by header; the value only names the data path ExportQuery needs, exportRow() has the cells. */
    public const EXPORT_COLUMNS = [
        'Nomor tagihan' => 'number',
        'Pelanggan' => 'customer',
        'Kode pelanggan' => 'customer_code',
        'Tanggal terbit' => 'issue_date',
        'Jatuh tempo' => 'due_date',
        'Lewat jatuh tempo (hari)' => 'days_overdue',
        'Umur' => 'bucket',
        'Total' => 'total',
        'Terbayar' => 'paid_amount',
        'Sisa' => 'sisa',
        'Status' => 'status',
    ];

    /** How the PDF writes the typed columns (K32); the CSV keeps the raw values. */
    public const EXPORT_TYPES = ['Total' => 'money', 'Terbayar' => 'money', 'Sisa' => 'money', 'Tanggal terbit' => 'date', 'Jatuh tempo' => 'date', 'Lewat jatuh tempo (hari)' => 'number'];

    /**
     * @param  array{search?: ?string, bucket?: ?string, due_from?: ?string, due_to?: ?string}  $filters
     * @param  string  $today  the company's calendar day, 'YYYY-MM-DD'
     * @return Builder<Invoice>
     */
    public function builder(array $filters, string $today): Builder
    {
        $today = CarbonImmutable::parse($today);
        $like = '%'.addcslashes((string) ($filters['search'] ?? ''), '\\%_').'%';

        return Invoice::query()
            ->with('customer:id,public_id,code,name,deleted_at')
            ->whereIn('status', InvoiceWhatsAppNotification::AWAITING_PAYMENT)
            ->whereColumn('paid_amount', '<', 'total')
            ->when($filters['search'] ?? null, fn (Builder $query) => $query->where(fn (Builder $search) => $search
                ->where('number', 'like', $like)
                ->orWhere('customer_name_snapshot', 'like', $like)
                ->orWhereHas('customer', fn (Builder $customer) => $customer->where('name', 'like', $like)->orWhere('code', 'like', $like))))
            ->when($filters['bucket'] ?? null, fn (Builder $query, string $bucket) => match ($bucket) {
                'current' => $query->where('due_date', '>=', $today->toDateString()),
                'b1_30' => $query->whereBetween('due_date', [$today->subDays(30)->toDateString(), $today->subDay()->toDateString()]),
                'b31_60' => $query->whereBetween('due_date', [$today->subDays(60)->toDateString(), $today->subDays(31)->toDateString()]),
                'b61_90' => $query->whereBetween('due_date', [$today->subDays(90)->toDateString(), $today->subDays(61)->toDateString()]),
                'b90_plus' => $query->where('due_date', '<=', $today->subDays(91)->toDateString()),
                default => $query,
            })
            ->when($filters['due_from'] ?? null, fn (Builder $query, string $day) => $query->whereDate('due_date', '>=', $day))
            ->when($filters['due_to'] ?? null, fn (Builder $query, string $day) => $query->whereDate('due_date', '<=', $day))
            ->orderBy('due_date')
            ->orderBy('id');
    }

    /**
     * One page of rows, each with whether it was reminded today: one cache read for the whole page.
     *
     * @param  array{search?: ?string, bucket?: ?string, due_from?: ?string, due_to?: ?string}  $filters
     * @return LengthAwarePaginator<int, non-empty-array<string, mixed>>
     */
    public function paginate(array $filters, string $today): LengthAwarePaginator
    {
        $page = $this->builder($filters, $today)->paginate(self::PER_PAGE, ['*'], self::PAGE_NAME);
        $keys = $page->getCollection()->mapWithKeys(fn (Invoice $invoice): array => [
            $invoice->id => BillingService::reminderKey($invoice->company_id, $invoice->id, $today),
        ]);
        $reminded = Cache::many($keys->values()->all());

        return $page->through(fn (Invoice $invoice): array => $this->row($invoice, $today) + [
            'reminded_today' => (bool) $reminded[$keys[$invoice->id]],
        ]);
    }

    /**
     * One invoice as a row of the list and of its export. The customer is named as the invoice printed it.
     *
     * @return array<string, mixed>
     */
    public function row(Invoice $invoice, string $today): array
    {
        $customer = $invoice->customer;
        $today = CarbonImmutable::parse($today);
        $daysPast = (int) CarbonImmutable::parse($invoice->due_date->toDateString())->diffInDays($today, false);

        return [
            'id' => $invoice->public_id,
            'number' => $invoice->number,
            'customer' => $customer === null ? null : [...CustomerResource::summary($customer), 'name' => $invoice->customer_name_snapshot ?? $customer->name],
            'status' => $invoice->status,
            'issue_date' => $invoice->issue_date->toDateString(),
            'due_date' => $invoice->due_date->toDateString(),
            'days_overdue' => max(0, $daysPast),
            'bucket' => match (true) {
                $daysPast <= 0 => 'current',
                $daysPast <= 30 => 'b1_30',
                $daysPast <= 60 => 'b31_60',
                $daysPast <= 90 => 'b61_90',
                default => 'b90_plus',
            },
            'total' => $invoice->total,
            'paid_amount' => $invoice->paid_amount,
            'sisa' => $invoice->sisa,
            // A customer who was deleted since still owes: the row stays, the reminder has nobody to go to.
            'can_remind' => $customer !== null && ! $customer->trashed(),
        ];
    }

    /**
     * One invoice as a line of the export, keyed by the headers of EXPORT_COLUMNS. A late invoice reads "Lewat jatuh
     * tempo" even before the daily job has turned its status.
     *
     * @return array<string, string|int>
     */
    public function exportRow(Invoice $invoice, string $today): array
    {
        $row = $this->row($invoice, $today);

        return [
            'Nomor tagihan' => $row['number'],
            'Pelanggan' => $row['customer']['name'] ?? '',
            'Kode pelanggan' => $row['customer']['code'] ?? '',
            'Tanggal terbit' => $row['issue_date'],
            'Jatuh tempo' => $row['due_date'],
            'Lewat jatuh tempo (hari)' => $row['days_overdue'],
            'Umur' => self::BUCKET_LABELS[$row['bucket']],
            'Total' => $row['total'],
            'Terbayar' => $row['paid_amount'],
            'Sisa' => $row['sisa'],
            'Status' => self::STATUS_LABELS[$row['days_overdue'] > 0 ? 'overdue' : $row['status']],
        ];
    }

    /**
     * The header lines of the PDF: the day the ages are counted to, or the due dates asked for, and the other filters in words.
     *
     * @param  array{search?: ?string, bucket?: ?string, due_from?: ?string, due_to?: ?string}  $filters
     * @return array{period: string, filters: list<string>}
     */
    public function describe(array $filters, string $today): array
    {
        $day = static fn (string $date): string => CarbonImmutable::parse($date)->locale('id')->translatedFormat('j M Y');
        $from = $filters['due_from'] ?? null;
        $to = $filters['due_to'] ?? null;

        return [
            'period' => match (true) {
                $from !== null && $to !== null => 'Jatuh tempo '.$day($from).' sampai '.$day($to),
                $from !== null => 'Jatuh tempo mulai '.$day($from),
                $to !== null => 'Jatuh tempo sampai '.$day($to),
                default => 'Umur dihitung per '.$day($today),
            },
            'filters' => array_values(array_filter([
                isset($filters['bucket']) ? 'Umur: '.self::BUCKET_LABELS[$filters['bucket']] : null,
                isset($filters['search']) ? 'Pencarian: '.$filters['search'] : null,
            ])),
        ];
    }
}
