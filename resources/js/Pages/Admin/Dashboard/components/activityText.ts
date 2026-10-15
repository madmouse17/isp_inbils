import type { DashboardActivityItem } from '@/types/dashboard';

/** What an activity is about, by the `log_name` the server writes (the model or module that logged it). */
const SUBJECT: Record<string, string> = {
    bandwidth_profile: 'Profil bandwidth',
    category: 'Kategori barang',
    company: 'Perusahaan',
    customer: 'Pelanggan',
    customer_address: 'Alamat pelanggan',
    customer_contact: 'Kontak pelanggan',
    document_type: 'Jenis dokumen',
    employee_evaluation: 'Evaluasi karyawan',
    employee_profile: 'Karyawan',
    inventory: 'Stok',
    inventory_request: 'Permintaan barang',
    invoice: 'Tagihan',
    ip_address: 'Alamat IP',
    ip_prefix: 'Prefiks IP',
    ipam: 'Alamat IP',
    location: 'Lokasi',
    material_return_case: 'Pengembalian material',
    nas_device: 'Perangkat NAS',
    network_account: 'Akun jaringan',
    network_asset: 'Aset jaringan',
    number_sequence: 'Penomoran',
    odn: 'ODN',
    odn_port: 'Port ODN',
    odn_splitter: 'Splitter ODN',
    organization_unit: 'Organisasi',
    product: 'Barang',
    service_package: 'Paket layanan',
    service_package_category: 'Kategori paket',
    service_subscription: 'Langganan',
    sla_tier: 'Tingkat SLA',
    speed_profile: 'Profil kecepatan',
    ticket: 'Tiket',
    ticket_category: 'Kategori tiket',
    unit: 'Satuan',
    user: 'Pengguna',
    vehicle: 'Kendaraan',
    vlan: 'VLAN',
    whatsapp: 'WhatsApp',
    work_order: 'SPK',
};

/** What happened, by the `description` the server writes. A word not listed shows as it was written. */
const EVENT: Record<string, string> = {
    activated: 'diaktifkan',
    approved: 'disetujui',
    approved_reserved: 'disetujui dan stok dicadangkan',
    assigned: 'ditugaskan',
    attachment_removed: 'lampiran dihapus',
    attachment_uploaded: 'lampiran diunggah',
    blocked: 'diblokir',
    cancelled: 'dibatalkan',
    check_in: 'check-in tercatat',
    closed: 'ditutup',
    completed_orchestrated: 'diselesaikan',
    created: 'dibuat',
    created_from_spk: 'dibuat dari SPK',
    damaged: 'ditandai rusak',
    declared: 'dideklarasikan',
    deleted: 'dihapus',
    evidence_removed: 'bukti dihapus',
    evidence_uploaded: 'bukti diunggah',
    generated: 'diterbitkan',
    installed: 'dipasang',
    issued: 'diserahkan',
    item_recorded: 'item dicatat',
    item_removed: 'item dihapus',
    onboarded: 'didaftarkan',
    payment_recorded: 'pembayaran dicatat',
    payment_reversed: 'pembayaran dibatalkan',
    preparing: 'disiapkan',
    provisioned: 'disambungkan ke router',
    reactivated: 'diaktifkan kembali',
    ready_for_pickup: 'siap diambil',
    rejected: 'ditolak',
    repaired: 'selesai diperbaiki',
    resolved: 'diselesaikan',
    retired: 'dipensiunkan',
    sent: 'dikirim',
    spawned_spk: 'dibuatkan SPK',
    started: 'mulai dikerjakan',
    status_changed: 'status diubah',
    submitted: 'diajukan',
    submitted_for_review: 'diajukan untuk review',
    suspended: 'ditangguhkan',
    terminated: 'dihentikan',
    unblocked: 'blokir dibuka',
    updated: 'diubah',
};

/**
 * One line for an activity of the dashboard: "Tiket ditugaskan". A `log_name` or `description` that has no
 * wording here shows as written, with the underscores read as spaces: the server may log more than this knows.
 */
export function activityText({ log_name, description }: DashboardActivityItem): string {
    // hasOwn: a logged name such as `constructor` must not read the object's own members.
    const known = (words: Record<string, string>, key: string) =>
        Object.hasOwn(words, key) ? words[key] : key.replaceAll('_', ' ');
    const subject = log_name ? known(SUBJECT, log_name) : 'Aktivitas';

    return `${subject} ${known(EVENT, description)}`;
}
