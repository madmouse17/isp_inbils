import type { StatusMap } from './types';

/** Status of a top-level tenant in the operator panel. */
export const PLATFORM_TENANT_STATUS = {
    active: { label: 'Aktif', tone: 'success' },
    suspended: { label: 'Ditangguhkan', tone: 'warning' },
    deletion_requested: { label: 'Pengajuan hapus', tone: 'danger' },
} satisfies StatusMap;

/** State of a tenant data export. */
export const TENANT_EXPORT_STATUS = {
    queued: { label: 'Antrean', tone: 'neutral' },
    running: { label: 'Berjalan', tone: 'info' },
    ready: { label: 'Siap diunduh', tone: 'success' },
    failed: { label: 'Gagal', tone: 'danger' },
    expired: { label: 'Kedaluwarsa', tone: 'neutral' },
} satisfies StatusMap;

/** Operator and tenant-visible audit channels. */
export const PLATFORM_LOG = {
    platform: { label: 'Platform', tone: 'neutral' },
    support: { label: 'Akses dukungan', tone: 'warning' },
} satisfies StatusMap;

/**
 * What the platform and support logs record, as `description` stores it. The audit page filters by these codes,
 * so every code the backend writes under those two logs is listed.
 */
export const PLATFORM_AUDIT_ACTION = {
    'admin.created': { label: 'Operator dibuat', tone: 'success' },
    'auth.login': { label: 'Operator masuk', tone: 'neutral' },
    'auth.logout': { label: 'Operator keluar', tone: 'neutral' },
    'auth.idle_timeout': { label: 'Sesi operator berakhir', tone: 'warning' },
    'login.failed': { label: 'Operator gagal masuk', tone: 'warning' },
    lockout: { label: 'Masuk operator diblokir', tone: 'danger' },
    'two_factor.challenge_passed': { label: 'Kode 2FA benar', tone: 'success' },
    'two_factor.enrollment_started': { label: 'Pendaftaran 2FA dimulai', tone: 'info' },
    'two_factor.enabled': { label: '2FA diaktifkan', tone: 'success' },
    'two_factor.challenge_failed': { label: 'Kode 2FA salah', tone: 'warning' },
    'two_factor.recovery_code_used': { label: 'Kode pemulihan dipakai', tone: 'warning' },
    'two_factor.recovery_codes_regenerated': { label: 'Kode pemulihan dibuat ulang', tone: 'info' },
    'role.created': { label: 'Peran dibuat', tone: 'success' },
    'role.updated': { label: 'Peran diubah', tone: 'info' },
    'role.deleted': { label: 'Peran dihapus', tone: 'danger' },
    'tenant.provisioned': { label: 'Perusahaan dibuat', tone: 'success' },
    'tenant.subsidiary_created': { label: 'Anak perusahaan dibuat', tone: 'success' },
    'tenant.suspended': { label: 'Perusahaan ditangguhkan', tone: 'warning' },
    'tenant.reactivated': { label: 'Perusahaan diaktifkan', tone: 'success' },
    'tenant.deletion_requested': { label: 'Pengajuan hapus dicatat', tone: 'danger' },
    'tenant.demo_data_seeded': { label: 'Data contoh terisi', tone: 'success' },
    'tenant.demo_data_failed': { label: 'Data contoh gagal', tone: 'danger' },
    'tenant.export_requested': { label: 'Ekspor diminta', tone: 'info' },
    'tenant.export_completed': { label: 'Ekspor selesai', tone: 'success' },
    'tenant.export_failed': { label: 'Ekspor gagal', tone: 'danger' },
    'tenant.export_expired': { label: 'Ekspor kedaluwarsa', tone: 'neutral' },
    'tenant.export_downloaded': { label: 'Ekspor diunduh', tone: 'info' },
    'tenant.purge_started': { label: 'Penghapusan dimulai', tone: 'warning' },
    'tenant.purge_failed': { label: 'Penghapusan gagal', tone: 'danger' },
    'tenant.purge_completed': { label: 'Penghapusan selesai', tone: 'neutral' },
    'user.two_factor_reset': { label: '2FA pengguna diatur ulang', tone: 'warning' },
    'user.invitation_resent': { label: 'Undangan dikirim ulang', tone: 'info' },
    'user.login_unlocked': { label: 'Kunci masuk dibuka', tone: 'info' },
    'impersonation.started': { label: 'Akses dukungan dimulai', tone: 'warning' },
    'impersonation.mode_changed': { label: 'Mode akses diubah', tone: 'warning' },
    'impersonation.write': { label: 'Perubahan saat akses dukungan', tone: 'warning' },
    'impersonation.ended': { label: 'Akses dukungan berakhir', tone: 'neutral' },
} satisfies StatusMap;
