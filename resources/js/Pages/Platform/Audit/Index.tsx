import { useMemo, useState } from 'react';
import { Link } from '@inertiajs/react';
import PlatformLayout from '@/Layouts/PlatformLayout';
import { DataTable, PageHeader, type DataTableColumn } from '@/Components/composite';
import {
    Alert,
    AlertDescription,
    Button,
    Card,
    CardContent,
    DateRangePicker,
    EmptyCell,
    EnumBadge,
    Input,
    NativeSelect,
    SearchSelect,
} from '@/Components/ui';
import { useServerTable } from '@/hooks/useServerTable';
import { formatDateTime } from '@/lib/format';
import { toPagination } from '@/lib/pagination';
import { PLATFORM_LOG } from '@/lib/statuses';
import type { Paginated } from '@/types';
import type {
    PlatformAdminOption,
    PlatformAuditEntry,
    PlatformAuditEventOption,
    PlatformAuditFilterErrors,
    PlatformAuditFilters,
    PlatformTenantOption,
} from '@/types/platform';
import { AuditDetailDialog } from './components/AuditDetailDialog';

interface AuditIndexProps {
    activity: Paginated<PlatformAuditEntry>;
    filters: PlatformAuditFilters;
    /** What was wrong with each filter the server could not use; the page still opens on the rest. */
    filterErrors: PlatformAuditFilterErrors;
    /** Every action the logs hold, with its sentence. */
    events: PlatformAuditEventOption[];
    tenants: PlatformTenantOption[];
    admins: PlatformAdminOption[];
}

const DEFAULT_SOURCE = 'platform';

const SOURCE_OPTIONS = [
    { value: 'platform', label: 'Platform' },
    { value: 'support', label: 'Akses dukungan' },
    { value: 'all', label: 'Semua sumber' },
];

const NO_FILTERS = {
    company_id: '',
    platform_admin_id: '',
    event: '',
    source: '',
    date_from: '',
    date_to: '',
    search: '',
};

export default function Index({
    activity,
    filters,
    filterErrors,
    events,
    tenants,
    admins,
}: AuditIndexProps) {
    const { params, set, setMany, processing } = useServerTable({
        url: route('platform.audit.index'),
        filters,
        only: ['activity', 'filters', 'filterErrors'],
    });
    const [selected, setSelected] = useState<PlatformAuditEntry | null>(null);
    const source = params.source ?? DEFAULT_SOURCE;
    // The default source is where the page starts, not a filter somebody set.
    const hasFilters = Object.keys(NO_FILTERS).some((key) =>
        key === 'source' ? source !== DEFAULT_SOURCE : params[key],
    );
    const eventOptions = useMemo(
        () => events.map(({ value, label }) => ({ value, label, description: value })),
        [events],
    );
    // Page and rows per page have no field of their own; the others show their message beside the field.
    const pagingError = filterErrors.page ?? filterErrors.per_page;
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
                        <p className="font-medium">{row.text}</p>
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
                                    className="font-medium text-primary-text hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-hidden"
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
                        variant="outline"
                        size="sm"
                        aria-label={`Lihat rincian ${row.text}`}
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
                            error={filterErrors.search}
                            onChange={(event) => set('search', event.target.value)}
                        />
                        <SearchSelect
                            label="Perusahaan"
                            placeholder="Semua perusahaan"
                            value={params.company_id ?? ''}
                            options={tenantOptions}
                            error={filterErrors.company_id}
                            onChange={(value) => set('company_id', value)}
                        />
                        <SearchSelect
                            label="Operator"
                            placeholder="Semua operator"
                            value={params.platform_admin_id ?? ''}
                            options={adminOptions}
                            error={filterErrors.platform_admin_id}
                            onChange={(value) => set('platform_admin_id', value)}
                        />
                        <SearchSelect
                            label="Kegiatan"
                            placeholder="Semua kegiatan"
                            value={params.event ?? ''}
                            options={eventOptions}
                            error={filterErrors.event}
                            onChange={(value) => set('event', value)}
                        />
                        <NativeSelect
                            label="Sumber"
                            value={source}
                            options={SOURCE_OPTIONS}
                            error={filterErrors.source}
                            onChange={(event) => set('source', event.target.value)}
                        />
                        <DateRangePicker
                            label="Rentang tanggal"
                            value={{ from: params.date_from ?? null, to: params.date_to ?? null }}
                            error={filterErrors.date_from ?? filterErrors.date_to}
                            onChange={(range) =>
                                setMany({ date_from: range.from, date_to: range.to })
                            }
                        />
                    </div>
                    <p className="text-sm text-muted-foreground">
                        Rentang tanggal dihitung dalam UTC. Waktu pada tabel mengikuti zona waktu
                        peramban.
                    </p>
                    {pagingError ? (
                        <Alert variant="warning">
                            <AlertDescription>{pagingError}</AlertDescription>
                        </Alert>
                    ) : null}
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
