<?php

namespace App\Support;

use App\Services\Platform\PlatformAuditService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Modules\Inventory\Models\Product;
use Spatie\Activitylog\Models\Activity;

/**
 * What an audit row says, as the one Indonesian sentence every audit surface shows: the audit log page, its search and
 * export, the dashboard's recent activity and the platform's company page. A row is the pair (`log_name`,
 * `description`); a sentence is looked up by that pair, else composed from the noun of the log and the verb of the
 * description ("Tiket" + "ditugaskan"), else the pair itself with its underscores read as spaces. The `support` log is
 * worded by SupportActionText. Sentences carry no actor, no time and no id: those have their own columns, and the
 * subject is named by subjects().
 *
 * A property is only ever read to add a short, non-secret detail (a rating, a role name); a row without it reads as
 * the bare sentence, which is also what a search matches.
 */
final class AuditEventText
{
    /** What a log is about: the subject of a composed sentence. */
    private const NOUNS = [
        'announcement' => 'Pengumuman',
        'asset_port' => 'Port aset',
        'bandwidth_profile' => 'Profil bandwidth',
        'category' => 'Kategori barang',
        'company' => 'Perusahaan',
        'customer' => 'Pelanggan',
        'customer_address' => 'Alamat pelanggan',
        'customer_contact' => 'Kontak pelanggan',
        'document_type' => 'Jenis dokumen',
        'employee_evaluation' => 'Evaluasi karyawan',
        'employee_profile' => 'Karyawan',
        'integration' => 'Integrasi',
        'inventory' => 'Stok',
        'inventory_request' => 'Permintaan barang',
        'invoice' => 'Tagihan',
        'ip_address' => 'Alamat IP',
        'ip_prefix' => 'Prefiks IP',
        'ipam' => 'Alamat IP',
        'location' => 'Lokasi',
        'material_return_case' => 'Pengembalian material',
        'nas_device' => 'Router NAS',
        'network_account' => 'Akun jaringan',
        'network_asset' => 'Aset jaringan',
        'number_sequence' => 'Penomoran',
        'odn' => 'ODN',
        'odn_port' => 'Port ODN',
        'odn_splitter' => 'Splitter ODN',
        'organization_unit' => 'Unit organisasi',
        'payment_attempt' => 'Pembayaran online',
        'product' => 'Barang',
        'service_package' => 'Paket layanan',
        'service_package_category' => 'Kategori paket',
        'service_subscription' => 'Langganan',
        'sla_tier' => 'Tingkat SLA',
        'speed_profile' => 'Profil kecepatan',
        'ticket' => 'Tiket',
        'ticket_category' => 'Kategori tiket',
        'unit' => 'Satuan',
        'user' => 'Pengguna',
        'vehicle' => 'Kendaraan',
        'vlan' => 'VLAN',
        'whatsapp' => 'WhatsApp',
        'work_order' => 'SPK',
    ];

    /** What happened, as it reads after any noun above. */
    private const VERBS = [
        'activated' => 'diaktifkan',
        'approved' => 'disetujui',
        'approved_reserved' => 'disetujui dan stok dicadangkan',
        'assigned' => 'ditugaskan',
        'blocked' => 'diblokir',
        'cancelled' => 'dibatalkan',
        'closed' => 'ditutup',
        'completed_orchestrated' => 'diselesaikan',
        'created' => 'dibuat',
        'created_and_submitted' => 'dibuat dan diajukan',
        'created_draft' => 'dibuat sebagai draf',
        'created_from_spk' => 'dibuat dari SPK',
        'damaged' => 'ditandai rusak',
        'deactivated' => 'dinonaktifkan',
        'declared' => 'dideklarasikan',
        'deleted' => 'dihapus',
        'force_deleted' => 'dihapus permanen',
        'generated' => 'diterbitkan',
        'installed' => 'dipasang',
        'issued' => 'diserahkan',
        'onboarded' => 'didaftarkan',
        'preparing' => 'disiapkan',
        'provisioned' => 'disambungkan ke router',
        'published' => 'diterbitkan',
        'reactivated' => 'diaktifkan kembali',
        'ready_for_pickup' => 'siap diambil',
        'received_accepted' => 'diterima gudang',
        'received_rejected' => 'ditolak gudang',
        'rejected' => 'ditolak',
        'repaired' => 'selesai diperbaiki',
        'resolved' => 'diselesaikan',
        'restored' => 'dipulihkan',
        'retired' => 'dipensiunkan',
        'sent' => 'dikirim',
        'spawned_spk' => 'dibuatkan SPK',
        'started' => 'mulai dikerjakan',
        'status_changed' => 'berubah status',
        'submitted' => 'diajukan',
        'submitted_for_review' => 'diajukan untuk ditinjau',
        'suspended' => 'ditangguhkan',
        'terminated' => 'dihentikan',
        'updated' => 'diubah',
    ];

    /** Pairs whose wording is not "noun + verb", and the pairs no noun covers, as "log.description" => sentence. */
    private const SENTENCES = [
        // Security trail of a tenant's people.
        'security.login' => 'Masuk ke akun',
        'security.logout' => 'Keluar dari akun',
        'security.login.failed' => 'Percobaan masuk gagal',
        'security.lockout' => 'Masuk dikunci sementara karena terlalu banyak percobaan',
        'security.password.reset_requested' => 'Tautan atur ulang kata sandi diminta',
        'security.password.reset' => 'Kata sandi diatur ulang',
        'security.password.changed' => 'Kata sandi diubah',
        'security.api.token_issued' => 'Token aplikasi seluler dibuat',
        'security.api.tokens_revoked' => 'Token aplikasi seluler dicabut',
        'security.enumeration_suspected' => 'Pola akses mencurigakan terdeteksi',
        'security.two_factor.enrollment_started' => 'Pendaftaran 2FA dimulai',
        'security.two_factor.enabled' => '2FA diaktifkan',
        'security.two_factor.disabled' => '2FA dimatikan',
        'security.two_factor.challenge_passed' => 'Kode 2FA benar',
        'security.two_factor.challenge_failed' => 'Kode 2FA salah',
        'security.two_factor.recovery_code_used' => 'Kode pemulihan 2FA dipakai',
        'security.two_factor.recovery_codes_regenerated' => 'Kode pemulihan 2FA dibuat ulang',
        'security.two_factor.admin_reset' => '2FA diatur ulang oleh admin',
        'security.role.created' => 'Peran dibuat',
        'security.role.updated' => 'Peran diubah',
        'security.role.deleted' => 'Peran dihapus',
        'security.role.attached' => 'Peran ditambahkan ke pengguna',
        'security.role.detached' => 'Peran dicabut dari pengguna',
        'export.export.csv' => 'Data diekspor ke CSV',
        'export.export.pdf' => 'Data diekspor ke PDF',
        'auth.admin_role_assigned' => 'Admin diberi peran',

        // Company, customers, team.
        'company.company_created' => 'Perusahaan dibuat',
        'company.company_switched' => 'Pindah ke perusahaan lain',
        'company.membership_granted' => 'Pengguna diberi akses ke perusahaan',
        'company.membership_revoked' => 'Akses pengguna ke perusahaan dicabut',
        'company.subsidiary_created' => 'Anak perusahaan dibuat',
        'company.subsidiary_attached' => 'Perusahaan dipasang sebagai anak perusahaan',
        'company.subsidiary_detached' => 'Perusahaan dilepas dari induk',
        'customer.acquirer_assigned' => 'Penjual pelanggan ditetapkan',
        'customer.acquirer_corrected' => 'Penjual pelanggan dikoreksi',
        'customer.document_uploaded' => 'Dokumen pelanggan diunggah',
        'customer.document_deleted' => 'Dokumen pelanggan dihapus',
        'whatsapp.opted_in' => 'Pelanggan setuju menerima WhatsApp',
        'whatsapp.opted_out' => 'Pelanggan berhenti menerima WhatsApp',
        'whatsapp.opted_out_by_reply' => 'Pelanggan berhenti menerima WhatsApp lewat balasan',
        'integration.imported_from_env' => 'Integrasi diimpor dari pengaturan server',
        'integration.saved' => 'Pengaturan integrasi disimpan',
        'integration.tested' => 'Uji koneksi integrasi berhasil',
        'integration.test_failed' => 'Uji koneksi integrasi gagal',
        'announcement.image_purged' => 'Gambar promo dibersihkan',
        'service_subscription.package_changed' => 'Paket langganan diganti',
        'service_subscription.unblocked' => 'Blokir langganan dibuka',

        // Tickets and work orders.
        'ticket.reopened' => 'Tiket dibuka kembali',
        'ticket.customer_confirmed' => 'Pelanggan mengonfirmasi tiket',
        'ticket.attachment_uploaded' => 'Lampiran tiket diunggah',
        'ticket.attachment_removed' => 'Lampiran tiket dihapus',
        'ticket.check_in' => 'Teknisi check-in di lokasi tiket',
        'work_order.rejected' => 'SPK dikembalikan untuk diperbaiki',
        'work_order.check_in' => 'Teknisi check-in di lokasi SPK',
        'work_order.en_route' => 'Teknisi berangkat ke lokasi SPK',
        'work_order.evidence_uploaded' => 'Bukti SPK diunggah',
        'work_order.evidence_removed' => 'Bukti SPK dihapus',
        'work_order.item_recorded' => 'Material SPK dicatat',
        'work_order.item_removed' => 'Material SPK dihapus',
        'work_order.item_usage_cleared' => 'Pemakaian material SPK dikosongkan',
        'work_order.stock_consumed' => 'Stok dipakai oleh SPK',
        'work_order.stock_consumption_skipped_ir_backed' => 'Stok SPK tidak dipotong karena memakai permintaan barang',

        // Billing.
        'invoice.payment_recorded' => 'Pembayaran dicatat',
        'invoice.payment_reversed' => 'Pembayaran dibatalkan',
        'invoice.recurring_generated' => 'Tagihan berulang diterbitkan',
        'invoice.reminder_sent' => 'Pengingat tagihan dikirim',
        'invoice.draft_updated' => 'Draf tagihan diubah',
        'payment_attempt.created' => 'Pembayaran online dibuat',
        'payment_attempt.pending' => 'Pembayaran online menunggu pembayaran',
        'payment_attempt.notified' => 'Midtrans memperbarui status pembayaran',
        'payment_attempt.settled' => 'Pembayaran online lunas',
        'payment_attempt.refunded' => 'Pembayaran online dikembalikan',
        'payment_attempt.failed' => 'Pembayaran online gagal',
        'payment_attempt.expired' => 'Pembayaran online kedaluwarsa',
        'payment_attempt.exception' => 'Pembayaran online perlu ditinjau',
        'payment_attempt.cancelled_locally' => 'Pembayaran online dibatalkan',
        'payment_attempt.callback_received' => 'Midtrans mengirim kabar pembayaran',
        'payment_attempt.cancel_requested' => 'Pembatalan ke Midtrans diminta',

        // Inventory.
        'inventory.stock_received' => 'Stok diterima',
        'inventory.stock_issued' => 'Stok dikeluarkan',
        'inventory.stock_transfer_out' => 'Stok dipindahkan keluar',
        'inventory.stock_transfer_in' => 'Stok dipindahkan masuk',
        'inventory.stock_adjustment' => 'Stok disesuaikan',
        'inventory.stock_adjustment_requested' => 'Penyesuaian stok diajukan',
        'inventory.stock_adjustment_rejected' => 'Penyesuaian stok ditolak',
        'inventory.stock_movement' => 'Stok dipindahkan',

        // Network.
        'nas_device.created' => 'Router NAS didaftarkan',
        'nas_device.secret_changed' => 'Secret RADIUS router diganti',
        'nas_device.radius_failed' => 'Penulisan router ke RADIUS gagal',
        'nas_device.radius_written' => 'Router ditulis ke RADIUS',
        'nas_device.sync_requested' => 'Sinkronisasi router ke RADIUS diminta',
        'network_account.auto_create_skipped' => 'Akun jaringan otomatis tidak dibuat',
        'network_account.disconnected' => 'Sesi akun jaringan diputus',
        'network_account.drift_corrected' => 'Perbedaan akun dengan RADIUS dikoreksi',
        'network_account.provision_failed' => 'Penulisan akun ke RADIUS gagal',
        'network_account.provisioned' => 'Akun jaringan ditulis ke RADIUS',
        'network_account.secret_changed' => 'Kata sandi akun jaringan diganti',
        'network_account.secret_revealed' => 'Kata sandi akun jaringan dilihat',
        'network_account.secret_rotated' => 'Kata sandi akun jaringan diputar ulang',
        'network_account.disabled' => 'Akun jaringan dinonaktifkan',
        'network_asset.maintenance' => 'Aset jaringan masuk perawatan',
        'network_asset.resumed' => 'Aset jaringan kembali beroperasi',
        'network_asset.removed' => 'Aset jaringan dilepas',
        'network_asset.panel_applied' => 'Panel aset diterapkan',
        'network_asset.port_linked' => 'Port aset disambungkan',
        'network_asset.port_unlinked' => 'Port aset dilepas',
        'network_asset.rack_placed' => 'Perangkat dipasang di rak',
        'network_asset.rack_unplaced' => 'Perangkat dilepas dari rak',
        'odn.pon_ports_created' => 'Port PON dibuat',
        'odn.pon_port_deleted' => 'Port PON dihapus',
        'odn.splitter_created' => 'Splitter dibuat',
        'odn.splitter_connected' => 'Splitter disambungkan',
        'odn.splitter_disconnected' => 'Splitter dilepas',
        'odn.splitter_deleted' => 'Splitter dihapus',
        'odn.drop_assigned' => 'Kabel drop dipasang ke pelanggan',
        'odn.drop_released' => 'Kabel drop dilepas',
        'ipam.address_assigned' => 'Alamat IP ditetapkan',
        'ipam.address_reserved' => 'Alamat IP dicadangkan',
        'ipam.address_released' => 'Alamat IP dilepas',
        'ipam.asset_ips_imported' => 'Alamat IP aset diimpor',

        // The platform's own log: what the operator reads about a company or itself.
        'platform.admin.created' => 'Operator dibuat',
        'platform.auth.login' => 'Operator masuk',
        'platform.auth.logout' => 'Operator keluar',
        'platform.auth.idle_timeout' => 'Sesi operator berakhir',
        'platform.login.failed' => 'Operator gagal masuk',
        'platform.lockout' => 'Masuk operator diblokir',
        'platform.two_factor.challenge_passed' => 'Kode 2FA benar',
        'platform.two_factor.enrollment_started' => 'Pendaftaran 2FA dimulai',
        'platform.two_factor.enabled' => '2FA diaktifkan',
        'platform.two_factor.disabled' => '2FA dimatikan',
        'platform.two_factor.challenge_failed' => 'Kode 2FA salah',
        'platform.two_factor.recovery_code_used' => 'Kode pemulihan dipakai',
        'platform.two_factor.recovery_codes_regenerated' => 'Kode pemulihan dibuat ulang',
        'platform.role.created' => 'Peran dibuat',
        'platform.role.updated' => 'Peran diubah',
        'platform.role.deleted' => 'Peran dihapus',
        'platform.role.attached' => 'Peran ditambahkan ke pengguna',
        'platform.role.detached' => 'Peran dicabut dari pengguna',
        'platform.role_template.created' => 'Templat peran dibuat',
        'platform.role_template.updated' => 'Templat peran diubah',
        'platform.role_template.deleted' => 'Templat peran dihapus',
        'platform.tenant.provisioned' => 'Perusahaan dibuat',
        'platform.tenant.subsidiary_created' => 'Anak perusahaan dibuat',
        'platform.tenant.subsidiary_requested' => 'Anak perusahaan diminta',
        'platform.tenant.subsidiary_request_closed' => 'Permintaan anak perusahaan ditutup',
        'platform.tenant.suspended' => 'Perusahaan ditangguhkan',
        'platform.tenant.reactivated' => 'Perusahaan diaktifkan kembali',
        'platform.tenant.deletion_requested' => 'Permintaan hapus data dicatat',
        'platform.tenant.demo_data_seeded' => 'Data contoh terisi',
        'platform.tenant.demo_data_failed' => 'Data contoh gagal diisi',
        'platform.tenant.export_requested' => 'Ekspor data diminta',
        'platform.tenant.export_completed' => 'Ekspor data selesai',
        'platform.tenant.export_failed' => 'Ekspor data gagal',
        'platform.tenant.export_expired' => 'Ekspor data kedaluwarsa',
        'platform.tenant.export_downloaded' => 'Ekspor data diunduh',
        'platform.tenant.purge_started' => 'Penghapusan data dimulai',
        'platform.tenant.purge_failed' => 'Penghapusan data gagal',
        'platform.tenant.purge_completed' => 'Penghapusan data selesai',
        'platform.user.two_factor_reset' => '2FA pengguna diatur ulang',
        'platform.user.invitation_resent' => 'Undangan dikirim ulang',
        'platform.user.invitation_failed' => 'Undangan gagal terkirim',
        'platform.user.login_unlocked' => 'Kunci masuk dibuka',
        'platform.impersonation.started' => 'Akses dukungan dimulai',
        'platform.impersonation.mode_changed' => 'Mode akses dukungan diubah',
        'platform.impersonation.write' => 'Data diubah saat akses dukungan',
        'platform.impersonation.ended' => 'Akses dukungan berakhir',
    ];

    /** What the Midtrans `transaction_status` of a callback means to the person reading it. */
    private const CALLBACK_STATUS = [
        'settlement' => 'lunas',
        'capture' => 'lunas',
        'pending' => 'menunggu pembayaran',
        'expire' => 'kedaluwarsa',
        'cancel' => 'dibatalkan',
        'deny' => 'ditolak',
        'failure' => 'gagal',
        'refund' => 'dikembalikan',
        'partial_refund' => 'sebagian dikembalikan',
        'chargeback' => 'dibantah pemilik kartu',
    ];

    private const REMINDER_CHANNELS = [
        'mail' => 'email',
        'whatsapp' => 'WhatsApp',
        'fcm' => 'notifikasi aplikasi',
        'database' => 'notifikasi aplikasi',
    ];

    /** The attributes of a subject that name it to a person, first one present wins. */
    private const LABEL_ATTRIBUTES = ['code', 'number', 'order_id', 'username', 'name', 'title'];

    /** The properties a row keeps for a subject that was deleted since, same order. */
    private const LABEL_PROPERTIES = ['code', 'number', 'asset_code', 'order_id', 'username', 'name'];

    /** What a detail taken from a property may be at most: a role name or a supplier is short, free text is not. */
    private const DETAIL_LIMIT = 80;

    /** @var array<string, list<string>>|null */
    private static ?array $pairs = null;

    /**
     * @param  array<string, mixed>  $properties
     */
    public static function describe(?string $logName, string $description, array $properties = []): string
    {
        $log = (string) $logName;

        return self::detailed($log, $description, $properties)
            ?? self::known($log, $description)
            ?? self::fallback($log, $description);
    }

    /**
     * The sentence of a stored row: the support team's rows are worded by SupportActionText, any other row by
     * describe(). $properties false leaves a row's properties out, for a reader that may not see them.
     */
    public static function text(Activity $row, bool $withProperties = true): string
    {
        if ($row->log_name === PlatformAuditService::SUPPORT_LOG) {
            return SupportActionText::for($row);
        }

        return self::describe($row->log_name, (string) $row->description, $withProperties ? ($row->properties?->toArray() ?? []) : []);
    }

    /**
     * What each row is about, as a person would name it ("REQ-2026-00001"), keyed by the row's id; a row that has
     * nothing to name is left out. One query per subject type and one for the products of stock rows. The label
     * comes from the subject, else from the properties the row kept, else from its product.
     *
     * @param  Collection<int, Activity>  $rows
     * @return array<int, string>
     */
    public static function subjects(Collection $rows): array
    {
        $rows = $rows instanceof EloquentCollection ? $rows : new EloquentCollection($rows->all());
        self::loadSubjects($rows);

        $labels = [];
        $products = [];

        foreach ($rows as $row) {
            $properties = $row->properties?->toArray() ?? [];
            $label = self::modelLabel($row) ?? self::propertyLabel($properties);

            if ($label !== null) {
                $labels[(int) $row->getKey()] = $label;
            } elseif (is_numeric($properties['product_id'] ?? null)) {
                $products[(int) $row->getKey()] = (int) $properties['product_id'];
            }
        }

        if ($products !== []) {
            $names = Product::query()->withTrashed()->whereIn('id', array_values(array_unique($products)))->pluck('name', 'id');

            foreach ($products as $rowId => $productId) {
                if ($names->has($productId)) {
                    $labels[$rowId] = (string) $names->get($productId);
                }
            }
        }

        return $labels;
    }

    /**
     * Puts the label of each row on it as `subject_label` (null when it has none), for a reader of the page, an export or a feed.
     *
     * @param  EloquentCollection<int, Activity>  $rows
     */
    public static function annotate(EloquentCollection $rows): void
    {
        $labels = self::subjects($rows);

        foreach ($rows as $row) {
            $row->setAttribute('subject_label', $labels[(int) $row->getKey()] ?? null);
        }
    }

    /**
     * The subject of every row whose type is still a model, read once per type; a deleted subject keeps its id.
     *
     * @param  EloquentCollection<int, Activity>  $activities
     */
    private static function loadSubjects(EloquentCollection $activities): void
    {
        (new EloquentCollection($activities->filter(fn (Activity $activity): bool => is_string($activity->subject_type) && is_subclass_of($activity->subject_type, Model::class))->all()))
            ->loadMissing(['subject' => fn ($subject) => $subject->withTrashed()]);
    }

    /**
     * The (log, descriptions) pairs whose sentence, without any detail, contains $term: what a search for visible
     * text means. The `support` rows are searched through SupportActionText and the platform log is no tenant's.
     *
     * @return array<string, list<string>>
     */
    public static function codesMatching(string $term): array
    {
        $needle = mb_strtolower(trim($term));

        if ($needle === '') {
            return [];
        }

        $found = [];

        foreach (self::pairs() as $log => $descriptions) {
            foreach ($descriptions as $description) {
                if (str_contains(mb_strtolower(self::describe($log, $description)), $needle)) {
                    $found[$log][] = $description;
                }
            }
        }

        return $found;
    }

    /** @return array<string, list<string>> every pair the dictionary words, by log */
    private static function pairs(): array
    {
        if (self::$pairs !== null) {
            return self::$pairs;
        }

        $pairs = [];

        foreach (array_keys(self::SENTENCES) as $key) {
            [$log, $description] = explode('.', $key, 2);
            $pairs[$log][] = $description;
        }

        foreach (array_keys(self::NOUNS) as $log) {
            foreach (array_keys(self::VERBS) as $description) {
                $pairs[$log][] = $description;
            }
        }

        unset($pairs['platform']);

        return self::$pairs = array_map(fn (array $descriptions): array => array_values(array_unique($descriptions)), $pairs);
    }

    private static function known(string $log, string $description): ?string
    {
        if (isset(self::SENTENCES[$log.'.'.$description])) {
            return self::SENTENCES[$log.'.'.$description];
        }

        if ($log === PlatformAuditService::SUPPORT_LOG) {
            return 'Akses dukungan oleh tim Inbils';
        }

        if (isset(self::NOUNS[$log], self::VERBS[$description])) {
            return self::NOUNS[$log].' '.self::VERBS[$description];
        }

        return null;
    }

    /** "inventory_request: foo bar": a pair nobody worded yet still says what it is, in the log's own words. */
    private static function fallback(string $log, string $description): string
    {
        return str_replace('_', ' ', $log === '' ? 'aktivitas' : $log).': '.str_replace('_', ' ', $description);
    }

    /**
     * The sentence of a pair with the one detail its properties add, or null when the pair has none or the row does
     * not carry it.
     *
     * @param  array<string, mixed>  $properties
     */
    private static function detailed(string $log, string $description, array $properties): ?string
    {
        $base = self::known($log, $description);

        return match ($log.'.'.$description) {
            'ticket.reopened' => match ($properties['by'] ?? null) {
                'customer' => 'Tiket dibuka kembali oleh pelanggan',
                'staff' => 'Tiket dibuka kembali oleh staf',
                default => null,
            },
            'ticket.customer_confirmed' => is_numeric($properties['rating'] ?? null)
                ? 'Pelanggan memberi nilai '.(int) $properties['rating'].' dari 5'.(($properties['closed'] ?? false) === true ? ' dan tiket ditutup' : '')
                : (($properties['closed'] ?? false) === true ? 'Pelanggan mengonfirmasi dan tiket ditutup' : null),
            'payment_attempt.callback_received' => self::callbackStatus($properties['transaction_status'] ?? null, $base),
            'invoice.reminder_sent' => self::reminderChannels($properties['channels'] ?? null, $base),
            'inventory.stock_received' => self::detail($properties['supplier_name'] ?? null, $base, 'dari'),
            'security.role.attached', 'security.role.detached' => self::roles($properties['roles'] ?? null, $description === 'role.attached'),
            'security.role.created', 'security.role.updated', 'security.role.deleted' => is_string($properties['name'] ?? null) && trim($properties['name']) !== ''
                ? 'Peran '.Str::limit(trim($properties['name']), self::DETAIL_LIMIT, '').' '.substr((string) $base, strlen('Peran '))
                : null,
            'platform.tenant.subsidiary_requested' => is_string($properties['name'] ?? null) && trim($properties['name']) !== ''
                ? 'Anak perusahaan '.Str::limit(trim($properties['name']), self::DETAIL_LIMIT, '').' diminta'
                : null,
            'platform.tenant.subsidiary_request_closed' => self::closedRequest($properties),
            default => null,
        };
    }

    private static function callbackStatus(mixed $status, ?string $base): ?string
    {
        if (! is_string($status) || $status === '') {
            return null;
        }

        return $base.': '.(self::CALLBACK_STATUS[$status] ?? str_replace('_', ' ', Str::limit($status, self::DETAIL_LIMIT, '')));
    }

    private static function reminderChannels(mixed $channels, ?string $base): ?string
    {
        $names = collect(is_array($channels) ? $channels : [$channels])
            ->filter(fn (mixed $channel): bool => is_string($channel) && $channel !== '')
            ->map(fn (string $channel): string => self::REMINDER_CHANNELS[$channel] ?? str_replace('_', ' ', $channel))
            ->unique()
            ->values();

        return $names->isEmpty() ? null : $base.' lewat '.$names->implode(', ');
    }

    /** "Peran Admin, Teknisi ditambahkan ke pengguna": the names of the roles of the row, when it kept them. */
    private static function roles(mixed $roles, bool $attached): ?string
    {
        $names = collect(is_array($roles) ? $roles : [])
            ->filter(fn (mixed $role): bool => is_string($role) && $role !== '')
            ->map(fn (string $role): string => Str::limit($role, self::DETAIL_LIMIT, ''));

        if ($names->isEmpty()) {
            return null;
        }

        return 'Peran '.$names->implode(', ').($attached ? ' ditambahkan ke pengguna' : ' dicabut dari pengguna');
    }

    /** @param  array<string, mixed>  $properties */
    private static function closedRequest(array $properties): ?string
    {
        $name = is_string($properties['name'] ?? null) && $properties['name'] !== '' ? ' '.Str::limit($properties['name'], self::DETAIL_LIMIT, '') : '';
        $outcome = match ($properties['outcome'] ?? null) {
            'created' => 'dibuat',
            'rejected' => 'ditolak',
            default => null,
        };

        return $name === '' && $outcome === null ? null : 'Permintaan anak perusahaan'.$name.' '.($outcome ?? 'ditutup');
    }

    /** "$base $connector $value", or null when the row has no text value to add. */
    private static function detail(mixed $value, ?string $base, string $connector): ?string
    {
        if ($base === null || ! is_string($value) || trim($value) === '') {
            return null;
        }

        return $base.' '.$connector.' '.Str::limit(trim($value), self::DETAIL_LIMIT, '');
    }

    private static function modelLabel(Activity $row): ?string
    {
        $subject = $row->relationLoaded('subject') ? $row->getRelation('subject') : null;

        if (! $subject instanceof Model) {
            return null;
        }

        foreach (self::LABEL_ATTRIBUTES as $attribute) {
            $value = $subject->getAttribute($attribute);

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    /** @param  array<string, mixed>  $properties */
    private static function propertyLabel(array $properties): ?string
    {
        foreach (self::LABEL_PROPERTIES as $key) {
            $value = $properties[$key] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }
}
