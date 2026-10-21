<?php

namespace Modules\Reporting\Exports;

use Modules\Reporting\Drill\DrillKind;
use Modules\Reporting\Registry\MetricUnit;

/**
 * The words of a report file: what the screen says (the status maps of resources/js/lib/statuses, which
 * ExportLabelsTest holds these constants equal to), where the stored value is a code such as `on_progress` or
 * `receive`. A value no map lists is written as it is, like `labelOf` does on the screen.
 */
final class ExportLabels
{
    /** `AUDIT_MODULE` plus the support log, which the page adds to it. */
    public const MODULES = [
        'customer' => 'Pelanggan',
        'customer_address' => 'Alamat pelanggan',
        'customer_contact' => 'Kontak pelanggan',
        'service_subscription' => 'Langganan',
        'billing' => 'Tagihan',
        'invoice' => 'Dokumen tagihan',
        'payment_attempt' => 'Pembayaran online',
        'inventory' => 'Inventaris',
        'inventory_request' => 'Permintaan barang',
        'material_return_case' => 'Pengembalian material',
        'product' => 'Barang',
        'category' => 'Kategori barang',
        'unit' => 'Satuan',
        'spk' => 'SPK',
        'work_order' => 'Perintah kerja',
        'ticket' => 'Tiket',
        'ticket_category' => 'Kategori tiket',
        'user' => 'Pengguna',
        'auth' => 'Autentikasi',
        'security' => 'Keamanan',
        'company' => 'Perusahaan',
        'location' => 'Lokasi',
        'network_asset' => 'Aset jaringan',
        'network_account' => 'Akun jaringan',
        'nas_device' => 'Perangkat NAS',
        'odn' => 'ODN',
        'odn_port' => 'Port ODN',
        'odn_splitter' => 'Splitter ODN',
        'ipam' => 'IPAM',
        'ip_address' => 'Alamat IP',
        'ip_prefix' => 'Prefiks IP',
        'vlan' => 'VLAN',
        'organization' => 'Organisasi',
        'organization_unit' => 'Unit organisasi',
        'employee_profile' => 'Profil karyawan',
        'employee_evaluation' => 'Evaluasi karyawan',
        'vehicle' => 'Kendaraan',
        'document_type' => 'Jenis dokumen',
        'service_package' => 'Paket layanan',
        'service_package_category' => 'Kategori paket',
        'bandwidth_profile' => 'Profil bandwidth',
        'speed_profile' => 'Profil kecepatan',
        'sla_tier' => 'Tingkat SLA',
        'number_sequence' => 'Penomoran',
        'whatsapp' => 'WhatsApp',
        'integration' => 'Integrasi',
        'approval' => 'Persetujuan',
        'setting' => 'Pengaturan',
        'export' => 'Ekspor',
        'support' => 'Akses dukungan',
    ];

    /** `STOCK_MOVEMENT_TYPE` */
    public const MOVEMENT_TYPES = [
        'receive' => 'Masuk',
        'issue' => 'Keluar',
        'transfer' => 'Transfer',
        'adjustment' => 'Penyesuaian',
        'reserve' => 'Dicadangkan',
        'release' => 'Cadangan dilepas',
        'return' => 'Pengembalian',
    ];

    /** `NETWORK_ASSET_STATUS` */
    public const ASSET_STATUS = [
        'available' => 'Tersedia',
        'installed' => 'Terpasang',
        'maintenance' => 'Perawatan',
        'damaged' => 'Rusak',
        'retired' => 'Dipensiunkan',
    ];

    /** `NETWORK_ASSET_TYPE` */
    public const ASSET_TYPE = [
        'router' => 'Router',
        'switch' => 'Switch',
        'olt' => 'OLT',
        'onu_ont' => 'ONU/ONT',
        'radio' => 'Radio',
        'antenna' => 'Antena',
        'fiber' => 'Fiber',
        'odp' => 'ODP',
        'odc' => 'ODC',
        'rack' => 'Rak',
        'power' => 'Perangkat daya',
        'other' => 'Lainnya',
    ];

    /** `SUBSCRIPTION_STATUS` */
    public const SUBSCRIPTION_STATUS = [
        'pending' => 'Menunggu',
        'active' => 'Aktif',
        'suspended' => 'Ditangguhkan',
        'terminated' => 'Berhenti',
    ];

    /** `INVOICE_STATUS` */
    public const INVOICE_STATUS = [
        'draft' => 'Draf',
        'sent' => 'Belum dibayar',
        'partial' => 'Dibayar sebagian',
        'paid' => 'Lunas',
        'overdue' => 'Lewat jatuh tempo',
        'cancelled' => 'Dibatalkan',
    ];

    /** `INVOICE_TYPE` */
    public const INVOICE_TYPE = [
        'one_time' => 'Sekali bayar',
        'recurring' => 'Berlangganan',
    ];

    /** `TICKET_STATUS` */
    public const TICKET_STATUS = [
        'open' => 'Terbuka',
        'assigned' => 'Ditugaskan',
        'on_progress' => 'Dikerjakan',
        'resolved' => 'Diselesaikan',
        'closed' => 'Ditutup',
    ];

    /** `WORK_ORDER_STATUS` */
    public const WORK_ORDER_STATUS = [
        'draft' => 'Draf',
        'generated' => 'Menunggu penugasan',
        'assigned' => 'Ditugaskan',
        'in_progress' => 'Dikerjakan',
        'waiting_review' => 'Menunggu review',
        'completed' => 'Selesai',
        'rejected' => 'Dikembalikan',
        'cancelled' => 'Dibatalkan',
    ];

    /** `WORK_ORDER_TYPE` */
    public const WORK_ORDER_TYPE = [
        'installation' => 'Instalasi',
        'maintenance' => 'Perawatan',
        'upgrade_service' => 'Peningkatan layanan',
        'relocation' => 'Pindah lokasi',
    ];

    /** `PRIORITY` */
    public const PRIORITY = [
        'low' => 'Rendah',
        'medium' => 'Sedang',
        'high' => 'Tinggi',
        'urgent' => 'Mendesak',
    ];

    /** What a figure is counted in, for the `Satuan` column. A currency other than rupiah is named by its code. */
    public static function unit(MetricUnit $unit, ?string $currency = null): string
    {
        return match ($unit) {
            MetricUnit::Currency => $currency === null || $currency === 'IDR' ? 'Rupiah' : $currency,
            MetricUnit::Count => 'Jumlah',
            MetricUnit::Percent => 'Persen',
            MetricUnit::Minutes => 'Menit',
            MetricUnit::Rating => 'Nilai (1-5)',
            MetricUnit::Quantity => 'Kuantitas',
            MetricUnit::Breakdown => 'Rincian',
            MetricUnit::Records => 'Baris',
        };
    }

    /** The entry of a breakdown as the screen names it: an asset status or an SPK type reads in words, a name stays. */
    public static function detail(string $metric, string $entry): string
    {
        return match ($metric) {
            'asset_distribution', 'status_distribution' => self::of(self::ASSET_STATUS, $entry),
            'spk_by_type' => self::of(self::WORK_ORDER_TYPE, $entry),
            default => $entry,
        };
    }

    /**
     * The name of a row of a breakdown as the screen says it: a handler (none yet), a priority or a target source in
     * words, any other name (a category) as it is.
     */
    public static function rowName(string $metric, ?string $name): string
    {
        return match ($metric) {
            'by_technician' => $name ?? 'Belum ditugaskan',
            'by_priority' => self::priority($name),
            'by_source' => $name === null ? 'Target kategori tiket' : 'Tingkat SLA '.$name,
            default => (string) $name,
        };
    }

    /** The module of an audit entry: `Umum` for a row that has none, like the page. */
    public static function module(?string $log): string
    {
        return $log === null || $log === '' ? 'Umum' : self::of(self::MODULES, $log);
    }

    public static function movementType(?string $type): string
    {
        return self::of(self::MOVEMENT_TYPES, $type);
    }

    public static function priority(?string $priority): string
    {
        return self::of(self::PRIORITY, $priority);
    }

    public static function assetType(?string $type): string
    {
        return self::of(self::ASSET_TYPE, $type);
    }

    /** One cell of a drill-down file: the status, type and asset type of a row in words, everything else as it is. */
    public static function drillCell(DrillKind $kind, string $key, mixed $value): mixed
    {
        if (! is_string($value) || $value === '') {
            return $value;
        }

        return match ($key) {
            'status' => match ($kind) {
                DrillKind::Subscription => self::of(self::SUBSCRIPTION_STATUS, $value),
                DrillKind::Invoice => self::of(self::INVOICE_STATUS, $value),
                DrillKind::Ticket => self::of(self::TICKET_STATUS, $value),
                DrillKind::WorkOrder => self::of(self::WORK_ORDER_STATUS, $value),
                DrillKind::Asset => self::of(self::ASSET_STATUS, $value),
                default => $value,
            },
            'type' => match ($kind) {
                DrillKind::Invoice => self::of(self::INVOICE_TYPE, $value),
                DrillKind::WorkOrder => self::of(self::WORK_ORDER_TYPE, $value),
                default => $value,
            },
            'asset_type' => self::of(self::ASSET_TYPE, $value),
            default => $value,
        };
    }

    /** @param  array<string, string>  $map */
    private static function of(array $map, ?string $value): string
    {
        return $value === null ? '' : ($map[$value] ?? $value);
    }
}
