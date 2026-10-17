import { useEffect, useMemo } from 'react';
import { Link, router } from '@inertiajs/react';
import { Download } from 'lucide-react';
import PlatformLayout from '@/Layouts/PlatformLayout';
import { DataTable, PageHeader, type DataTableColumn } from '@/Components/composite';
import {
    Badge,
    Button,
    Card,
    CardContent,
    EmptyCell,
    EnumBadge,
    NativeSelect,
} from '@/Components/ui';
import { useServerTable } from '@/hooks/useServerTable';
import { formatDateTime, formatNumber } from '@/lib/format';
import { toPagination } from '@/lib/pagination';
import { formatBytes, totalRows } from '@/lib/platform';
import { TENANT_EXPORT_STATUS, optionsOf } from '@/lib/statuses';
import type { Paginated } from '@/types';
import type { PlatformExportFilters, PlatformExportRow } from '@/types/platform';

interface ExportsIndexProps {
    exports: Paginated<PlatformExportRow>;
    filters: PlatformExportFilters;
}

const POLL_MS = 5000;
const STATUS_OPTIONS = [{ value: '', label: 'Semua status' }, ...optionsOf(TENANT_EXPORT_STATUS)];

export default function Index({ exports, filters }: ExportsIndexProps) {
    const { params, set, setMany, processing } = useServerTable({
        url: route('platform.exports.index'),
        filters,
        only: ['exports', 'filters'],
    });
    const hasFilters = Boolean(params.status || params.company_id);
    const building = exports.data.some(
        (row) => row.status === 'queued' || row.status === 'running',
    );
    // Every row of a company filter names the same tenant, so the first one labels the chip.
    const filteredTenant = params.company_id ? exports.data[0]?.tenant : undefined;

    // A build runs on a queue worker, so the list asks again until no row is waiting or running.
    useEffect(() => {
        if (!building) return;

        const poll = router.poll(POLL_MS, { only: ['exports'], async: true }, { mode: 'rest' });

        return () => poll.destroy();
    }, [building]);

    const columns = useMemo<DataTableColumn<PlatformExportRow>[]>(
        () => [
            {
                key: 'tenant',
                header: 'Perusahaan',
                cell: (row) => (
                    <div>
                        <Link
                            href={route('platform.companies.show', row.tenant.id)}
                            className="font-medium text-primary hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-hidden"
                        >
                            {row.tenant.name}
                        </Link>
                        <p className="text-xs text-muted-foreground">{row.tenant.code}</p>
                    </div>
                ),
            },
            {
                key: 'status',
                header: 'Status',
                cell: (row) => <EnumBadge map={TENANT_EXPORT_STATUS} value={row.status} />,
            },
            {
                key: 'requested_by',
                header: 'Diminta',
                cell: (row) => (
                    <div>
                        <p>{row.requested_by ?? <EmptyCell />}</p>
                        <p className="text-xs text-muted-foreground">
                            {formatDateTime(row.created_at)}
                        </p>
                    </div>
                ),
            },
            {
                key: 'file_size',
                header: 'Ukuran',
                className: 'text-right tabular-nums',
                headerClassName: 'text-right',
                cell: (row) => (row.file_size == null ? <EmptyCell /> : formatBytes(row.file_size)),
            },
            {
                key: 'row_counts',
                header: 'Baris',
                className: 'text-right tabular-nums',
                headerClassName: 'text-right',
                cell: (row) =>
                    row.row_counts == null ? (
                        <EmptyCell />
                    ) : (
                        formatNumber(totalRows(row.row_counts))
                    ),
            },
            {
                key: 'expires_at',
                header: 'Berlaku sampai',
                cell: (row) => (row.expires_at ? formatDateTime(row.expires_at) : <EmptyCell />),
            },
            {
                key: 'error_message',
                header: 'Catatan',
                className: 'max-w-xs whitespace-normal text-destructive-text',
                cell: (row) => row.error_message ?? <EmptyCell />,
            },
            {
                key: 'download_url',
                header: 'Unduh',
                cell: (row) =>
                    row.download_url ? (
                        <Button
                            asChild
                            size="sm"
                            variant="outline"
                            leftIcon={<Download className="size-4" aria-hidden="true" />}
                        >
                            <a
                                href={row.download_url}
                                download
                                aria-label={`Unduh ekspor ${row.tenant.code}`}
                            >
                                Unduh
                            </a>
                        </Button>
                    ) : (
                        <EmptyCell />
                    ),
            },
        ],
        [],
    );

    return (
        <PlatformLayout title="Ekspor">
            <PageHeader
                title="Ekspor data"
                subtitle="Arsip data perusahaan. Minta ekspor baru dari halaman perusahaan."
                breadcrumbs={[
                    { label: 'Dasbor', href: route('platform.dashboard') },
                    { label: 'Ekspor' },
                ]}
            />

            <Card>
                <CardContent className="space-y-4 pt-6">
                    <div className="flex flex-col gap-3 md:flex-row md:items-end">
                        <div className="w-full md:w-52">
                            <NativeSelect
                                label="Status"
                                value={params.status ?? ''}
                                options={STATUS_OPTIONS}
                                onChange={(event) => set('status', event.target.value)}
                            />
                        </div>
                        {params.company_id ? (
                            <div className="flex flex-wrap items-center gap-2 md:pb-1">
                                <Badge variant="info">
                                    {filteredTenant
                                        ? `Perusahaan ${filteredTenant.name} (${filteredTenant.code})`
                                        : 'Satu perusahaan'}
                                </Badge>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    onClick={() => set('company_id', '')}
                                >
                                    Tampilkan semua perusahaan
                                </Button>
                            </div>
                        ) : null}
                    </div>
                    <p className="text-sm text-muted-foreground">
                        Arsip siap diunduh selama 24 jam sejak selesai. Setelah itu tautan hilang
                        dan ekspor baru perlu diminta.
                    </p>
                    <DataTable
                        columns={columns}
                        pagination={toPagination(exports)}
                        onPageChange={(page) => set('page', page)}
                        onPerPageChange={(perPage) => set('per_page', perPage)}
                        emptyTitle="Belum ada ekspor"
                        emptyDescription="Minta ekspor dari halaman sebuah perusahaan. Hasilnya muncul di sini."
                        hasFilters={hasFilters}
                        onResetFilters={() => setMany({ status: '', company_id: '' })}
                        itemLabel="ekspor"
                        loading={processing}
                    />
                </CardContent>
            </Card>
        </PlatformLayout>
    );
}
