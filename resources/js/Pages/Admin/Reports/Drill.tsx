import { Link, usePage } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import {
    DataTable,
    MetricDefinitionNote,
    PageHeader,
    type DataTableColumn,
} from '@/Components/composite';
import {
    Badge,
    Button,
    Card,
    CardContent,
    CardHeader,
    CardTitle,
    EnumBadge,
} from '@/Components/ui';
import { useCompany } from '@/hooks/useCompany';
import { useServerTable } from '@/hooks/useServerTable';
import { formatDate, formatDateTime, formatRupiah } from '@/lib/format';
import { toPagination, type PaginationLike } from '@/lib/pagination';
import {
    INVOICE_STATUS,
    INVOICE_TYPE,
    NETWORK_ASSET_STATUS,
    NETWORK_ASSET_TYPE,
    SUBSCRIPTION_STATUS,
    TICKET_STATUS,
    WORK_ORDER_STATUS,
    WORK_ORDER_TYPE,
    type StatusMap,
} from '@/lib/statuses';
import type { MetricDefinitionData } from '@/types';
import { ReportExportMenu } from './components/ReportExportMenu';

type Cell = string | number | null;

type DrillRow = { id: number; url: string } & Record<string, Cell>;

interface DrillColumn {
    key: string;
    label: string;
    sortable: boolean;
    numeric: boolean;
}

interface TieOut {
    measure: 'count' | 'sum' | 'share' | 'average';
    noun: string;
    rows: number;
    figure: string | number | null;
    restricted: boolean;
    matches: boolean | null;
    sum: string | null;
    sum_label: string | null;
    compliant: number | null;
    companion: { label: string; rows: number } | null;
}

interface Reconciliation {
    label: string;
    href: string;
    amount: string;
    difference: string;
}

type DrillKind =
    'subscription' | 'invoice' | 'ticket' | 'work_order' | 'asset' | 'installation' | 'evaluation';

interface Props extends Record<string, unknown> {
    report: { key: string; title: string; href: string };
    source: {
        key: string;
        kind: DrillKind;
        label: string;
        group: string | null;
        noun: string;
        definition: MetricDefinitionData | null;
    };
    columns: DrillColumn[];
    rows: PaginationLike<DrillRow>;
    tie_out: TieOut;
    reconciliation: Reconciliation | null;
    filters: Record<string, string | number | undefined>;
    export: { url: string; params: Record<string, string | number> } | null;
}

/** The columns of each kind that hold a state or a type, with the map that names them. */
const ENUM_COLUMNS: Partial<Record<DrillKind, Record<string, StatusMap>>> = {
    invoice: { status: INVOICE_STATUS, type: INVOICE_TYPE },
    subscription: { status: SUBSCRIPTION_STATUS },
    ticket: { status: TICKET_STATUS },
    work_order: { status: WORK_ORDER_STATUS, type: WORK_ORDER_TYPE },
    asset: { status: NETWORK_ASSET_STATUS, asset_type: NETWORK_ASSET_TYPE },
};

const MONEY_COLUMNS: Record<string, true> = {
    mrc_amount: true,
    total: true,
    paid_amount: true,
    outstanding: true,
};
const DECIMAL = /^-?\d+(\.\d+)?$/;
const ISO_DATE = /^\d{4}-\d{2}-\d{2}$/;
const ISO_MOMENT = /^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}/;

export default function Drill({
    report,
    source,
    columns,
    rows,
    tie_out: tieOut,
    reconciliation,
    filters,
    export: exportFile,
}: Props) {
    const { url } = usePage();
    const company = useCompany();
    const { sort_by: sortBy, sort_dir: sortDir, ...applied } = filters;
    const table = useServerTable({
        url: url.split('?')[0],
        filters: { ...applied, sort: sortBy, direction: sortDir },
        only: ['rows', 'filters', 'export'],
    });
    const pagination = toPagination(rows);

    const title = source.group ? `${source.label}: ${source.group}` : source.label;
    const enumMaps = ENUM_COLUMNS[source.kind] ?? {};

    const formatCell = (key: string, value: Cell): string => {
        if (value === null || value === undefined || value === '') return '-';
        const text = String(value);

        if (MONEY_COLUMNS[key] && DECIMAL.test(text)) return formatRupiah(text, company);
        if (ISO_DATE.test(text)) return formatDate(text, company);
        if (ISO_MOMENT.test(text)) return formatDateTime(text, company);

        return text;
    };

    const tableColumns: DataTableColumn<DrillRow>[] = columns.map((column, index) => ({
        key: column.key,
        header: column.label,
        sortable: column.sortable,
        className: column.numeric ? 'text-right tabular-nums' : undefined,
        headerClassName: column.numeric ? 'text-right' : undefined,
        cell: (row) => {
            const value = row[column.key];
            const map = enumMaps[column.key];

            if (map && value !== null && value !== undefined && value !== '') {
                return <EnumBadge map={map} value={String(value)} />;
            }

            const text = formatCell(column.key, value);

            return index === 0 && row.url ? (
                <Link
                    href={row.url}
                    className="font-medium text-primary underline-offset-2 hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-hidden"
                >
                    {text}
                </Link>
            ) : (
                text
            );
        },
    }));

    const verdict = tieOut.restricted
        ? {
              variant: 'info' as const,
              text: 'Anda hanya melihat baris yang ditugaskan kepada Anda, jadi ini sebagian dari angka.',
          }
        : tieOut.matches === true
          ? {
                variant: 'success' as const,
                text: 'Baris-baris ini berjumlah sama dengan angka di laporan.',
            }
          : tieOut.matches === false
            ? {
                  variant: 'warning' as const,
                  text: 'Baris-baris ini berbeda dari angka di laporan.',
              }
            : {
                  variant: 'info' as const,
                  text: 'Angka ini rata-rata dari baris-baris ini; bandingkan dengan kolom di bawah.',
              };

    return (
        <AdminLayout title={title}>
            <div className="space-y-6">
                <PageHeader
                    title={title}
                    subtitle={`Data ${source.noun} di balik angka ${report.title}.`}
                    breadcrumbs={[{ label: report.title, href: report.href }, { label: title }]}
                    actions={
                        <>
                            <Button asChild variant="outline" size="sm">
                                <Link href={report.href}>Kembali ke {report.title}</Link>
                            </Button>
                            {exportFile && (
                                <ReportExportMenu
                                    url={exportFile.url}
                                    params={exportFile.params}
                                    canExport
                                />
                            )}
                        </>
                    }
                />

                <Card>
                    <CardHeader>
                        <CardTitle>Kecocokan dengan laporan</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <dl className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                            <div className="min-w-0">
                                <dt className="text-xs text-muted-foreground">Baris ditampilkan</dt>
                                <dd className="text-2xl font-bold">
                                    {tieOut.rows} {tieOut.noun}
                                </dd>
                                {tieOut.companion && (
                                    <dd className="text-xs text-muted-foreground">
                                        ditambah {tieOut.companion.rows} {tieOut.companion.label} di
                                        daftar pendamping
                                    </dd>
                                )}
                            </div>
                            {tieOut.sum !== null && (
                                <div className="min-w-0">
                                    <dt className="text-xs text-muted-foreground">
                                        {tieOut.sum_label}
                                    </dt>
                                    <dd className="text-2xl font-bold wrap-break-word">
                                        {tieOut.sum}
                                    </dd>
                                </div>
                            )}
                            {tieOut.compliant !== null && (
                                <div className="min-w-0">
                                    <dt className="text-xs text-muted-foreground">Tepat waktu</dt>
                                    <dd className="text-2xl font-bold">
                                        {tieOut.compliant} dari {tieOut.rows}
                                    </dd>
                                </div>
                            )}
                            <div className="min-w-0">
                                <dt className="text-xs text-muted-foreground">
                                    Angka di {report.title}
                                </dt>
                                <dd className="text-2xl font-bold wrap-break-word">
                                    {tieOut.figure ?? '-'}
                                    {tieOut.measure === 'share' ? '%' : ''}
                                </dd>
                            </div>
                        </dl>
                        <div role="status" className="flex flex-wrap items-center gap-2 text-sm">
                            <Badge variant={verdict.variant}>
                                {tieOut.matches === true
                                    ? 'Cocok'
                                    : tieOut.matches === false
                                      ? 'Berbeda'
                                      : 'Tidak bisa dicocokkan per baris'}
                            </Badge>
                            <span>{verdict.text}</span>
                        </div>
                        {source.definition && (
                            <MetricDefinitionNote definition={source.definition} className="mt-0" />
                        )}
                    </CardContent>
                </Card>

                {reconciliation && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Rekonsiliasi</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <p className="text-sm">
                                {reconciliation.label} menunjukkan{' '}
                                <strong>{reconciliation.amount}</strong>; daftar ini dikurangi{' '}
                                {reconciliation.label} adalah{' '}
                                <strong>{reconciliation.difference}</strong>.
                            </p>
                            <p className="text-xs text-muted-foreground">
                                {reconciliation.difference === '0.00'
                                    ? 'Kedua tampilan sama.'
                                    : `${reconciliation.label} memakai aturan sendiri, jadi selisih dilaporkan di sini, bukan disembunyikan.`}
                            </p>
                            <Button asChild variant="outline" size="sm">
                                <Link href={reconciliation.href}>Buka {reconciliation.label}</Link>
                            </Button>
                        </CardContent>
                    </Card>
                )}

                <DataTable
                    columns={tableColumns}
                    data={rows.data}
                    pagination={pagination}
                    rowKey="id"
                    sortBy={table.sortBy}
                    sortDir={table.sortDir}
                    onSort={table.onSort}
                    onPageChange={(page) => table.setPage(page)}
                    onPerPageChange={(perPage) => table.setPerPage(perPage)}
                    loading={table.processing}
                    emptyTitle={`Tidak ada ${tieOut.noun}`}
                    emptyDescription="Tidak ada data perusahaan yang cocok dengan angka ini untuk filter laporan."
                />
            </div>
        </AdminLayout>
    );
}
