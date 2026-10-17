import { useMemo, useState } from 'react';
import { Link } from '@inertiajs/react';
import { Archive } from 'lucide-react';
import { DataTable, type DataTableColumn } from '@/Components/composite';
import {
    Button,
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
    EmptyCell,
    EnumBadge,
} from '@/Components/ui';
import { ConfirmActionDialog } from '@/Components/composite/platform/ConfirmActionDialog';
import { usePlatformAction } from '@/Components/composite/platform/usePlatformAction';
import { formatDateTime, formatNumber } from '@/lib/format';
import { formatBytes, totalRows } from '@/lib/platform';
import { TENANT_EXPORT_STATUS } from '@/lib/statuses';
import type { PlatformTenantExport, PlatformTenantProfile } from '@/types/platform';

interface ExportsSectionProps {
    tenant: PlatformTenantProfile;
    exports: PlatformTenantExport[];
}

export function ExportsSection({ tenant, exports }: ExportsSectionProps) {
    const [requesting, setRequesting] = useState(false);
    const { processing, errors, post, clearErrors } = usePlatformAction();
    const columns = useMemo<DataTableColumn<PlatformTenantExport>[]>(
        () => [
            {
                key: 'created_at',
                header: 'Dibuat',
                cell: (row) => (row.created_at ? formatDateTime(row.created_at) : <EmptyCell />),
            },
            {
                key: 'status',
                header: 'Status',
                cell: (row) => <EnumBadge map={TENANT_EXPORT_STATUS} value={row.status} />,
            },
            {
                key: 'requested_by',
                header: 'Diminta oleh',
                cell: (row) => row.requested_by ?? <EmptyCell />,
            },
            {
                key: 'file_size',
                header: 'Ukuran',
                className: 'text-right tabular-nums',
                cell: (row) => (row.file_size == null ? '-' : formatBytes(row.file_size)),
            },
            {
                key: 'row_counts',
                header: 'Baris',
                className: 'text-right tabular-nums',
                cell: (row) => formatNumber(totalRows(row.row_counts)),
            },
            {
                key: 'completed_at',
                header: 'Selesai',
                cell: (row) =>
                    row.completed_at ? formatDateTime(row.completed_at) : <EmptyCell />,
            },
            {
                key: 'expires_at',
                header: 'Kedaluwarsa',
                cell: (row) => (row.expires_at ? formatDateTime(row.expires_at) : <EmptyCell />),
            },
            {
                key: 'error_message',
                header: 'Catatan',
                className: 'max-w-md whitespace-normal text-destructive-text',
                cell: (row) => row.error_message ?? <EmptyCell />,
            },
        ],
        [],
    );
    const close = () => {
        setRequesting(false);
        clearErrors();
    };

    return (
        <Card>
            <CardHeader className="gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <CardTitle>Ekspor data</CardTitle>
                    <CardDescription>
                        Riwayat ekspor perusahaan ini. Arsip yang siap diunduh dari halaman Ekspor.
                    </CardDescription>
                </div>
                <div className="flex flex-wrap gap-2">
                    <Button asChild size="sm" variant="outline">
                        <Link href={route('platform.exports.index', { company_id: tenant.id })}>
                            Semua ekspor
                        </Link>
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        leftIcon={<Archive className="size-4" aria-hidden="true" />}
                        onClick={() => setRequesting(true)}
                    >
                        Minta ekspor
                    </Button>
                </div>
            </CardHeader>
            <CardContent>
                <DataTable
                    columns={columns}
                    rows={exports}
                    emptyTitle="Belum ada ekspor"
                    emptyDescription="Belum ada ekspor data untuk perusahaan ini. Pakai Minta ekspor untuk membuatnya."
                    itemLabel="ekspor"
                />
            </CardContent>
            {requesting ? (
                <ConfirmActionDialog
                    title={`Minta ekspor ${tenant.name}?`}
                    description="Arsip data perusahaan dibuat di latar belakang dan siap diunduh dari halaman Ekspor selama 24 jam. Hanya satu ekspor yang boleh berjalan untuk satu perusahaan."
                    confirmLabel="Minta ekspor"
                    companyCode={tenant.code}
                    processing={processing}
                    errors={errors}
                    onConfirmed={(confirmation) =>
                        post(
                            route('platform.companies.exports.store', tenant.id),
                            confirmation,
                            close,
                        )
                    }
                    onClose={close}
                />
            ) : null}
        </Card>
    );
}
