import { useMemo, useState } from 'react';
import { Link } from '@inertiajs/react';
import PlatformLayout from '@/Layouts/PlatformLayout';
import { DataTable, PageHeader, type DataTableColumn } from '@/Components/composite';
import {
    Button,
    Card,
    CardContent,
    DateRangePicker,
    EmptyCell,
    EnumBadge,
    Input,
    SearchSelect,
} from '@/Components/ui';
import { useServerTable } from '@/hooks/useServerTable';
import { formatDateTime } from '@/lib/format';
import { toPagination } from '@/lib/pagination';
import { PLATFORM_AUDIT_ACTION, PLATFORM_LOG, labelOf } from '@/lib/statuses';
import type { Paginated } from '@/types';
import type {
    PlatformAdminOption,
    PlatformAuditEntry,
    PlatformAuditFilters,
    PlatformTenantOption,
} from '@/types/platform';
import { AuditDetailDialog } from './components/AuditDetailDialog';

interface AuditIndexProps {
    activity: Paginated<PlatformAuditEntry>;
    filters: PlatformAuditFilters;
    tenants: PlatformTenantOption[];
    admins: PlatformAdminOption[];
}

const ACTION_OPTIONS = Object.entries(PLATFORM_AUDIT_ACTION).map(([code, { label }]) => ({
    value: code,
    label,
    description: code,
}));

const NO_FILTERS = {
    company_id: '',
    platform_admin_id: '',
    event: '',
    date_from: '',
    date_to: '',
    search: '',
};

export default function Index({ activity, filters, tenants, admins }: AuditIndexProps) {
    const { params, set, setMany, processing } = useServerTable({
        url: route('platform.audit.index'),
        filters,
        only: ['activity', 'filters'],
    });
    const [selected, setSelected] = useState<PlatformAuditEntry | null>(null);
    const hasFilters = Object.keys(NO_FILTERS).some((key) => params[key]);
    const tenantOptions = useMemo(
        () =>
            tenants.map((tenant) => ({
                value: String(tenant.id),
                label: tenant.name,
                description: tenant.code,
            })),
        [tenants],
    );
    const adminOptions = useMemo(
        () => admins.map((admin) => ({ value: String(admin.id), label: admin.name })),
        [admins],
    );

    const columns = useMemo<DataTableColumn<PlatformAuditEntry>[]>(
        () => [
            {
                key: 'created_at',
                header: 'Waktu',
                cell: (row) => formatDateTime(row.created_at),
            },
            {
                key: 'description',
                header: 'Kegiatan',
                cell: (row) => (
                    <div>
                        <p className="font-medium">
                            {labelOf(PLATFORM_AUDIT_ACTION, row.description)}
                        </p>
                        <p className="font-mono text-xs text-muted-foreground">{row.description}</p>
                    </div>
                ),
            },
            {
                key: 'log_name',
                header: 'Sumber',
                cell: (row) => <EnumBadge map={PLATFORM_LOG} value={row.log_name} />,
            },
            {
                key: 'causer',
                header: 'Pelaku',
                cell: (row) =>
                    row.causer ? (
                        <div>
                            <p>{row.causer.name ?? 'Tidak diketahui'}</p>
                            <p className="text-xs text-muted-foreground">
                                {row.causer.type === 'platform_admin'
                                    ? 'Operator platform'
                                    : 'Pengguna'}
                            </p>
                        </div>
                    ) : (
                        <span className="text-muted-foreground">Sistem</span>
                    ),
            },
            {
                key: 'company',
                header: 'Perusahaan',
                cell: (row) => {
                    const company = row.company;
                    if (!company) return <EmptyCell />;

                    return (
                        <div>
                            {company.id ? (
                                <Link
                                    href={route('platform.companies.show', company.id)}
                                    className="font-medium text-primary hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-hidden"
                                >
                                    {company.name ?? company.code}
                                </Link>
                            ) : (
                                <p className="font-medium">{company.name ?? company.code}</p>
                            )}
                            <p className="text-xs text-muted-foreground">{company.code}</p>
                        </div>
                    );
                },
            },
            {
                key: 'subject',
                header: 'Subjek',
                cell: (row) =>
                    row.subject ? (
                        <span className="text-xs text-muted-foreground">
                            {row.subject.type} #{row.subject.id}
                        </span>
                    ) : (
                        <EmptyCell />
                    ),
            },
            {
                key: 'detail',
                header: 'Rincian',
                cell: (row) => (
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        aria-label={`Lihat rincian ${labelOf(PLATFORM_AUDIT_ACTION, row.description)}`}
                        onClick={() => setSelected(row)}
                    >
                        Lihat
                    </Button>
                ),
            },
        ],
        [],
    );

    return (
        <PlatformLayout title="Audit platform">
            <PageHeader
                title="Audit platform"
                subtitle="Kegiatan operator platform dan akses dukungan pada semua perusahaan."
                breadcrumbs={[
                    { label: 'Dasbor', href: route('platform.dashboard') },
                    { label: 'Audit platform' },
                ]}
            />

            <Card>
                <CardContent className="space-y-4 pt-6">
                    <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        <Input
                            label="Cari"
                            placeholder="Kegiatan, operator, atau perusahaan"
                            value={params.search ?? ''}
                            maxLength={100}
                            onChange={(event) => set('search', event.target.value)}
                        />
                        <SearchSelect
                            label="Perusahaan"
                            placeholder="Semua perusahaan"
                            value={params.company_id ?? ''}
                            options={tenantOptions}
                            onChange={(value) => set('company_id', value)}
                        />
                        <SearchSelect
                            label="Operator"
                            placeholder="Semua operator"
                            value={params.platform_admin_id ?? ''}
                            options={adminOptions}
                            onChange={(value) => set('platform_admin_id', value)}
                        />
                        <SearchSelect
                            label="Kegiatan"
                            placeholder="Semua kegiatan"
                            value={params.event ?? ''}
                            options={ACTION_OPTIONS}
                            onChange={(value) => set('event', value)}
                        />
                        <DateRangePicker
                            label="Rentang tanggal"
                            value={{ from: params.date_from ?? null, to: params.date_to ?? null }}
                            onChange={(range) =>
                                setMany({ date_from: range.from, date_to: range.to })
                            }
                        />
                    </div>
                    <p className="text-sm text-muted-foreground">
                        Rentang tanggal dihitung dalam UTC. Waktu pada tabel mengikuti zona waktu
                        peramban.
                    </p>
                    <DataTable
                        columns={columns}
                        pagination={toPagination(activity)}
                        onPageChange={(page) => set('page', page)}
                        onPerPageChange={(perPage) => set('per_page', perPage)}
                        emptyTitle="Belum ada kegiatan"
                        emptyDescription="Kegiatan operator dan akses dukungan akan tercatat di sini."
                        hasFilters={hasFilters}
                        onResetFilters={() => setMany(NO_FILTERS)}
                        itemLabel="kegiatan"
                        loading={processing}
                    />
                </CardContent>
            </Card>

            {selected ? (
                <AuditDetailDialog entry={selected} onClose={() => setSelected(null)} />
            ) : null}
        </PlatformLayout>
    );
}
