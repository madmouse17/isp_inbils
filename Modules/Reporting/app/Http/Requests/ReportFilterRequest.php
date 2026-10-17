<?php

namespace Modules\Reporting\Http\Requests;

use App\Models\Core\Location;
use App\Models\User;
use App\Rules\PublicIdExists;
use App\Services\Core\CompanyService;
use App\Support\PublicId;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Inventory\Models\Product;
use Modules\Reporting\Queries\SalesKpiQuery;
use Modules\Reporting\Registry\ReportType;
use Modules\Service\Models\ServicePackageCategory;
use Modules\Ticketing\Models\TicketCategory;

class ReportFilterRequest extends FormRequest
{
    /** @var array<string, array{id: int, public_id: string}|null> */
    private array $records = [];

    /**
     * A report is authorized by the capability the report catalog assigns to the matched route,
     * so route middleware and this request can never drift apart. Unknown routes fail closed.
     */
    public function authorize(): bool
    {
        $report = ReportType::fromRouteName($this->route()?->getName());

        return $report !== null && ($this->user()?->can($report->permission()) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $sortColumns = match (true) {
            $this->routeIs('admin.reports.stock-card') => ['nullable', 'string', 'in:created_at,quantity,balance_after'],
            $this->routeIs('admin.reports.sales') => ['nullable', 'string', 'in:'.implode(',', SalesKpiQuery::SORTABLE)],
            default => ['nullable', 'string', 'in:id,created_at,log_name,event,description'],
        };

        return [
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            // Who may be picked is decided by TechnicianReportAccess (403), so only the shape is checked here.
            'technician_id' => ['nullable', 'ulid'],
            'location_id' => ['nullable', $this->recordExists('location_id')],
            'asset_type' => ['nullable', 'string'],
            'category_id' => ['nullable', $this->recordExists('category_id')],
            'product_id' => ['nullable', $this->recordExists('product_id')],
            'acquirer_id' => ['nullable', $this->recordExists('acquirer_id')],
            'user_id' => ['nullable', $this->recordExists('user_id')],
            'log_name' => ['nullable', 'string'],
            'event' => ['nullable', 'string', 'max:50'],
            'search' => ['nullable', 'string', 'max:255'],
            'service_package_category_id' => ['nullable', $this->recordExists('service_package_category_id')],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'sort' => $sortColumns,
            'direction' => ['nullable', 'string', 'in:asc,desc,ASC,DESC'],
            'sort_by' => $sortColumns,
            'sort_dir' => ['nullable', 'string', 'in:asc,desc,ASC,DESC'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'date_from' => 'tanggal mulai',
            'date_to' => 'tanggal akhir',
            'technician_id' => 'teknisi',
            'location_id' => 'lokasi',
            'asset_type' => 'jenis aset',
            'category_id' => 'kategori',
            'product_id' => 'barang',
            'acquirer_id' => 'petugas penjualan',
            'user_id' => 'pengguna',
            'log_name' => 'modul',
            'event' => 'aksi',
            'search' => 'pencarian',
            'service_package_category_id' => 'kategori paket',
            'page' => 'halaman',
            'per_page' => 'baris per halaman',
            'sort' => 'urutan',
            'sort_by' => 'urutan',
            'direction' => 'arah urutan',
            'sort_dir' => 'arah urutan',
            'group' => 'kelompok',
            'format' => 'format berkas',
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'date_to.after_or_equal' => 'Tanggal akhir tidak boleh sebelum tanggal mulai.',
            'location_id.public_id_exists' => 'Lokasi tidak ditemukan.',
            'product_id.public_id_exists' => 'Barang tidak ditemukan.',
            'acquirer_id.public_id_exists' => 'Petugas penjualan tidak ditemukan.',
            'user_id.public_id_exists' => 'Pengguna tidak ditemukan.',
        ];
    }

    public function dateFrom(): ?string
    {
        return $this->input('date_from');
    }

    public function dateTo(): ?string
    {
        $dateTo = $this->input('date_to');

        return $dateTo ? Carbon::parse($dateTo)->endOfDay()->toDateTimeString() : null;
    }

    /**
     * The record a public id filter names: its integer id for the queries and its public id for the links and files
     * that must reproduce this view. The input stays as the client sent it, so the page can echo it. Null when the
     * filter is empty; call it after validation, which refuses every value that names no record of the company.
     *
     * @return array{id: int, public_id: string}|null
     */
    public function record(string $key): ?array
    {
        if (! array_key_exists($key, $this->records)) {
            [$model, $constrain] = $this->recordSource($key);
            $value = $this->input($key);
            $id = PublicId::resolveOne($model, $value, $constrain);

            $this->records[$key] = $id === null ? null : ['id' => $id, 'public_id' => strtolower($value)];
        }

        return $this->records[$key];
    }

    private function recordExists(string $key): PublicIdExists
    {
        return new PublicIdExists(...$this->recordSource($key));
    }

    /**
     * The model a public id filter names and what the record must satisfy on top of the company scope.
     *
     * @return array{0: class-string<Model>, 1: (Closure(Builder<Model>): mixed)|null}
     */
    private function recordSource(string $key): array
    {
        $companyId = CompanyService::currentId();
        // The ledger and the tickets keep the rows of a soft-deleted product, location or category.
        $withTrashed = static fn (Builder $query): Builder => $query->withoutGlobalScope(SoftDeletingScope::class);

        return match ($key) {
            'location_id' => [Location::class, $withTrashed],
            'category_id' => [TicketCategory::class, $withTrashed],
            'product_id' => [Product::class, $withTrashed],
            'service_package_category_id' => [ServicePackageCategory::class, null],
            'acquirer_id' => [User::class, static fn (Builder $query): Builder => $query->where('users.company_id', $companyId)],
            // A member of the company, home or granted: the audit log's user picker offers exactly these.
            'user_id' => [User::class, static fn (Builder $query): Builder => $query->whereIn(
                'users.id',
                DB::table('company_user')->where('company_id', $companyId)->select('user_id'),
            )],
            default => throw new InvalidArgumentException("{$key} is not a record filter."),
        };
    }
}
