import { useMemo } from 'react';
import { Link } from '@inertiajs/react';
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
import { formatDateTime } from '@/lib/format';
import { classBasename } from '@/lib/platform';
import { AUDIT_EVENT, PLATFORM_AUDIT_ACTION, PLATFORM_LOG, resolveStatus } from '@/lib/statuses';
import type {
    PlatformAuditRow,
    PlatformFailedJob,
    PlatformFailedWhatsApp,
    PlatformTenantProfile,
} from '@/types/platform';
import { FailedListCard } from './FailedListCard';

interface ActivitySectionsProps {
    tenant: PlatformTenantProfile;
    audit: PlatformAuditRow[];
    failedJobs: PlatformFailedJob[];
    failedWhatsApp: PlatformFailedWhatsApp[];
}

/** The platform log's label first, then the tenant audit's; a code neither lists shows as itself. */
function actionLabel(description: string): string {
    const known = [PLATFORM_AUDIT_ACTION, AUDIT_EVENT]
        .map((map) => resolveStatus(map, description))
        .find((status) => status?.known);

    return known?.label ?? description;
}

export function ActivitySections({
    tenant,
    audit,
    failedJobs,
    failedWhatsApp,
}: ActivitySectionsProps) {
    const auditColumns = useMemo<DataTableColumn<PlatformAuditRow>[]>(
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
                        <p className="font-medium">{actionLabel(row.description)}</p>
                        {row.subject_type ? (
                            <p className="text-xs text-muted-foreground">{row.subject_type}</p>
                        ) : null}
                    </div>
                ),
            },
            {
                key: 'log_name',
                header: 'Sumber',
                cell: (row) =>
                    row.log_name === 'platform' || row.log_name === 'support' ? (
                        <EnumBadge map={PLATFORM_LOG} value={row.log_name} />
                    ) : (
                        <span className="text-muted-foreground">{row.log_name}</span>
                    ),
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
                key: 'reason',
                header: 'Alasan',
                cell: (row) => row.reason ?? <EmptyCell />,
            },
        ],
        [],
    );
    const jobColumns = useMemo<DataTableColumn<PlatformFailedJob>[]>(
        () => [
            {
                key: 'failed_at',
                header: 'Waktu',
                cell: (row) => formatDateTime(row.failed_at),
            },
            {
                key: 'job',
                header: 'Job',
                cell: (row) => <span title={row.job ?? undefined}>{classBasename(row.job)}</span>,
            },
            { key: 'queue', header: 'Antrean' },
            {
                key: 'exception',
                header: 'Galat',
                className: 'max-w-md whitespace-normal text-destructive-text',
            },
        ],
        [],
    );
    const fullJobColumns = useMemo<DataTableColumn<PlatformFailedJob>[]>(
        () => [
            ...jobColumns,
            {
                key: 'company',
                header: 'Perusahaan',
                cell: (row) => row.company?.code ?? <EmptyCell />,
            },
        ],
        [jobColumns],
    );
    const whatsappColumns = useMemo<DataTableColumn<PlatformFailedWhatsApp>[]>(
        () => [
            {
                key: 'failed_at',
                header: 'Waktu',
                cell: (row) => formatDateTime(row.failed_at),
            },
            { key: 'event', header: 'Peristiwa' },
            { key: 'template', header: 'Template' },
            { key: 'error_code', header: 'Kode galat' },
            {
                key: 'recipient_last4',
                header: 'Penerima',
                cell: (row) =>
                    row.recipient_last4 ? `Nomor berakhiran ${row.recipient_last4}` : '-',
            },
            {
                key: 'company',
                header: 'Perusahaan',
                cell: (row) => row.company.code,
            },
        ],
        [],
    );

    return (
        <>
            <div className="grid gap-6 xl:grid-cols-2">
                <FailedListCard
                    title="Job gagal"
                    itemLabel="job gagal"
                    recent={failedJobs}
                    columns={jobColumns}
                    fullColumns={fullJobColumns}
                    url={route('platform.companies.failed-jobs', tenant.id)}
                />
                <FailedListCard
                    title="WhatsApp gagal"
                    itemLabel="pesan gagal"
                    recent={failedWhatsApp}
                    columns={whatsappColumns}
                    url={route('platform.companies.failed-notifications', tenant.id)}
                />
            </div>
            <Card>
                <CardHeader className="gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <CardTitle>Audit terbaru</CardTitle>
                        <CardDescription>
                            Sepuluh kegiatan terbaru pada perusahaan induk dan anak perusahaannya.
                        </CardDescription>
                    </div>
                    <Button asChild size="sm" variant="outline">
                        <Link href={route('platform.audit.index', { company_id: tenant.id })}>
                            Lihat semua audit
                        </Link>
                    </Button>
                </CardHeader>
                <CardContent>
                    <DataTable
                        columns={auditColumns}
                        rows={audit}
                        emptyTitle="Belum ada kegiatan"
                        emptyDescription="Kegiatan perusahaan ini akan tercatat di sini."
                        itemLabel="kegiatan"
                    />
                </CardContent>
            </Card>
        </>
    );
}
