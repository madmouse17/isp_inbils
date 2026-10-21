<?php

namespace Modules\Reporting\Registry;

/**
 * Code-defined registry of every figure the reports compute, versioned in git.
 *
 * Every entry records the formula EXACTLY as the query implements it today (version v1),
 * including known quirks, so a number can be traced to the definition that produced it.
 * The registry never changes what a query returns: correcting a quirk means shipping a
 * new version of the metric (and its characterization test), never editing a v1 entry
 * in place. Entries are static text; the registry reads no company data.
 */
final class MetricRegistry
{
    /** @var list<MetricDefinition>|null */
    private static ?array $definitions = null;

    /** Caveat of every figure the Kategori paket filter narrows (Business and Sales): which category a subscription is in. */
    private const PACKAGE_CATEGORY_FILTER = 'Filter Kategori paket memakai kategori paket saat langganan dijual (kategori versi paket langganan): memindahkan paket ke kategori lain tidak memindahkan langganan yang sudah terjual. Tagihan dan SPK mengikuti kategori langganannya.';

    /** Caveat of the by-category breakdowns (SLA and Technician): which name a ticket's category has. */
    private const TICKET_CATEGORY_NAME = 'Tiket yang sudah ditutup memakai nama kategori saat ditutup, sehingga mengganti nama kategori tidak memindahkan tiket lama; tiket yang belum ditutup memakai nama kategori saat ini.';

    /** @return list<MetricDefinition> */
    public static function all(): array
    {
        return self::$definitions ??= [
            ...self::business(),
            ...self::sla(),
            ...self::asset(),
            ...self::technician(),
            ...self::sales(),
            ...self::stockCard(),
        ];
    }

    /** @return list<MetricDefinition> */
    public static function forReport(ReportType $report): array
    {
        return array_values(array_filter(
            self::all(),
            fn (MetricDefinition $metric): bool => $metric->report === $report,
        ));
    }

    /**
     * Report-level formula version: the highest metric version of the report, e.g. `business.v1`.
     * Null for reports that define no metrics.
     */
    public static function formulaVersion(ReportType $report): ?string
    {
        $versions = array_map(
            fn (MetricDefinition $metric): int => (int) substr($metric->version, 1),
            self::forReport($report),
        );

        return $versions === [] ? null : $report->value.'.v'.max($versions);
    }

    /**
     * Version metadata and definitions attached to a report response, keyed by payload key.
     *
     * @return array{
     *     report: string,
     *     formula_version: string|null,
     *     metrics: array<string, array<string, mixed>>
     * }
     */
    public static function meta(ReportType $report): array
    {
        $metrics = [];
        foreach (self::forReport($report) as $metric) {
            $metrics[$metric->key] = $metric->toArray();
        }

        return [
            'report' => $report->value,
            'formula_version' => self::formulaVersion($report),
            'metrics' => $metrics,
        ];
    }

    /**
     * Read-only data dictionary: every report that defines metrics with its capability and definitions.
     *
     * @return list<array<string, mixed>>
     */
    public static function dictionary(): array
    {
        $reports = [];
        foreach (ReportType::cases() as $report) {
            $metrics = self::forReport($report);
            if ($metrics === []) {
                continue;
            }

            $reports[] = [
                'key' => $report->value,
                'title' => $report->title(),
                'description' => $report->description(),
                'permission' => $report->permission(),
                'formula_version' => self::formulaVersion($report),
                'metrics' => array_map(fn (MetricDefinition $metric): array => $metric->toArray(), $metrics),
            ];
        }

        return $reports;
    }

    /** @return list<MetricDefinition> */
    private static function business(): array
    {
        $periodRule = 'Rentang berlaku bila Dari, Sampai, atau keduanya diisi (hanya Dari: sejak hari itu; hanya Sampai: sampai hari itu); bila kosong, semua data yang cocok dipakai.';

        return [
            new MetricDefinition(
                report: ReportType::Business,
                key: 'mrr',
                label: 'MRR',
                definition: 'Pendapatan berulang bulanan: tagihan bulanan dari semua langganan yang aktif saat ini.',
                formula: 'SUM(service_subscriptions.mrc_amount) untuk langganan dengan status = active.',
                unit: MetricUnit::Currency,
                dateBasis: DateBasis::Snapshot,
                sources: ['service_subscriptions.mrc_amount', 'service_subscriptions.status'],
                statuses: ['active'],
                caveats: [self::PACKAGE_CATEGORY_FILTER],
            ),
            new MetricDefinition(
                report: ReportType::Business,
                key: 'active_subscriptions',
                label: 'Langganan aktif',
                definition: 'Jumlah langganan yang aktif saat ini.',
                formula: 'COUNT service_subscriptions dengan status = active.',
                unit: MetricUnit::Count,
                dateBasis: DateBasis::Snapshot,
                sources: ['service_subscriptions.status'],
                statuses: ['active'],
                caveats: [self::PACKAGE_CATEGORY_FILTER],
            ),
            new MetricDefinition(
                report: ReportType::Business,
                key: 'suspended_subscriptions',
                label: 'Langganan ditangguhkan',
                definition: 'Jumlah langganan yang sedang ditangguhkan.',
                formula: 'COUNT service_subscriptions dengan status = suspended.',
                unit: MetricUnit::Count,
                dateBasis: DateBasis::Snapshot,
                sources: ['service_subscriptions.status'],
                statuses: ['suspended'],
                caveats: [self::PACKAGE_CATEGORY_FILTER],
            ),
            new MetricDefinition(
                report: ReportType::Business,
                key: 'terminated_subscriptions',
                label: 'Langganan berhenti',
                definition: 'Jumlah langganan yang telah berhenti, sepanjang waktu.',
                formula: 'COUNT service_subscriptions dengan status = terminated.',
                unit: MetricUnit::Count,
                dateBasis: DateBasis::Snapshot,
                sources: ['service_subscriptions.status'],
                statuses: ['terminated'],
                caveats: [
                    'Total sepanjang waktu; pakai Churn untuk langganan yang berhenti dalam periode terpilih.',
                    self::PACKAGE_CATEGORY_FILTER,
                ],
            ),
            new MetricDefinition(
                report: ReportType::Business,
                key: 'new_subscriptions',
                label: 'Langganan baru',
                definition: 'Langganan yang dibuat dalam periode terpilih.',
                formula: 'COUNT service_subscriptions dengan created_at antara Dari 00:00:00 dan Sampai 23:59:59.',
                unit: MetricUnit::Count,
                dateBasis: DateBasis::Period,
                sources: ['service_subscriptions.created_at'],
                dateColumn: 'service_subscriptions.created_at',
                caveats: [
                    'Bernilai 0 bila Dari dan Sampai sama-sama kosong.',
                    'Menghitung langganan dengan status apa pun, termasuk yang sudah tidak aktif.',
                    self::PACKAGE_CATEGORY_FILTER,
                ],
            ),
            new MetricDefinition(
                report: ReportType::Business,
                key: 'churn',
                label: 'Churn',
                definition: 'Langganan yang berhenti dalam periode terpilih. Berupa jumlah, bukan persentase.',
                formula: 'COUNT service_subscriptions dengan status = terminated dan terminated_at '
                    .'antara Dari 00:00:00 dan Sampai 23:59:59.',
                unit: MetricUnit::Count,
                dateBasis: DateBasis::Period,
                sources: ['service_subscriptions.status', 'service_subscriptions.terminated_at'],
                dateColumn: 'service_subscriptions.terminated_at',
                statuses: ['terminated'],
                caveats: [
                    'Jumlah langganan yang berhenti, bukan persentase: versi v1 tidak memiliki pembagi untuk tingkat churn.',
                    'Bernilai 0 bila Dari dan Sampai sama-sama kosong.',
                    self::PACKAGE_CATEGORY_FILTER,
                ],
            ),
            new MetricDefinition(
                report: ReportType::Business,
                key: 'revenue_paid',
                label: 'Pendapatan terbayar',
                definition: 'Jumlah terbayar dari tagihan lunas yang terbit dalam periode terpilih.',
                formula: 'SUM(invoices.paid_amount) untuk tagihan dengan status = paid dan issue_date '
                    .'antara Dari dan Sampai (Sampai termasuk).',
                unit: MetricUnit::Currency,
                dateBasis: DateBasis::Period,
                sources: ['invoices.paid_amount', 'invoices.status', 'invoices.issue_date'],
                dateColumn: 'invoices.issue_date',
                statuses: ['paid'],
                caveats: [
                    'Dihitung menurut tanggal terbit tagihan, bukan tanggal pembayaran.',
                    'Tagihan yang dibayar sebagian tidak termasuk: tagihan itu sendiri harus berstatus paid.',
                    $periodRule,
                    self::PACKAGE_CATEGORY_FILTER,
                ],
            ),
            new MetricDefinition(
                report: ReportType::Business,
                key: 'recurring_revenue',
                label: 'Pendapatan berulang',
                definition: 'Bagian dari Pendapatan terbayar yang berasal dari tagihan berulang.',
                formula: 'SUM(invoices.paid_amount) untuk tagihan Pendapatan terbayar dengan type = recurring.',
                unit: MetricUnit::Currency,
                dateBasis: DateBasis::Period,
                sources: ['invoices.paid_amount', 'invoices.status', 'invoices.issue_date', 'invoices.type'],
                dateColumn: 'invoices.issue_date',
                statuses: ['paid'],
                caveats: [
                    'Dihitung menurut tanggal terbit tagihan, bukan tanggal pembayaran.',
                    $periodRule,
                    self::PACKAGE_CATEGORY_FILTER,
                ],
            ),
            new MetricDefinition(
                report: ReportType::Business,
                key: 'one_time_revenue',
                label: 'Pendapatan sekali bayar',
                definition: 'Bagian dari Pendapatan terbayar yang berasal dari tagihan sekali bayar.',
                formula: 'SUM(invoices.paid_amount) untuk tagihan Pendapatan terbayar dengan type = one_time.',
                unit: MetricUnit::Currency,
                dateBasis: DateBasis::Period,
                sources: ['invoices.paid_amount', 'invoices.status', 'invoices.issue_date', 'invoices.type'],
                dateColumn: 'invoices.issue_date',
                statuses: ['paid'],
                caveats: [
                    'Dihitung menurut tanggal terbit tagihan, bukan tanggal pembayaran.',
                    $periodRule,
                    self::PACKAGE_CATEGORY_FILTER,
                ],
            ),
            new MetricDefinition(
                report: ReportType::Business,
                key: 'outstanding',
                label: 'Piutang',
                definition: 'Jumlah yang masih terutang pada tagihan yang sudah dikirim dan belum lunas.',
                formula: 'SUM(invoices.total - invoices.paid_amount) untuk tagihan dengan status sent, partial, atau overdue.',
                unit: MetricUnit::Currency,
                dateBasis: DateBasis::Snapshot,
                sources: ['invoices.total', 'invoices.paid_amount', 'invoices.status'],
                statuses: ['sent', 'partial', 'overdue'],
                caveats: [self::PACKAGE_CATEGORY_FILTER],
            ),
            new MetricDefinition(
                report: ReportType::Business,
                key: 'asset_distribution',
                label: 'Sebaran aset',
                definition: 'Jumlah aset jaringan pada setiap status.',
                formula: 'COUNT network_assets dikelompokkan menurut status.',
                unit: MetricUnit::Breakdown,
                dateBasis: DateBasis::Snapshot,
                sources: ['network_assets.status'],
            ),
            new MetricDefinition(
                report: ReportType::Business,
                key: 'sla_compliance_pct',
                label: 'Kepatuhan SLA',
                definition: 'Persentase tiket selesai yang diselesaikan paling lambat pada batas SLA-nya, sepanjang waktu.',
                formula: 'ROUND(100 * COUNT(tiket dengan resolved_at <= sla_deadline) / COUNT(tiket dengan resolved_at), 1); '
                    .'0 bila tidak ada tiket yang selesai.',
                unit: MetricUnit::Percent,
                dateBasis: DateBasis::Snapshot,
                sources: ['tickets.resolved_at', 'tickets.sla_deadline'],
                caveats: [
                    'Angka sepanjang waktu: mengabaikan Dari dan Sampai, berbeda dengan laporan Kepatuhan SLA (sla_rate).',
                    'Tiket tanpa batas SLA tetap masuk penyebut tetapi tidak pernah dianggap sesuai SLA.',
                ],
            ),
            new MetricDefinition(
                report: ReportType::Business,
                key: 'installation_count',
                label: 'Pemasangan selesai',
                definition: 'SPK pemasangan yang selesai dalam periode terpilih.',
                formula: 'COUNT work_orders dengan type = installation dan status = completed dan completed_at '
                    .'antara Dari 00:00:00 dan Sampai 23:59:59.',
                unit: MetricUnit::Count,
                dateBasis: DateBasis::Period,
                sources: ['work_orders.type', 'work_orders.status', 'work_orders.completed_at'],
                dateColumn: 'work_orders.completed_at',
                statuses: ['completed'],
                caveats: [
                    $periodRule,
                    'Menghitung SPK, berbeda dengan installation_count di Pemanfaatan Aset yang menghitung catatan pemasangan aset.',
                    self::PACKAGE_CATEGORY_FILTER,
                ],
            ),
        ];
    }

    /** @return list<MetricDefinition> */
    private static function sales(): array
    {
        $scope = 'Perusahaan, per petugas penjualan';
        $periodRule = 'Rentang berlaku bila Dari, Sampai, atau keduanya diisi (hanya Dari: sejak hari itu; hanya Sampai: sampai hari itu); bila kosong, semua data yang cocok dipakai.';
        $attribution = 'Mengikuti petugas penjualan pelanggan saat ini: mengoreksi petugas memindahkan seluruh riwayat pelanggan itu '
            .'ke petugas yang baru, sehingga angka periode lampau ikut berubah. Pelanggan tanpa petugas penjualan, atau yang petugasnya bukan '
            .'pengguna perusahaan, dilaporkan sebagai Tanpa petugas.';

        return [
            new MetricDefinition(
                report: ReportType::Sales,
                key: 'customers_acquired',
                label: 'Pelanggan baru',
                definition: 'Pelanggan atas nama petugas penjualan yang dibuat dalam periode terpilih.',
                formula: 'COUNT customers dengan acquired_by_user_id = petugas penjualan, deleted_at kosong, dan created_at '
                    .'antara Dari 00:00:00 dan Sampai 23:59:59.',
                unit: MetricUnit::Count,
                dateBasis: DateBasis::Period,
                sources: ['customers.acquired_by_user_id', 'customers.created_at', 'customers.deleted_at'],
                dateColumn: 'customers.created_at',
                scope: $scope,
                caveats: [
                    $attribution,
                    'Dihitung menurut tanggal pelanggan dibuat, bukan hari petugas ditetapkan: pelanggan hasil impor atau '
                        .'pengisian ulang dihitung pada hari mereka dibuat.',
                    'Pelanggan yang sudah dihapus tidak dihitung.',
                    $periodRule,
                ],
            ),
            new MetricDefinition(
                report: ReportType::Sales,
                key: 'subscriptions_activated',
                label: 'Langganan diaktifkan',
                definition: 'Langganan milik pelanggan petugas penjualan yang diaktifkan dalam periode terpilih.',
                formula: 'COUNT service_subscriptions milik pelanggan atas nama petugas penjualan dengan activation_date '
                    .'antara Dari dan Sampai (Sampai termasuk).',
                unit: MetricUnit::Count,
                dateBasis: DateBasis::Period,
                sources: [
                    'customers.acquired_by_user_id',
                    'service_subscriptions.customer_id',
                    'service_subscriptions.activation_date',
                ],
                dateColumn: 'service_subscriptions.activation_date',
                scope: $scope,
                caveats: [
                    $attribution,
                    'Menghitung langganan berstatus apa pun yang memiliki tanggal aktivasi: yang ditangguhkan dan berhenti tetap dihitung.',
                    'Langganan tanpa tanggal aktivasi (pending) tidak pernah dihitung.',
                    'Langganan milik pelanggan yang sudah dihapus tetap dihitung; langganan yang sudah dihapus tidak pernah dihitung.',
                    $periodRule,
                    self::PACKAGE_CATEGORY_FILTER,
                ],
            ),
            new MetricDefinition(
                report: ReportType::Sales,
                key: 'active_mrr',
                label: 'MRR aktif',
                definition: 'Pendapatan berulang bulanan pelanggan petugas penjualan: tagihan bulanan dari semua langganan '
                    .'yang aktif saat ini.',
                formula: 'SUM(service_subscriptions.mrc_amount) untuk langganan milik pelanggan atas nama petugas penjualan '
                    .'dengan status = active.',
                unit: MetricUnit::Currency,
                dateBasis: DateBasis::Snapshot,
                sources: [
                    'customers.acquired_by_user_id',
                    'service_subscriptions.mrc_amount',
                    'service_subscriptions.status',
                ],
                statuses: ['active'],
                scope: $scope,
                caveats: [
                    $attribution,
                    'Langganan milik pelanggan yang sudah dihapus tetap dihitung; langganan yang sudah dihapus tidak pernah dihitung.',
                    self::PACKAGE_CATEGORY_FILTER,
                ],
            ),
            new MetricDefinition(
                report: ReportType::Sales,
                key: 'invoiced',
                label: 'Ditagihkan',
                definition: 'Jumlah yang ditagihkan kepada pelanggan petugas penjualan dalam periode terpilih, tagihan draf '
                    .'dan dibatalkan tidak termasuk.',
                formula: 'SUM(invoices.total) untuk tagihan milik pelanggan atas nama petugas penjualan dengan status sent, '
                    .'partial, paid, atau overdue dan issue_date antara Dari dan Sampai (Sampai termasuk).',
                unit: MetricUnit::Currency,
                dateBasis: DateBasis::Period,
                sources: [
                    'customers.acquired_by_user_id',
                    'invoices.customer_id',
                    'invoices.total',
                    'invoices.status',
                    'invoices.issue_date',
                ],
                dateColumn: 'invoices.issue_date',
                statuses: ['sent', 'partial', 'paid', 'overdue'],
                scope: $scope,
                caveats: [
                    $attribution,
                    'Dihitung menurut tanggal terbit tagihan, bukan tanggal pembayaran.',
                    'Tagihan draf, dibatalkan, dan yang sudah dihapus tidak pernah dihitung; tagihan milik pelanggan yang sudah dihapus tetap dihitung.',
                    $periodRule,
                    self::PACKAGE_CATEGORY_FILTER,
                ],
            ),
            new MetricDefinition(
                report: ReportType::Sales,
                key: 'collected',
                label: 'Terkumpul',
                definition: 'Uang yang diterima dari pelanggan petugas penjualan dalam periode terpilih: semua pembayaran yang '
                    .'tidak dibatalkan.',
                formula: 'SUM(payments.amount) untuk pembayaran dengan cancelled_at kosong dan paid_at antara Dari 00:00:00 dan '
                    .'Sampai 23:59:59, pada tagihan milik pelanggan atas nama petugas penjualan.',
                unit: MetricUnit::Currency,
                dateBasis: DateBasis::Period,
                sources: [
                    'customers.acquired_by_user_id',
                    'invoices.customer_id',
                    'payments.invoice_id',
                    'payments.amount',
                    'payments.paid_at',
                    'payments.cancelled_at',
                ],
                dateColumn: 'payments.paid_at',
                scope: $scope,
                caveats: [
                    $attribution,
                    'Dihitung menurut tanggal pembayaran, bukan tanggal terbit tagihan: satu pembayaran bisa melunasi tagihan yang terbit sebelum rentang.',
                    'Pembayaran yang dibatalkan tidak pernah dihitung, kapan pun pembatalannya.',
                    'Pembayaran pada tagihan yang sudah dihapus tidak pernah dihitung; pembayaran pada tagihan milik pelanggan yang sudah dihapus tetap dihitung.',
                    'Tidak sama dengan Pendapatan terbayar di Metrik Bisnis, yang menjumlahkan paid_amount tagihan lunas menurut tanggal terbit '
                        .'dan mengabaikan pembayaran sebagian.',
                    $periodRule,
                    self::PACKAGE_CATEGORY_FILTER,
                ],
            ),
            new MetricDefinition(
                report: ReportType::Sales,
                key: 'outstanding',
                label: 'Piutang',
                definition: 'Jumlah yang masih terutang oleh pelanggan petugas penjualan pada tagihan yang sudah dikirim dan belum lunas.',
                formula: 'SUM(invoices.total - invoices.paid_amount) untuk tagihan milik pelanggan atas nama petugas penjualan '
                    .'dengan status sent, partial, atau overdue.',
                unit: MetricUnit::Currency,
                dateBasis: DateBasis::Snapshot,
                sources: [
                    'customers.acquired_by_user_id',
                    'invoices.customer_id',
                    'invoices.total',
                    'invoices.paid_amount',
                    'invoices.status',
                ],
                statuses: ['sent', 'partial', 'overdue'],
                scope: $scope,
                caveats: [
                    $attribution,
                    'Tagihan draf, dibatalkan, dan yang sudah dihapus tidak pernah menjadi piutang; tagihan milik pelanggan yang sudah dihapus tetap dihitung.',
                    'Bila dijumlahkan untuk semua petugas penjualan dan Tanpa petugas, hasilnya sama dengan Piutang di Metrik Bisnis.',
                    self::PACKAGE_CATEGORY_FILTER,
                ],
            ),
            new MetricDefinition(
                report: ReportType::Sales,
                key: 'collection_rate',
                label: 'Tingkat penagihan',
                definition: 'Terkumpul sebagai persentase dari Ditagihkan pada periode terpilih.',
                formula: 'ROUND(100 * Terkumpul / Ditagihkan, 2); kosong bila Ditagihkan bernilai 0.',
                unit: MetricUnit::Percent,
                dateBasis: DateBasis::Period,
                sources: [
                    'invoices.total',
                    'invoices.status',
                    'invoices.issue_date',
                    'payments.amount',
                    'payments.paid_at',
                    'payments.cancelled_at',
                ],
                dateColumn: 'payments.paid_at',
                statuses: ['sent', 'partial', 'paid', 'overdue'],
                scope: $scope,
                caveats: [
                    $attribution,
                    'Terkumpul dihitung menurut tanggal pembayaran dan Ditagihkan menurut tanggal terbit, sehingga keduanya mencakup tagihan '
                        .'yang berbeda dan persentasenya bisa melebihi 100 persen.',
                    'Kosong bila Ditagihkan bernilai 0, walaupun ada pembayaran masuk pada periode itu.',
                    $periodRule,
                    self::PACKAGE_CATEGORY_FILTER,
                ],
            ),
        ];
    }

    /** @return list<MetricDefinition> */
    private static function sla(): array
    {
        $periodRule = 'Rentang berlaku bila Dari, Sampai, atau keduanya diisi (hanya Dari: sejak hari itu; hanya Sampai: sampai hari itu); bila kosong, semua tiket selesai dipakai.';

        return [
            new MetricDefinition(
                report: ReportType::Sla,
                key: 'total_resolved',
                label: 'Tiket selesai',
                definition: 'Tiket yang selesai pada periode dan kategori terpilih.',
                formula: 'COUNT tiket dengan resolved_at terisi, resolved_at antara Dari 00:00:00 dan Sampai 23:59:59, '
                    .'dan category_id = kategori yang dipilih bila ada.',
                unit: MetricUnit::Count,
                dateBasis: DateBasis::Period,
                sources: ['tickets.resolved_at', 'tickets.category_id'],
                dateColumn: 'tickets.resolved_at',
                caveats: [$periodRule],
            ),
            new MetricDefinition(
                report: ReportType::Sla,
                key: 'sla_compliant',
                label: 'Sesuai SLA',
                definition: 'Tiket selesai yang memenuhi batas SLA-nya.',
                formula: 'COUNT tiket dari Tiket selesai dengan resolved_at <= sla_deadline.',
                unit: MetricUnit::Count,
                dateBasis: DateBasis::Period,
                sources: ['tickets.resolved_at', 'tickets.sla_deadline', 'tickets.category_id'],
                dateColumn: 'tickets.resolved_at',
                caveats: ['Tiket tanpa batas SLA tidak pernah dianggap sesuai SLA.', $periodRule],
            ),
            new MetricDefinition(
                report: ReportType::Sla,
                key: 'sla_rate',
                label: 'Tingkat kepatuhan SLA',
                definition: 'Persentase tiket selesai yang memenuhi batas SLA-nya.',
                formula: 'ROUND(100 * Sesuai SLA / Tiket selesai, 1); 0 bila Tiket selesai bernilai 0.',
                unit: MetricUnit::Percent,
                dateBasis: DateBasis::Period,
                sources: ['tickets.resolved_at', 'tickets.sla_deadline', 'tickets.category_id'],
                dateColumn: 'tickets.resolved_at',
                caveats: [
                    'Tiket tanpa batas SLA tetap masuk penyebut tetapi tidak pernah dianggap sesuai SLA.',
                    'Mengikuti periode terpilih; Kepatuhan SLA di Metrik Bisnis berlaku sepanjang waktu dan bisa berbeda.',
                    $periodRule,
                ],
            ),
            new MetricDefinition(
                report: ReportType::Sla,
                key: 'breach_count',
                label: 'Pelanggaran SLA',
                definition: 'Tiket selesai yang melewati batas SLA-nya.',
                formula: 'COUNT tiket dari Tiket selesai dengan resolved_at > sla_deadline.',
                unit: MetricUnit::Count,
                dateBasis: DateBasis::Period,
                sources: ['tickets.resolved_at', 'tickets.sla_deadline', 'tickets.category_id'],
                dateColumn: 'tickets.resolved_at',
                caveats: [
                    'Tiket tanpa batas SLA tidak dihitung sesuai maupun melanggar, '
                        .'sehingga Sesuai SLA ditambah Pelanggaran SLA bisa lebih kecil dari Tiket selesai.',
                    $periodRule,
                ],
            ),
            new MetricDefinition(
                report: ReportType::Sla,
                key: 'response_on_time',
                label: 'Respons tepat waktu',
                definition: 'Tiket selesai yang respons pertamanya masuk dalam target respons.',
                formula: 'COUNT tiket dari Tiket selesai dengan first_response_at <= response_deadline.',
                unit: MetricUnit::Count,
                dateBasis: DateBasis::Period,
                sources: ['tickets.resolved_at', 'tickets.first_response_at', 'tickets.response_deadline', 'tickets.category_id'],
                dateColumn: 'tickets.resolved_at',
                caveats: [
                    'Tiket tanpa target respons tidak pernah dihitung di sini; lihat Tanpa target respons.',
                    'Tiket selesai yang punya target tetapi belum mencatat respons pertama tidak dihitung tepat waktu maupun terlambat.',
                    $periodRule,
                ],
            ),
            new MetricDefinition(
                report: ReportType::Sla,
                key: 'response_breached',
                label: 'Respons terlambat',
                definition: 'Tiket selesai yang respons pertamanya melewati target respons.',
                formula: 'COUNT tiket dari Tiket selesai dengan first_response_at > response_deadline.',
                unit: MetricUnit::Count,
                dateBasis: DateBasis::Period,
                sources: ['tickets.resolved_at', 'tickets.first_response_at', 'tickets.response_deadline', 'tickets.category_id'],
                dateColumn: 'tickets.resolved_at',
                caveats: [
                    'Tiket tanpa target respons tidak pernah dihitung di sini; lihat Tanpa target respons.',
                    'Tiket selesai yang punya target tetapi belum mencatat respons pertama tidak dihitung tepat waktu maupun terlambat.',
                    $periodRule,
                ],
            ),
            new MetricDefinition(
                report: ReportType::Sla,
                key: 'response_without_target',
                label: 'Tanpa target respons',
                definition: 'Tiket selesai yang dibuat tanpa target respons.',
                formula: 'COUNT tiket dari Tiket selesai dengan response_deadline kosong.',
                unit: MetricUnit::Count,
                dateBasis: DateBasis::Period,
                sources: ['tickets.resolved_at', 'tickets.response_deadline', 'tickets.category_id'],
                dateColumn: 'tickets.resolved_at',
                caveats: [
                    'Setiap tiket yang dibuat sebelum target respons ada tidak memilikinya, sehingga tidak masuk Respons tepat waktu, '
                        .'Respons terlambat, dan Tingkat respons.',
                    $periodRule,
                ],
            ),
            new MetricDefinition(
                report: ReportType::Sla,
                key: 'response_rate',
                label: 'Tingkat respons',
                definition: 'Persentase tiket selesai yang dinilai dan respons pertamanya masuk dalam target respons.',
                formula: 'ROUND(100 * Respons tepat waktu / (Respons tepat waktu + Respons terlambat), 1); kosong bila keduanya 0.',
                unit: MetricUnit::Percent,
                dateBasis: DateBasis::Period,
                sources: ['tickets.resolved_at', 'tickets.first_response_at', 'tickets.response_deadline', 'tickets.category_id'],
                dateColumn: 'tickets.resolved_at',
                caveats: [
                    'Tiket tanpa target respons atau tanpa respons pertama yang tercatat tidak dinilai dan tidak masuk '
                        .'penyebut, berbeda dengan Tingkat kepatuhan SLA.',
                    'Kosong bila belum ada tiket selesai yang dinilai.',
                    $periodRule,
                ],
            ),
            new MetricDefinition(
                report: ReportType::Sla,
                key: 'avg_resolution_minutes',
                label: 'Rata-rata waktu penyelesaian',
                definition: 'Rata-rata menit dari tiket dibuat sampai selesai.',
                formula: 'ROUND(AVG(TIMESTAMPDIFF(MINUTE, created_at, resolved_at))) pada tiket dari Tiket selesai.',
                unit: MetricUnit::Minutes,
                dateBasis: DateBasis::Period,
                sources: ['tickets.created_at', 'tickets.resolved_at', 'tickets.category_id'],
                dateColumn: 'tickets.resolved_at',
                caveats: ['Kosong bila tidak ada tiket yang cocok.', $periodRule],
            ),
            new MetricDefinition(
                report: ReportType::Sla,
                key: 'avg_frt_minutes',
                label: 'Rata-rata respons pertama',
                definition: 'Rata-rata menit dari tiket dibuat sampai respons pertama.',
                formula: 'ROUND(AVG(TIMESTAMPDIFF(MINUTE, created_at, first_response_at))) pada tiket dari Tiket selesai '
                    .'yang memiliki first_response_at.',
                unit: MetricUnit::Minutes,
                dateBasis: DateBasis::Period,
                sources: ['tickets.created_at', 'tickets.first_response_at', 'tickets.category_id'],
                dateColumn: 'tickets.resolved_at',
                caveats: [
                    'Tiket tanpa respons pertama dilewati.',
                    'Kosong bila tidak ada tiket yang cocok yang memiliki respons pertama.',
                    $periodRule,
                ],
            ),
            new MetricDefinition(
                report: ReportType::Sla,
                key: 'by_category',
                label: 'Tiket selesai per kategori',
                definition: 'Tiket selesai dan kepatuhan SLA untuk setiap kategori tiket.',
                formula: 'Per kategori tiket (tickets.category_name_snapshot, atau ticket_categories.name selama tiket belum ditutup): '
                    .'COUNT tiket dengan resolved_at terisi, COUNT yang memiliki resolved_at <= sla_deadline, dan rate = ROUND(100 * compliant / total, 1).',
                unit: MetricUnit::Breakdown,
                dateBasis: DateBasis::Snapshot,
                sources: [
                    'tickets.category_id',
                    'tickets.category_name_snapshot',
                    'tickets.resolved_at',
                    'tickets.sla_deadline',
                    'ticket_categories.name',
                ],
                caveats: [
                    'Mengabaikan filter Dari/Sampai dan filter kategori, sehingga bisa berbeda dengan angka di atasnya '
                        .'untuk periode yang sama.',
                    'Kategori dengan nama yang sama digabung menjadi satu baris.',
                    self::TICKET_CATEGORY_NAME,
                ],
            ),
            new MetricDefinition(
                report: ReportType::Sla,
                key: 'by_technician',
                label: 'Tiket selesai per teknisi',
                definition: 'Tiket selesai pada periode dan kategori terpilih, dan kepatuhan SLA-nya, untuk setiap penangan tiket.',
                formula: 'Per penangan tiket (tickets.assigned_to; nama dari tickets.assignee_name_snapshot, atau users.name selama tiket belum ditutup): '
                    .'COUNT tiket dari Tiket selesai, COUNT yang memiliki resolved_at <= sla_deadline, COUNT yang memiliki resolved_at > sla_deadline, '
                    .'rate = ROUND(100 * compliant / total, 1), dan ROUND(AVG(TIMESTAMPDIFF(MINUTE, created_at, first_response_at))) pada tiket yang memiliki first_response_at.',
                unit: MetricUnit::Breakdown,
                dateBasis: DateBasis::Period,
                sources: [
                    'tickets.assigned_to',
                    'tickets.assignee_name_snapshot',
                    'users.name',
                    'tickets.resolved_at',
                    'tickets.sla_deadline',
                    'tickets.first_response_at',
                    'tickets.category_id',
                ],
                dateColumn: 'tickets.resolved_at',
                caveats: [
                    'Tiket yang belum punya penangan menjadi satu baris tersendiri.',
                    'Pengguna yang tidak boleh melihat semua teknisi hanya membaca nama teknisi dalam cakupan laporan Teknisi mereka; penangan lainnya digabung menjadi satu baris "Penangan lain", sehingga baris tetap berjumlah sama dengan Tiket selesai.',
                    'Tiket yang sudah ditutup memakai nama penangan saat ditutup, sehingga mengganti nama pengguna tidak memindahkan tiket lama.',
                    'Tiket tanpa batas SLA tidak pernah dianggap sesuai SLA maupun melanggar.',
                    $periodRule,
                ],
            ),
            new MetricDefinition(
                report: ReportType::Sla,
                key: 'by_priority',
                label: 'Tiket selesai per prioritas',
                definition: 'Tiket selesai pada periode dan kategori terpilih, dan kepatuhan SLA-nya, untuk setiap prioritas tiket.',
                formula: 'Per prioritas tiket (tickets.priority), dari yang paling mendesak: COUNT tiket dari Tiket selesai, COUNT yang memiliki resolved_at <= sla_deadline, '
                    .'COUNT yang memiliki resolved_at > sla_deadline, rate = ROUND(100 * compliant / total, 1), '
                    .'dan ROUND(AVG(TIMESTAMPDIFF(MINUTE, created_at, first_response_at))) pada tiket yang memiliki first_response_at.',
                unit: MetricUnit::Breakdown,
                dateBasis: DateBasis::Period,
                sources: [
                    'tickets.priority',
                    'tickets.resolved_at',
                    'tickets.sla_deadline',
                    'tickets.first_response_at',
                    'tickets.category_id',
                ],
                dateColumn: 'tickets.resolved_at',
                caveats: [
                    'Tiket berprioritas mendesak mendapat batas SLA setengah dari biasanya; batas itu sudah tersimpan pada tiket.',
                    'Tiket tanpa batas SLA tidak pernah dianggap sesuai SLA maupun melanggar.',
                    $periodRule,
                ],
            ),
            new MetricDefinition(
                report: ReportType::Sla,
                key: 'by_source',
                label: 'Tiket selesai per sumber target',
                definition: 'Tiket selesai pada periode dan kategori terpilih, dan kepatuhan SLA-nya, menurut asal target SLA tiket: tingkat SLA paket langganan, atau kategori tiket.',
                formula: 'Per tingkat SLA (tickets.subscription_id, service_subscriptions.service_package_version_id, service_package_versions.sla_tier_id, sla_tiers.name); '
                    .'tiket tanpa langganan menjadi satu baris yang targetnya dari kategori tiket. Setiap baris: COUNT tiket dari Tiket selesai, COUNT yang memiliki resolved_at <= sla_deadline, '
                    .'COUNT yang memiliki resolved_at > sla_deadline, rate = ROUND(100 * compliant / total, 1), '
                    .'dan ROUND(AVG(TIMESTAMPDIFF(MINUTE, created_at, first_response_at))) pada tiket yang memiliki first_response_at.',
                unit: MetricUnit::Breakdown,
                dateBasis: DateBasis::Period,
                sources: [
                    'tickets.subscription_id',
                    'service_subscriptions.service_package_version_id',
                    'service_package_versions.sla_tier_id',
                    'sla_tiers.name',
                    'tickets.resolved_at',
                    'tickets.sla_deadline',
                    'tickets.first_response_at',
                    'tickets.category_id',
                ],
                dateColumn: 'tickets.resolved_at',
                caveats: [
                    'Sumbernya dibaca dari langganan tiket saat laporan dibuka: bila langganan berpindah ke versi paket lain sesudah tiket dibuat, tiket lama ikut tingkat SLA yang baru, walau batas SLA-nya sudah tersimpan.',
                    'Nama tingkat SLA adalah nama saat ini: mengganti nama tingkat memindahkan tiket lama ke nama baru.',
                    'Tiket tanpa batas SLA tidak pernah dianggap sesuai SLA maupun melanggar.',
                    $periodRule,
                ],
            ),
            new MetricDefinition(
                report: ReportType::Sla,
                key: 'csat_average',
                label: 'Kepuasan pelanggan',
                definition: 'Rata-rata nilai 1 sampai 5 yang diberikan pelanggan pada tiket yang selesai.',
                formula: 'ROUND(AVG(tickets.customer_rating), 1) pada tiket dengan customer_rated_at antara Dari 00:00:00 dan Sampai 23:59:59 '
                    .'dan category_id = kategori yang dipilih bila ada.',
                unit: MetricUnit::Rating,
                dateBasis: DateBasis::Period,
                sources: ['tickets.customer_rating', 'tickets.customer_rated_at', 'tickets.category_id'],
                dateColumn: 'tickets.customer_rated_at',
                caveats: [
                    'Mengikuti hari penilaian, bukan hari tiket selesai: tiket yang selesai sebelum periode tetapi dinilai di dalamnya ikut dihitung.',
                    'Tiket yang belum dinilai tidak dihitung dan tidak dianggap bernilai nol.',
                    'Kosong bila belum ada penilaian pada periode itu.',
                    'Rentang berlaku bila Dari, Sampai, atau keduanya diisi (hanya Dari: sejak hari itu; hanya Sampai: sampai hari itu); bila kosong, semua penilaian dipakai.',
                ],
            ),
            new MetricDefinition(
                report: ReportType::Sla,
                key: 'csat_count',
                label: 'Jumlah penilaian pelanggan',
                definition: 'Jumlah tiket yang dinilai pelanggan pada periode dan kategori terpilih.',
                formula: 'COUNT tiket dengan customer_rated_at antara Dari 00:00:00 dan Sampai 23:59:59 dan category_id = kategori yang dipilih bila ada.',
                unit: MetricUnit::Count,
                dateBasis: DateBasis::Period,
                sources: ['tickets.customer_rated_at', 'tickets.category_id'],
                dateColumn: 'tickets.customer_rated_at',
                caveats: [
                    'Mengikuti hari penilaian, bukan hari tiket selesai.',
                    'Rentang berlaku bila Dari, Sampai, atau keduanya diisi (hanya Dari: sejak hari itu; hanya Sampai: sampai hari itu); bila kosong, semua penilaian dipakai.',
                ],
            ),
        ];
    }

    /** @return list<MetricDefinition> */
    private static function asset(): array
    {
        return [
            new MetricDefinition(
                report: ReportType::Asset,
                key: 'status_distribution',
                label: 'Sebaran status',
                definition: 'Jumlah aset jaringan pada setiap status.',
                formula: 'COUNT network_assets dikelompokkan menurut status, dibatasi filter Jenis aset dan lokasi.',
                unit: MetricUnit::Breakdown,
                dateBasis: DateBasis::Snapshot,
                sources: [
                    'network_assets.status',
                    'network_assets.asset_type',
                    'network_assets.location_id',
                ],
            ),
            new MetricDefinition(
                report: ReportType::Asset,
                key: 'by_location',
                label: 'Aset per lokasi',
                definition: 'Jumlah aset jaringan di setiap lokasi.',
                formula: 'COUNT network_assets yang memiliki lokasi, dikelompokkan menurut locations.name.',
                unit: MetricUnit::Breakdown,
                dateBasis: DateBasis::Snapshot,
                sources: ['network_assets.location_id', 'locations.name'],
                caveats: [
                    'Mengabaikan filter Jenis aset dan lokasi.',
                    'Lokasi dengan nama yang sama digabung menjadi satu baris.',
                ],
            ),
            new MetricDefinition(
                report: ReportType::Asset,
                key: 'installation_count',
                label: 'Pemasangan aset',
                definition: 'Pemasangan aset yang tercatat dalam periode terpilih.',
                formula: 'COUNT network_asset_installations dengan installed_at antara Dari 00:00:00 dan Sampai 23:59:59.',
                unit: MetricUnit::Count,
                dateBasis: DateBasis::Period,
                sources: ['network_asset_installations.installed_at'],
                dateColumn: 'network_asset_installations.installed_at',
                caveats: [
                    'Rentang berlaku bila Dari, Sampai, atau keduanya diisi (hanya Dari: sejak hari itu; hanya Sampai: sampai hari itu); bila kosong, semua pemasangan dihitung.',
                    'Mengabaikan filter Jenis aset dan lokasi.',
                    'Menghitung catatan pemasangan aset, berbeda dengan installation_count di Metrik Bisnis yang menghitung '
                        .'SPK pemasangan selesai.',
                ],
            ),
            new MetricDefinition(
                report: ReportType::Asset,
                key: 'customer_linked_count',
                label: 'Terhubung ke pelanggan',
                definition: 'Jumlah aset jaringan yang ditetapkan ke pelanggan.',
                formula: 'COUNT network_assets dengan customer_id tidak null.',
                unit: MetricUnit::Count,
                dateBasis: DateBasis::Snapshot,
                sources: ['network_assets.customer_id'],
                caveats: ['Mengabaikan semua filter.'],
            ),
            new MetricDefinition(
                report: ReportType::Asset,
                key: 'retired_count',
                label: 'Dipensiunkan',
                definition: 'Jumlah aset jaringan yang dipensiunkan saat ini.',
                formula: 'COUNT network_assets dengan status = retired.',
                unit: MetricUnit::Count,
                dateBasis: DateBasis::Snapshot,
                sources: ['network_assets.status'],
                statuses: ['retired'],
                caveats: [
                    'Total berjalan, bukan aset yang dipensiunkan dalam periode terpilih.',
                    'Mengabaikan semua filter.',
                ],
            ),
        ];
    }

    /** @return list<MetricDefinition> */
    private static function technician(): array
    {
        $scope = 'Perusahaan, teknisi terpilih';
        $periodRule = 'Rentang berlaku bila Dari, Sampai, atau keduanya diisi (hanya Dari: sejak hari itu; hanya Sampai: sampai hari itu); bila kosong, semua data yang cocok dipakai.';

        return [
            new MetricDefinition(
                report: ReportType::Technician,
                key: 'spk_completed',
                label: 'SPK selesai',
                definition: 'SPK yang diselesaikan teknisi dalam periode terpilih.',
                formula: 'COUNT work_orders yang ditugaskan ke teknisi dengan status = completed dan completed_at '
                    .'antara Dari 00:00:00 dan Sampai 23:59:59.',
                unit: MetricUnit::Count,
                dateBasis: DateBasis::Period,
                sources: ['work_orders.assigned_to', 'work_orders.status', 'work_orders.completed_at'],
                dateColumn: 'work_orders.completed_at',
                statuses: ['completed'],
                scope: $scope,
                caveats: [$periodRule],
            ),
            new MetricDefinition(
                report: ReportType::Technician,
                key: 'tickets_resolved',
                label: 'Tiket diselesaikan',
                definition: 'Tiket yang diselesaikan atau ditutup teknisi dalam periode terpilih.',
                formula: 'COUNT tickets yang ditugaskan ke teknisi dengan status resolved atau closed dan resolved_at '
                    .'antara Dari 00:00:00 dan Sampai 23:59:59.',
                unit: MetricUnit::Count,
                dateBasis: DateBasis::Period,
                sources: ['tickets.assigned_to', 'tickets.status', 'tickets.resolved_at'],
                dateColumn: 'tickets.resolved_at',
                statuses: ['resolved', 'closed'],
                scope: $scope,
                caveats: [$periodRule],
            ),
            new MetricDefinition(
                report: ReportType::Technician,
                key: 'avg_spk_minutes',
                label: 'Rata-rata waktu SPK',
                definition: 'Rata-rata menit dari mulai sampai selesai pada SPK yang selesai.',
                formula: 'ROUND(AVG(TIMESTAMPDIFF(MINUTE, started_at, completed_at))) pada work_orders SPK selesai '
                    .'yang memiliki started_at.',
                unit: MetricUnit::Minutes,
                dateBasis: DateBasis::Period,
                sources: ['work_orders.started_at', 'work_orders.completed_at'],
                dateColumn: 'work_orders.completed_at',
                statuses: ['completed'],
                scope: $scope,
                caveats: [
                    'SPK tanpa started_at dilewati.',
                    'Kosong bila tidak ada SPK yang cocok yang memiliki started_at.',
                    $periodRule,
                ],
            ),
            new MetricDefinition(
                report: ReportType::Technician,
                key: 'avg_ticket_minutes',
                label: 'Rata-rata waktu tiket',
                definition: 'Rata-rata menit dari tiket dibuat sampai selesai pada tiket yang diselesaikan.',
                formula: 'ROUND(AVG(TIMESTAMPDIFF(MINUTE, created_at, resolved_at))) pada Tiket diselesaikan '
                    .'yang memiliki resolved_at.',
                unit: MetricUnit::Minutes,
                dateBasis: DateBasis::Period,
                sources: ['tickets.created_at', 'tickets.resolved_at'],
                dateColumn: 'tickets.resolved_at',
                statuses: ['resolved', 'closed'],
                scope: $scope,
                caveats: ['Kosong bila tidak ada tiket yang cocok.', $periodRule],
            ),
            new MetricDefinition(
                report: ReportType::Technician,
                key: 'sla_compliance_pct',
                label: 'Kepatuhan SLA',
                definition: 'Persentase tiket yang diselesaikan teknisi dan memenuhi batas SLA-nya.',
                formula: 'ROUND(100 * COUNT(Tiket diselesaikan dengan resolved_at <= sla_deadline) / COUNT(Tiket diselesaikan), 1); '
                    .'0 bila tidak ada.',
                unit: MetricUnit::Percent,
                dateBasis: DateBasis::Period,
                sources: ['tickets.resolved_at', 'tickets.sla_deadline', 'tickets.status'],
                dateColumn: 'tickets.resolved_at',
                statuses: ['resolved', 'closed'],
                scope: $scope,
                caveats: [
                    'Menghitung tiket berstatus resolved atau closed; laporan Kepatuhan SLA menghitung setiap tiket '
                        .'dengan resolved_at, sehingga keduanya bisa berbeda.',
                    'Tiket tanpa batas SLA tetap masuk penyebut tetapi tidak pernah dianggap sesuai SLA.',
                    $periodRule,
                ],
            ),
            new MetricDefinition(
                report: ReportType::Technician,
                key: 'avg_frt_minutes',
                label: 'Rata-rata respons pertama',
                definition: 'Rata-rata menit dari tiket dibuat sampai respons pertama.',
                formula: 'ROUND(AVG(TIMESTAMPDIFF(MINUTE, created_at, first_response_at))) pada Tiket diselesaikan '
                    .'yang memiliki first_response_at.',
                unit: MetricUnit::Minutes,
                dateBasis: DateBasis::Period,
                sources: ['tickets.created_at', 'tickets.first_response_at'],
                dateColumn: 'tickets.resolved_at',
                statuses: ['resolved', 'closed'],
                scope: $scope,
                caveats: [
                    'Tiket tanpa respons pertama dilewati.',
                    'Kosong bila tidak ada tiket yang cocok yang memiliki respons pertama.',
                    $periodRule,
                ],
            ),
            new MetricDefinition(
                report: ReportType::Technician,
                key: 'avg_score',
                label: 'Rata-rata skor evaluasi',
                definition: 'Rata-rata skor evaluasi yang diterima teknisi dalam periode terpilih.',
                formula: 'ROUND(AVG(employee_evaluations.score), 1) untuk evaluasi teknisi dengan evaluated_at '
                    .'antara Dari 00:00:00 dan Sampai 23:59:59.',
                unit: MetricUnit::Rating,
                dateBasis: DateBasis::Period,
                sources: [
                    'employee_evaluations.employee_id',
                    'employee_evaluations.score',
                    'employee_evaluations.evaluated_at',
                ],
                dateColumn: 'employee_evaluations.evaluated_at',
                scope: $scope,
                caveats: ['Kosong bila tidak ada evaluasi pada periode terpilih.', $periodRule],
            ),
            new MetricDefinition(
                report: ReportType::Technician,
                key: 'avg_customer_rating',
                label: 'Rata-rata rating pelanggan',
                definition: 'Rata-rata rating pelanggan pada evaluasi teknisi di periode terpilih.',
                formula: 'ROUND(AVG(employee_evaluations.customer_rating), 1) pada evaluasi teknisi '
                    .'yang customer_rating-nya terisi.',
                unit: MetricUnit::Rating,
                dateBasis: DateBasis::Period,
                sources: [
                    'employee_evaluations.employee_id',
                    'employee_evaluations.customer_rating',
                    'employee_evaluations.evaluated_at',
                ],
                dateColumn: 'employee_evaluations.evaluated_at',
                scope: $scope,
                caveats: [
                    'Evaluasi tanpa rating pelanggan dilewati.',
                    'Kosong bila tidak ada evaluasi pada periode terpilih yang memiliki rating pelanggan.',
                    $periodRule,
                ],
            ),
            new MetricDefinition(
                report: ReportType::Technician,
                key: 'active_workload',
                label: 'Beban kerja aktif',
                definition: 'Pekerjaan terbuka yang sedang ditangani teknisi saat ini.',
                formula: 'COUNT work_orders yang ditugaskan ke teknisi dengan status assigned atau in_progress, ditambah '
                    .'COUNT tickets yang ditugaskan ke teknisi dengan status open, assigned, atau on_progress.',
                unit: MetricUnit::Count,
                dateBasis: DateBasis::Snapshot,
                sources: [
                    'work_orders.assigned_to',
                    'work_orders.status',
                    'tickets.assigned_to',
                    'tickets.status',
                ],
                statuses: ['assigned', 'in_progress', 'open', 'on_progress'],
                scope: $scope,
            ),
            new MetricDefinition(
                report: ReportType::Technician,
                key: 'spk_by_type',
                label: 'SPK per jenis',
                definition: 'SPK yang selesai dalam periode terpilih, dikelompokkan menurut jenis.',
                formula: 'COUNT work_orders yang ditugaskan ke teknisi dengan status = completed dan completed_at dalam '
                    .'rentang, dikelompokkan menurut work_orders.type.',
                unit: MetricUnit::Breakdown,
                dateBasis: DateBasis::Period,
                sources: ['work_orders.assigned_to', 'work_orders.status', 'work_orders.type', 'work_orders.completed_at'],
                dateColumn: 'work_orders.completed_at',
                statuses: ['completed'],
                scope: $scope,
                caveats: [$periodRule],
            ),
            new MetricDefinition(
                report: ReportType::Technician,
                key: 'tickets_by_category',
                label: 'Tiket per kategori',
                definition: 'Tiket yang diselesaikan atau ditutup dalam periode terpilih, dikelompokkan menurut kategori.',
                formula: 'COUNT tickets yang ditugaskan ke teknisi dengan status resolved atau closed dan resolved_at dalam '
                    .'rentang, dikelompokkan menurut kategori tiket (tickets.category_name_snapshot, atau ticket_categories.name selama tiket belum ditutup).',
                unit: MetricUnit::Breakdown,
                dateBasis: DateBasis::Period,
                sources: [
                    'tickets.assigned_to',
                    'tickets.status',
                    'tickets.resolved_at',
                    'tickets.category_id',
                    'tickets.category_name_snapshot',
                    'ticket_categories.name',
                ],
                dateColumn: 'tickets.resolved_at',
                statuses: ['resolved', 'closed'],
                scope: $scope,
                caveats: [
                    'Kategori dengan nama yang sama digabung menjadi satu baris.',
                    self::TICKET_CATEGORY_NAME,
                    $periodRule,
                ],
            ),
        ];
    }

    /** @return list<MetricDefinition> */
    private static function stockCard(): array
    {
        $scope = 'Perusahaan, barang terpilih';

        return [
            new MetricDefinition(
                report: ReportType::StockCard,
                key: 'movements',
                label: 'Pergerakan stok',
                definition: 'Buku besar pergerakan stok yang tercatat untuk barang terpilih.',
                formula: 'Baris stock_movements untuk barang tersebut, dapat dibatasi pada satu lokasi (sebagai asal atau '
                    .'tujuan) dan pada created_at antara Dari 00:00:00 dan Sampai 23:59:59; diurutkan dan dipaginasi oleh server.',
                unit: MetricUnit::Records,
                dateBasis: DateBasis::Period,
                sources: [
                    'stock_movements.product_id',
                    'stock_movements.from_location_id',
                    'stock_movements.to_location_id',
                    'stock_movements.created_at',
                ],
                dateColumn: 'stock_movements.created_at',
                scope: $scope,
                caveats: [
                    'Hanya menampilkan pergerakan yang tercatat: tidak ada saldo awal atau baris rekonsiliasi.',
                    'Rentang berlaku bila Dari, Sampai, atau keduanya diisi (hanya Dari: sejak hari itu; hanya Sampai: sampai hari itu); bila kosong, semua pergerakan ditampilkan.',
                ],
            ),
            new MetricDefinition(
                report: ReportType::StockCard,
                key: 'quantity',
                label: 'Jumlah',
                definition: 'Jumlah bertanda dari satu pergerakan, persis seperti yang dicatat Inventaris.',
                formula: 'stock_movements.quantity, ditampilkan apa adanya.',
                unit: MetricUnit::Quantity,
                dateBasis: DateBasis::Period,
                sources: ['stock_movements.quantity'],
                dateColumn: 'stock_movements.created_at',
                scope: $scope,
            ),
            new MetricDefinition(
                report: ReportType::StockCard,
                key: 'balance_after',
                label: 'Saldo',
                definition: 'Jumlah stok pada baris stok yang disentuh pergerakan (barang pada satu lokasi) tepat setelah '
                    .'pergerakan itu.',
                formula: 'stock_movements.balance_after, ditampilkan apa adanya; laporan tidak menghitung ulang.',
                unit: MetricUnit::Quantity,
                dateBasis: DateBasis::Period,
                sources: ['stock_movements.balance_after'],
                dateColumn: 'stock_movements.created_at',
                scope: $scope,
                caveats: [
                    'Disimpan saat Inventaris mencatat pergerakan dan tidak pernah dihitung ulang oleh laporan ini.',
                    'Saldo per baris stok, bukan total berjalan lintas lokasi atau lintas baris yang disaring.',
                ],
            ),
        ];
    }
}
