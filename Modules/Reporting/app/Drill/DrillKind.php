<?php

namespace Modules\Reporting\Drill;

use App\Models\Core\EmployeeEvaluation;
use App\Models\Core\ServiceSubscription;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Billing\Models\Invoice;
use Modules\NetworkAsset\Models\NetworkAsset;
use Modules\NetworkAsset\Models\NetworkAssetInstallation;
use Modules\SPK\Models\WorkOrder;
use Modules\Ticketing\Models\Ticket;

/**
 * The kind of record a drill-down list shows: which module permission opens it, how its rows are
 * laid out, sorted and linked, and which rows a ticket or SPK handler may see.
 */
enum DrillKind: string
{
    case Subscription = 'subscription';
    case Invoice = 'invoice';
    case Ticket = 'ticket';
    case WorkOrder = 'work_order';
    case Asset = 'asset';
    case Installation = 'installation';
    case Evaluation = 'evaluation';

    /** @return class-string<Model> */
    public function model(): string
    {
        return match ($this) {
            self::Subscription => ServiceSubscription::class,
            self::Invoice => Invoice::class,
            self::Ticket => Ticket::class,
            self::WorkOrder => WorkOrder::class,
            self::Asset => NetworkAsset::class,
            self::Installation => NetworkAssetInstallation::class,
            self::Evaluation => EmployeeEvaluation::class,
        };
    }

    public function noun(): string
    {
        return match ($this) {
            self::Subscription => 'langganan',
            self::Invoice => 'tagihan',
            self::Ticket => 'tiket',
            self::WorkOrder => 'SPK',
            self::Asset => 'aset',
            self::Installation => 'pemasangan',
            self::Evaluation => 'evaluasi',
        };
    }

    /** The source module's own list permission, through its policy: report access alone never opens these rows. */
    public function authorizes(User $user): bool
    {
        $policyModel = $this === self::Installation ? NetworkAsset::class : $this->model();

        return $user->can('viewAny', $policyModel);
    }

    /**
     * Ticket and SPK handlers (may start/resolve, may not assign) only see rows assigned to them in
     * the module lists; the same rule applies here.
     */
    public function restrictsHandlers(User $user): bool
    {
        return match ($this) {
            self::Ticket => ($user->can('ticket.start') || $user->can('ticket.resolve') || $user->can('ticket.close'))
                && ! $user->can('ticket.assign'),
            self::WorkOrder => ($user->can('spk.start') || $user->can('spk.submit')) && ! $user->can('spk.assign'),
            default => false,
        };
    }

    /**
     * @param  Builder<*>  $query
     * @return Builder<*>
     */
    public function scopeToActor(Builder $query, User $user): Builder
    {
        if (! $this->restrictsHandlers($user)) {
            return $query;
        }

        return $user->is_active
            ? $query->where($query->getModel()->qualifyColumn('assigned_to'), $user->id)
            : $query->whereKey([]);
    }

    /** @return list<array{key: string, label: string, sortable: bool, numeric: bool}> */
    public function columns(): array
    {
        $sortable = $this->sortable();
        $columns = match ($this) {
            self::Subscription => [
                'code' => 'Kode', 'customer' => 'Pelanggan', 'package' => 'Paket', 'status' => 'Status',
                'mrc_amount' => 'MRC', 'created_at' => 'Dibuat', 'terminated_at' => 'Berhenti',
            ],
            self::Invoice => [
                'number' => 'Tagihan', 'customer' => 'Pelanggan', 'type' => 'Jenis', 'status' => 'Status',
                'issue_date' => 'Terbit', 'total' => 'Total', 'paid_amount' => 'Terbayar', 'outstanding' => 'Piutang',
            ],
            self::Ticket => [
                'code' => 'Tiket', 'title' => 'Judul', 'category' => 'Kategori', 'status' => 'Status',
                'created_at' => 'Dibuat', 'first_response_at' => 'Respons pertama', 'response_deadline' => 'Target respons',
                'response' => 'Respons', 'resolved_at' => 'Selesai',
                'sla_deadline' => 'Batas SLA', 'sla' => 'SLA', 'resolution_minutes' => 'Penyelesaian (menit)',
                'first_response_minutes' => 'Respons pertama (menit)',
            ],
            self::WorkOrder => [
                'code' => 'SPK', 'title' => 'Judul', 'type' => 'Jenis', 'status' => 'Status', 'assignee' => 'Teknisi',
                'started_at' => 'Dimulai', 'completed_at' => 'Selesai', 'work_minutes' => 'Durasi (menit)',
            ],
            self::Asset => [
                'code' => 'Kode', 'name' => 'Nama', 'asset_type' => 'Jenis', 'status' => 'Status',
                'location' => 'Lokasi', 'customer' => 'Pelanggan', 'serial_number' => 'Serial',
            ],
            self::Installation => [
                'asset' => 'Aset', 'location' => 'Lokasi', 'customer' => 'Pelanggan',
                'installed_at' => 'Dipasang', 'removed_at' => 'Dilepas', 'installer' => 'Dipasang oleh',
            ],
            self::Evaluation => [
                'evaluated_at' => 'Dievaluasi', 'employee' => 'Teknisi', 'score' => 'Skor',
                'customer_rating' => 'Rating pelanggan', 'first_response_minutes' => 'Respons pertama (menit)',
                'resolution_minutes' => 'Penyelesaian (menit)', 'evaluator' => 'Evaluator',
            ],
        };
        $numeric = ['mrc_amount', 'total', 'paid_amount', 'outstanding', 'resolution_minutes', 'first_response_minutes', 'work_minutes', 'score', 'customer_rating'];

        $result = [];
        foreach ($columns as $key => $label) {
            $result[] = [
                'key' => $key,
                'label' => $label,
                'sortable' => isset($sortable[$key]),
                'numeric' => in_array($key, $numeric, true),
            ];
        }

        return $result;
    }

    /**
     * Sort allowlist: list key => column of the model's table.
     *
     * @return array<string, string>
     */
    public function sortable(): array
    {
        return match ($this) {
            self::Subscription => ['code' => 'code', 'status' => 'status', 'mrc_amount' => 'mrc_amount', 'created_at' => 'created_at', 'terminated_at' => 'terminated_at'],
            self::Invoice => ['number' => 'number', 'type' => 'type', 'status' => 'status', 'issue_date' => 'issue_date', 'total' => 'total', 'paid_amount' => 'paid_amount'],
            self::Ticket => ['code' => 'code', 'status' => 'status', 'created_at' => 'created_at', 'resolved_at' => 'resolved_at', 'sla_deadline' => 'sla_deadline', 'response_deadline' => 'response_deadline'],
            self::WorkOrder => ['code' => 'code', 'type' => 'type', 'status' => 'status', 'started_at' => 'started_at', 'completed_at' => 'completed_at', 'created_at' => 'created_at'],
            self::Asset => ['code' => 'code', 'name' => 'name', 'asset_type' => 'asset_type', 'status' => 'status', 'created_at' => 'created_at'],
            self::Installation => ['installed_at' => 'installed_at', 'removed_at' => 'removed_at'],
            self::Evaluation => ['evaluated_at' => 'evaluated_at', 'score' => 'score', 'customer_rating' => 'customer_rating'],
        };
    }

    /** @return array{0: string, 1: 'asc'|'desc'} */
    public function defaultSort(): array
    {
        return match ($this) {
            self::Invoice => ['issue_date', 'desc'],
            self::Installation => ['installed_at', 'desc'],
            self::Evaluation => ['evaluated_at', 'desc'],
            default => ['created_at', 'desc'],
        };
    }

    /** @return list<string> */
    public function with(): array
    {
        return match ($this) {
            self::Subscription => ['customer:id,name', 'servicePackage:id,name'],
            self::Invoice => ['customer:id,name'],
            self::Ticket => ['category:id,name'],
            self::WorkOrder => ['assignee:id,name'],
            self::Asset => ['location:id,name', 'customer:id,name'],
            self::Installation => ['asset:id,public_id,code', 'location:id,name', 'customer:id,name', 'installer:id,name'],
            self::Evaluation => ['employee:id,name', 'evaluator:id,name'],
        };
    }

    /**
     * One list row: scalars only, keyed like columns().
     *
     * @return array<string, string|int|float|null>
     */
    public function row(Model $model): array
    {
        $get = static fn (string $path): mixed => data_get($model, $path);

        $row = match ($this) {
            self::Subscription => [
                'code' => $get('code'),
                'customer' => $get('customer.name'),
                'package' => $get('servicePackage.name'),
                'status' => $get('status'),
                'mrc_amount' => $get('mrc_amount'),
                'created_at' => self::moment($get('created_at')),
                'terminated_at' => self::moment($get('terminated_at')),
            ],
            self::Invoice => [
                'number' => $get('number'),
                'customer' => $get('customer.name'),
                'type' => $get('type'),
                'status' => $get('status'),
                'issue_date' => $get('issue_date')?->toDateString(),
                'total' => $get('total'),
                'paid_amount' => $get('paid_amount'),
                'outstanding' => Money::round(Money::sub($get('total'), $get('paid_amount'))),
            ],
            self::Ticket => [
                'code' => $get('code'),
                'title' => $get('title'),
                'category' => $get('category.name'),
                'status' => $get('status'),
                'created_at' => self::moment($get('created_at')),
                'first_response_at' => self::moment($get('first_response_at')),
                'response_deadline' => self::moment($get('response_deadline')),
                'response' => self::responseOutcome($get('first_response_at'), $get('response_deadline')),
                'resolved_at' => self::moment($get('resolved_at')),
                'sla_deadline' => self::moment($get('sla_deadline')),
                'sla' => self::slaOutcome($get('resolved_at'), $get('sla_deadline')),
                'resolution_minutes' => self::minutesBetween($get('created_at'), $get('resolved_at')),
                'first_response_minutes' => self::minutesBetween($get('created_at'), $get('first_response_at')),
            ],
            self::WorkOrder => [
                'code' => $get('code'),
                'title' => $get('title'),
                'type' => $get('type'),
                'status' => $get('status'),
                'assignee' => $get('assignee.name'),
                'started_at' => self::moment($get('started_at')),
                'completed_at' => self::moment($get('completed_at')),
                'work_minutes' => self::minutesBetween($get('started_at'), $get('completed_at')),
            ],
            self::Asset => [
                'code' => $get('code'),
                'name' => $get('name'),
                'asset_type' => $get('asset_type'),
                'status' => $get('status'),
                'location' => $get('location.name'),
                'customer' => $get('customer.name'),
                'serial_number' => $get('serial_number'),
            ],
            self::Installation => [
                'asset' => $get('asset_code_snapshot') ?? $get('asset.code'),
                'location' => $get('location.name'),
                'customer' => $get('customer.name'),
                'installed_at' => self::moment($get('installed_at')),
                'removed_at' => self::moment($get('removed_at')),
                'installer' => $get('installer.name'),
            ],
            self::Evaluation => [
                'evaluated_at' => self::moment($get('evaluated_at')),
                'employee' => $get('employee.name'),
                'score' => $get('score'),
                'customer_rating' => $get('customer_rating'),
                'first_response_minutes' => $get('first_response_minutes'),
                'resolution_minutes' => $get('resolution_minutes'),
                'evaluator' => $get('evaluator.name'),
            ],
        };

        return ['id' => $model->getAttribute('public_id'), ...$row];
    }

    /** The record's own page, for the actor already allowed to list this kind. */
    public function url(Model $model): string
    {
        return match ($this) {
            self::Subscription => route('admin.subscriptions.show', $model, false),
            self::Invoice => route('admin.invoices.show', $model, false),
            self::Ticket => route('admin.tickets.show', $model, false),
            self::WorkOrder => route('admin.spk.show', $model, false),
            self::Asset => route('admin.network-assets.show', $model, false),
            self::Installation => route('admin.network-assets.show', data_get($model, 'asset'), false),
            self::Evaluation => route('admin.evaluations.show', $model, false),
        };
    }

    private static function moment(?CarbonInterface $moment): ?string
    {
        return $moment?->toDateTimeString();
    }

    /** Whole minutes between two moments, truncated like the TIMESTAMPDIFF(MINUTE) the averages use. */
    private static function minutesBetween(?CarbonInterface $from, ?CarbonInterface $to): ?int
    {
        return $from === null || $to === null ? null : intdiv($to->getTimestamp() - $from->getTimestamp(), 60);
    }

    private static function slaOutcome(?CarbonInterface $resolvedAt, ?CarbonInterface $deadline): string
    {
        return match (true) {
            $resolvedAt === null => '',
            $deadline === null => 'Tanpa batas',
            $resolvedAt->lessThanOrEqualTo($deadline) => 'Tepat waktu',
            default => 'Terlambat',
        };
    }

    private static function responseOutcome(?CarbonInterface $respondedAt, ?CarbonInterface $deadline): string
    {
        return match (true) {
            $deadline === null => 'Tanpa target',
            $respondedAt === null => 'Belum ada respons',
            $respondedAt->lessThanOrEqualTo($deadline) => 'Tepat waktu',
            default => 'Terlambat',
        };
    }
}
