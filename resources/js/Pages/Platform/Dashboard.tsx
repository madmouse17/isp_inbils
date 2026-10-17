import { useMemo } from 'react';
import { Link } from '@inertiajs/react';
import { AlertTriangle, Building2, PauseCircle, Plus, Trash2 } from 'lucide-react';
import PlatformLayout from '@/Layouts/PlatformLayout';
import { DataTable, PageHeader, type DataTableColumn } from '@/Components/composite';
import {
    Alert,
    AlertDescription,
    Button,
    Card,
    CardContent,
    EnumBadge,
    Input,
    NativeSelect,
    StatCard,
} from '@/Components/ui';
import { useServerTable } from '@/hooks/useServerTable';
import { formatDateTime, formatNumber } from '@/lib/format';
import { formatBytes } from '@/lib/platform';
import { PLATFORM_TENANT_STATUS, optionsOf } from '@/lib/statuses';
import { toPagination } from '@/lib/pagination';
import type {
    PlatformTenantFilters,
    PlatformTenantRow,
    PlatformTenantSummary,
} from '@/types/platform';
import type { Paginated } from '@/types';

interface DashboardProps {
    tenants: Paginated<PlatformTenantRow>;
    filters: PlatformTenantFilters;
    summary: PlatformTenantSummary;
}

const STATUS_OPTIONS = [{ value: '', label: 'Semua status' }, ...optionsOf(PLATFORM_TENANT_STATUS)];

export default function Dashboard({ tenants, filters, summary }: DashboardProps) {
    const { params, set, setMany, sortBy, sortDir, onSort, processing } = useServerTable({
        url: route('platform.dashboard'),
        filters,
        only: ['tenants', 'filters', 'summary'],
    });
    const hasFilters = Boolean(params.search || params.status);
    const createButton = (
        <Button asChild leftIcon={<Plus className="size-4" aria-hidden="true" />}>
            <Link href={route('platform.companies.create')}>Perusahaan baru</Link>
        </Button>
    );

    const columns = useMemo<DataTableColumn<PlatformTenantRow>[]>(
        () => [
            {
                key: 'name',
                header: 'Perusahaan',
                sortable: true,
                sortKey: 'name',
                cell: (tenant) => (
                    <Link
                        href={route('platform.companies.show', tenant.id)}
                        className="font-medium text-primary hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-hidden"
                    >
                        {tenant.name}
                    </Link>
                ),
            },
            { key: 'code', header: 'Kode', sortable: true, sortKey: 'code' },
            {
                key: 'status',
                header: 'Status',
                cell: (tenant) => <EnumBadge map={PLATFORM_TENANT_STATUS} value={tenant.status} />,
            },
            {
                key: 'subsidiaries_count',
                header: 'Anak perusahaan',
                className: 'text-right tabular-nums',
                cell: (tenant) => formatNumber(tenant.subsidiaries_count),
            },
            {
                key: 'users_count',
                header: 'Pengguna',
                className: 'text-right tabular-nums',
                cell: (tenant) => formatNumber(tenant.users_count),
            },
            {
                key: 'customers_count',
                header: 'Pelanggan',
                className: 'text-right tabular-nums',
                cell: (tenant) => formatNumber(tenant.customers_count),
            },
            {
                key: 'failed_jobs_7d',
                header: 'Job gagal 7 hari',
                className: 'text-right tabular-nums',
                cell: (tenant) => (
                    <span
                        className={
                            tenant.failed_jobs_7d > 0
                                ? 'font-semibold text-destructive-text'
                                : 'text-muted-foreground'
                        }
                    >
                        {formatNumber(tenant.failed_jobs_7d)}
                    </span>
                ),
            },
            {
                key: 'failed_whatsapp_7d',
                header: 'WhatsApp gagal 7 hari',
                className: 'text-right tabular-nums',
                cell: (tenant) => (
                    <span
                        className={
                            tenant.failed_whatsapp_7d > 0
                                ? 'font-semibold text-destructive-text'
                                : 'text-muted-foreground'
                        }
                    >
                        {formatNumber(tenant.failed_whatsapp_7d)}
                    </span>
                ),
            },
            {
                key: 'last_login_at',
                header: 'Masuk terakhir',
                cell: (tenant) =>
                    tenant.last_login_at ? (
                        formatDateTime(tenant.last_login_at)
                    ) : (
                        <span className="text-muted-foreground">Belum pernah</span>
                    ),
            },
            {
                key: 'storage_bytes',
                header: 'Penyimpanan',
                className: 'text-right tabular-nums',
                cell: (tenant) => formatBytes(tenant.storage_bytes),
            },
        ],
        [],
    );

    return (
        <PlatformLayout title="Dasbor">
            <PageHeader
                title="Dasbor"
                subtitle="Pantau status dan kondisi setiap perusahaan pada platform."
                actions={createButton}
            />

            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard
                    label="Perusahaan aktif"
                    value={formatNumber(summary.active)}
                    accent="success"
                    icon={<Building2 className="size-5" aria-hidden="true" />}
                />
                <StatCard
                    label="Ditangguhkan"
                    value={formatNumber(summary.suspended)}
                    accent="warning"
                    icon={<PauseCircle className="size-5" aria-hidden="true" />}
                />
                <StatCard
                    label="Pengajuan hapus"
                    value={formatNumber(summary.deletion_requested)}
                    accent="danger"
                    icon={<Trash2 className="size-5" aria-hidden="true" />}
                />
                <StatCard
                    label="Job gagal tanpa perusahaan"
                    value={formatNumber(summary.unattributed_failed_jobs_7d)}
                    accent={summary.unattributed_failed_jobs_7d > 0 ? 'danger' : 'brand'}
                    icon={<AlertTriangle className="size-5" aria-hidden="true" />}
                />
            </div>

            {summary.unattributed_failed_jobs_7d > 0 ? (
                <Alert variant="warning">
                    <AlertDescription>
                        {summary.unattributed_failed_jobs_7d} job gagal dalam 7 hari terakhir tidak
                        membawa id perusahaan, sehingga tidak masuk ke baris perusahaan mana pun.
                    </AlertDescription>
                </Alert>
            ) : null}

            <Card>
                <CardContent className="space-y-4 pt-6">
                    <div className="flex flex-col gap-3 md:flex-row md:items-end">
                        <div className="min-w-48 flex-1">
                            <Input
                                label="Cari"
                                placeholder="Nama atau kode perusahaan"
                                value={params.search ?? ''}
                                maxLength={100}
                                onChange={(event) => set('search', event.target.value)}
                            />
                        </div>
                        <div className="w-full md:w-52">
                            <NativeSelect
                                label="Status"
                                value={params.status ?? ''}
                                options={STATUS_OPTIONS}
                                onChange={(event) => set('status', event.target.value)}
                            />
                        </div>
                    </div>
                    <DataTable
                        columns={columns}
                        pagination={toPagination(tenants)}
                        sortBy={sortBy}
                        sortDir={sortDir}
                        onSort={onSort}
                        onPageChange={(page) => set('page', page)}
                        onPerPageChange={(perPage) => set('per_page', perPage)}
                        emptyTitle="Belum ada perusahaan"
                        emptyDescription="Buat perusahaan beserta admin pertamanya untuk mulai mengelola tenant."
                        emptyAction={createButton}
                        hasFilters={hasFilters}
                        onResetFilters={() => setMany({ search: '', status: '' })}
                        itemLabel="perusahaan"
                        loading={processing}
                    />
                </CardContent>
            </Card>
        </PlatformLayout>
    );
}
