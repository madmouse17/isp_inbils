import { Link, usePage } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import {
    DataTable,
    MetricDefinitionNote,
    PageHeader,
    type DataTableColumn,
} from '@/Components/composite';
import { Badge, Button, Card, CardContent, CardHeader, CardTitle } from '@/Components/ui';
import { useServerTable } from '@/hooks/useServerTable';
import { toPagination, type PaginationLike } from '@/lib/pagination';
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

interface Props extends Record<string, unknown> {
    report: { key: string; title: string; href: string };
    source: {
        key: string;
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
    const { sort_by: sortBy, sort_dir: sortDir, ...applied } = filters;
    const table = useServerTable({
        url: url.split('?')[0],
        filters: { ...applied, sort: sortBy, direction: sortDir },
        only: ['rows', 'filters', 'export'],
    });
    const pagination = toPagination(rows);

    const title = source.group ? `${source.label}: ${source.group}` : source.label;

    const tableColumns: DataTableColumn<DrillRow>[] = columns.map((column, index) => ({
        key: column.key,
        header: column.label,
        sortable: column.sortable,
        className: column.numeric ? 'text-right tabular-nums' : undefined,
        headerClassName: column.numeric ? 'text-right' : undefined,
        cell: (row) => {
            const value = row[column.key];
            const text =
                value === null || value === undefined || value === '' ? '—' : String(value);

            return index === 0 && row.url ? (
                <Link
                    href={row.url}
                    className="font-medium text-primary underline-offset-2 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
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
              text: 'You only see the rows assigned to you, so they cover a part of the figure.',
          }
        : tieOut.matches === true
          ? {
                variant: 'success' as const,
                text: 'Ties out: these rows add up to the figure on the report.',
            }
          : tieOut.matches === false
            ? {
                  variant: 'warning' as const,
                  text: 'Does not tie out: these rows differ from the figure on the report.',
              }
            : {
                  variant: 'info' as const,
                  text: 'This figure is an average over these rows; compare it with the columns below.',
              };

    return (
        <AdminLayout title={title}>
            <div className="space-y-6">
                <PageHeader
                    title={title}
                    subtitle={`Source ${source.noun} behind this figure of ${report.title}.`}
                    breadcrumbs={[{ label: report.title, href: report.href }, { label: title }]}
                    actions={
                        <>
                            <Button asChild variant="outline" size="sm">
                                <Link href={report.href}>Back to {report.title}</Link>
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
                        <CardTitle>Tie-out to the report</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <dl className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                            <div className="min-w-0">
                                <dt className="text-xs text-muted-foreground">Rows listed</dt>
                                <dd className="text-2xl font-bold">
                                    {tieOut.rows} {tieOut.noun}
                                </dd>
                                {tieOut.companion && (
                                    <dd className="text-xs text-muted-foreground">
                                        plus {tieOut.companion.rows} {tieOut.companion.label} in the
                                        sibling list
                                    </dd>
                                )}
                            </div>
                            {tieOut.sum !== null && (
                                <div className="min-w-0">
                                    <dt className="text-xs text-muted-foreground">
                                        {tieOut.sum_label}
                                    </dt>
                                    <dd className="break-words text-2xl font-bold">{tieOut.sum}</dd>
                                </div>
                            )}
                            {tieOut.compliant !== null && (
                                <div className="min-w-0">
                                    <dt className="text-xs text-muted-foreground">On time</dt>
                                    <dd className="text-2xl font-bold">
                                        {tieOut.compliant} of {tieOut.rows}
                                    </dd>
                                </div>
                            )}
                            <div className="min-w-0">
                                <dt className="text-xs text-muted-foreground">
                                    Figure on {report.title}
                                </dt>
                                <dd className="break-words text-2xl font-bold">
                                    {tieOut.figure ?? '—'}
                                    {tieOut.measure === 'share' ? '%' : ''}
                                </dd>
                            </div>
                        </dl>
                        <div role="status" className="flex flex-wrap items-center gap-2 text-sm">
                            <Badge variant={verdict.variant}>
                                {tieOut.matches === true
                                    ? 'Ties out'
                                    : tieOut.matches === false
                                      ? 'Differs'
                                      : 'Not reconcilable by rows'}
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
                            <CardTitle>Reconciliation</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <p className="text-sm">
                                {reconciliation.label} shows{' '}
                                <strong>{reconciliation.amount}</strong>; this list minus{' '}
                                {reconciliation.label} is{' '}
                                <strong>{reconciliation.difference}</strong>.
                            </p>
                            <p className="text-xs text-muted-foreground">
                                {reconciliation.difference === '0.00'
                                    ? 'Both surfaces agree.'
                                    : `${reconciliation.label} applies its own inclusion rules, so a difference is reported here rather than hidden.`}
                            </p>
                            <Button asChild variant="outline" size="sm">
                                <Link href={reconciliation.href}>Open {reconciliation.label}</Link>
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
                    emptyTitle={`No ${tieOut.noun}`}
                    emptyDescription="Nothing in the company matches this figure for the filters of the report."
                />
            </div>
        </AdminLayout>
    );
}
