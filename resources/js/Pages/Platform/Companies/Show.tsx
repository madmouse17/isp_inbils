import { useMemo } from 'react';
import { AlertTriangle, ShieldCheck } from 'lucide-react';
import PlatformLayout from '@/Layouts/PlatformLayout';
import { DataTable, PageHeader, type DataTableColumn } from '@/Components/composite';
import {
    Alert,
    AlertDescription,
    AlertTitle,
    Badge,
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
    EnumBadge,
} from '@/Components/ui';
import { formatDateTime, formatNumber } from '@/lib/format';
import { formatBytes } from '@/lib/platform';
import {
    ACTIVE_STATE,
    INTEGRATION_PROVIDER,
    INTEGRATION_STATE,
    PLATFORM_TENANT_STATUS,
    TWO_FACTOR_STATE,
} from '@/lib/statuses';
import type {
    PlatformCompanyShowProps,
    PlatformCompanyUser,
    PlatformTenantIntegration,
} from '@/types/platform';
import { LifecycleActions } from './components/LifecycleActions';
import { SubsidiariesSection } from './components/SubsidiariesSection';
import { UsersSection } from './components/UsersSection';
import { ActivitySections } from './components/ActivitySections';
import { ExportsSection } from './components/ExportsSection';

export default function Show({
    tenant,
    metrics,
    admins,
    subsidiaries,
    users,
    userFilters,
    recentAudit,
    failedJobs,
    failedWhatsApp,
    integrations,
    exports,
    lifecycle,
}: PlatformCompanyShowProps) {
    const adminColumns = useMemo<DataTableColumn<PlatformCompanyUser>[]>(
        () => [
            {
                key: 'name',
                header: 'Admin',
                cell: (admin) => (
                    <div>
                        <p className="font-medium">{admin.name}</p>
                        <p className="text-xs text-muted-foreground">{admin.email}</p>
                    </div>
                ),
            },
            {
                key: 'company',
                header: 'Perusahaan',
                cell: (admin) => admin.company.code,
            },
            {
                key: 'is_active',
                header: 'Status',
                cell: (admin) => <EnumBadge map={ACTIVE_STATE} value={admin.is_active} />,
            },
            {
                key: 'two_factor_enabled',
                header: '2FA',
                cell: (admin) => (
                    <EnumBadge
                        map={TWO_FACTOR_STATE}
                        value={admin.two_factor_enabled ? 'enabled' : 'off'}
                    />
                ),
            },
            {
                key: 'last_login_at',
                header: 'Masuk terakhir',
                cell: (admin) =>
                    admin.last_login_at ? (
                        formatDateTime(admin.last_login_at)
                    ) : admin.invitation_pending ? (
                        <Badge variant="warning">Undangan belum dipakai</Badge>
                    ) : (
                        <span className="text-muted-foreground">Belum pernah</span>
                    ),
            },
        ],
        [],
    );
    const integrationColumns = useMemo<DataTableColumn<PlatformTenantIntegration>[]>(
        () => [
            {
                key: 'company',
                header: 'Perusahaan',
                cell: (row) => (
                    <div>
                        <p className="font-medium">{row.company.code}</p>
                        <p className="text-xs text-muted-foreground">{row.company.name}</p>
                    </div>
                ),
            },
            {
                key: 'provider',
                header: 'Layanan',
                cell: (row) => INTEGRATION_PROVIDER[row.provider].label,
            },
            {
                key: 'state',
                header: 'Status',
                cell: (row) => <EnumBadge map={INTEGRATION_STATE} value={row.state} />,
            },
            {
                key: 'is_enabled',
                header: 'Aktif',
                cell: (row) => (row.is_enabled ? 'Ya' : 'Tidak'),
            },
            {
                key: 'last_checked_at',
                header: 'Terakhir diuji',
                cell: (row) =>
                    row.last_checked_at ? formatDateTime(row.last_checked_at) : 'Belum pernah',
            },
        ],
        [],
    );
    const profile = [
        ['Kode', tenant.code],
        ['Email', tenant.email],
        ['Telepon', tenant.phone],
        ['Situs web', tenant.website],
        ['Alamat', tenant.address],
        ['Zona waktu', tenant.timezone],
        ['Mata uang', tenant.currency],
        ['Dibuat', tenant.created_at ? formatDateTime(tenant.created_at) : null],
        ['Ditangguhkan', tenant.suspended_at ? formatDateTime(tenant.suspended_at) : null],
        [
            'Pengajuan hapus',
            tenant.deletion_requested_at ? formatDateTime(tenant.deletion_requested_at) : null,
        ],
    ] as const;
    const summary = [
        ['Anak perusahaan', formatNumber(metrics.subsidiaries_count)],
        ['Pengguna', formatNumber(metrics.users_count)],
        ['Pelanggan', formatNumber(metrics.customers_count)],
        [
            'Masuk terakhir',
            metrics.last_login_at ? formatDateTime(metrics.last_login_at) : 'Belum pernah',
        ],
        ['Penyimpanan', formatBytes(metrics.storage_bytes)],
        ['Job gagal 7 hari', formatNumber(metrics.failed_jobs_7d)],
        ['WhatsApp gagal 7 hari', formatNumber(metrics.failed_whatsapp_7d)],
    ] as const;

    return (
        <PlatformLayout title={tenant.name}>
            <PageHeader
                title={tenant.name}
                subtitle={`Kode ${tenant.code}`}
                breadcrumbs={[
                    { label: 'Dasbor', href: route('platform.dashboard') },
                    { label: tenant.name },
                ]}
                actions={<LifecycleActions tenant={tenant} lifecycle={lifecycle} />}
            />

            {tenant.status === 'suspended' ? (
                <Alert variant="warning">
                    <AlertTriangle className="size-4" aria-hidden="true" />
                    <AlertTitle>Perusahaan ditangguhkan</AlertTitle>
                    <AlertDescription>
                        Pengguna tidak dapat masuk. Penangguhan dimulai{' '}
                        {tenant.suspended_at
                            ? formatDateTime(tenant.suspended_at)
                            : 'pada waktu yang tidak tercatat'}
                        .
                    </AlertDescription>
                </Alert>
            ) : null}
            {tenant.status === 'deletion_requested' ? (
                <Alert variant="danger">
                    <AlertTriangle className="size-4" aria-hidden="true" />
                    <AlertTitle>Pengajuan hapus tercatat</AlertTitle>
                    <AlertDescription>
                        Belum ada data yang dihapus. Aktifkan kembali perusahaan untuk membatalkan
                        pengajuan.
                    </AlertDescription>
                </Alert>
            ) : null}

            <div className="grid gap-6 xl:grid-cols-[1.1fr_0.9fr]">
                <Card>
                    <CardHeader className="gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <CardTitle>Profil perusahaan</CardTitle>
                            <CardDescription>
                                Identitas dan pengaturan dasar tenant.
                            </CardDescription>
                        </div>
                        <EnumBadge map={PLATFORM_TENANT_STATUS} value={tenant.status} />
                    </CardHeader>
                    <CardContent>
                        <dl className="grid gap-x-6 gap-y-4 text-sm sm:grid-cols-2">
                            {profile.map(([label, value]) => (
                                <div key={label}>
                                    <dt className="text-muted-foreground">{label}</dt>
                                    <dd className="mt-1 font-medium break-words">
                                        {value ?? <span className="text-muted-foreground">-</span>}
                                    </dd>
                                </div>
                            ))}
                        </dl>
                    </CardContent>
                </Card>

                <div className="space-y-6">
                    <Card>
                        <CardHeader>
                            <CardTitle>Ringkasan</CardTitle>
                            <CardDescription>
                                Kondisi tenant dan kegagalan dalam 7 hari terakhir.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <dl className="grid gap-x-5 gap-y-4 text-sm sm:grid-cols-2">
                                {summary.map(([label, value]) => {
                                    const danger =
                                        (label === 'Job gagal 7 hari' &&
                                            metrics.failed_jobs_7d > 0) ||
                                        (label === 'WhatsApp gagal 7 hari' &&
                                            metrics.failed_whatsapp_7d > 0);

                                    return (
                                        <div key={label}>
                                            <dt className="text-muted-foreground">{label}</dt>
                                            <dd
                                                className={
                                                    danger
                                                        ? 'mt-1 text-xl font-semibold text-destructive-text'
                                                        : 'mt-1 text-xl font-semibold'
                                                }
                                            >
                                                {value}
                                            </dd>
                                        </div>
                                    );
                                })}
                            </dl>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Status perusahaan</CardTitle>
                            <CardDescription>
                                Penangguhan dapat dibalik. Penghapusan permanen tidak dilakukan dari
                                panel web.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="flex items-center gap-2">
                                <ShieldCheck
                                    className="size-5 text-muted-foreground"
                                    aria-hidden="true"
                                />
                                <EnumBadge map={PLATFORM_TENANT_STATUS} value={tenant.status} />
                            </div>
                            {tenant.status !== 'active' &&
                            lifecycle.deletion_blockers.length > 0 ? (
                                <div id="deletion-blockers">
                                    <p className="text-sm font-medium">Syarat pengajuan hapus</p>
                                    <ul className="space-y-1 mt-2 list-disc pl-5 text-sm text-muted-foreground">
                                        {lifecycle.deletion_blockers.map((blocker) => (
                                            <li key={blocker}>{blocker}</li>
                                        ))}
                                    </ul>
                                </div>
                            ) : null}
                        </CardContent>
                    </Card>
                </div>
            </div>

            <Card>
                <CardHeader>
                    <CardTitle>Admin perusahaan</CardTitle>
                    <CardDescription>
                        Maksimal 25 admin ditampilkan untuk kontak dan akses awal anak perusahaan.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <DataTable
                        columns={adminColumns}
                        rows={admins}
                        emptyTitle="Belum ada admin"
                        emptyDescription="Perusahaan ini belum mempunyai pengguna dengan peran admin."
                        itemLabel="admin"
                    />
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Integrasi</CardTitle>
                    <CardDescription>
                        Akun layanan milik tiap perusahaan. Hanya status dan waktu uji yang tampil,
                        tidak pernah isinya.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <DataTable
                        columns={integrationColumns}
                        rows={integrations}
                        getRowId={(row) => `${row.company.id}:${row.provider}`}
                        emptyTitle="Belum ada integrasi"
                        itemLabel="integrasi"
                    />
                </CardContent>
            </Card>
            <SubsidiariesSection tenant={tenant} admins={admins} subsidiaries={subsidiaries} />
            <UsersSection tenant={tenant} users={users} filters={userFilters} />
            <ActivitySections
                tenant={tenant}
                audit={recentAudit}
                failedJobs={failedJobs}
                failedWhatsApp={failedWhatsApp}
            />
            <ExportsSection tenant={tenant} exports={exports} />
        </PlatformLayout>
    );
}
