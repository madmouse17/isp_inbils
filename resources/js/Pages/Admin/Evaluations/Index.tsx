import { Link } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import {
    DataTable,
    DataTableActions,
    PageHeader,
    RemoteSearchSelect,
} from '@/Components/composite';
import type { DataTableColumn, RemoteOption } from '@/Components/composite';
import { Button, EnumBadge, Input, NativeSelect } from '@/Components/ui';
import { useCompany } from '@/hooks/useCompany';
import { useServerTable, type ServerTableFilters } from '@/hooks/useServerTable';
import { formatDateTime } from '@/lib/format';
import { toPagination } from '@/lib/pagination';
import { EVALUATION_REFERENCE_TYPE, optionsOf } from '@/lib/statuses';
import type { PaginationMeta } from '@/types';

interface EvalRow {
    id: number;
    reference_type: string;
    reference_id: number;
    score: string;
    customer_rating?: string | null;
    evaluated_at: string;
    employee?: { name: string } | null;
    evaluator?: { name: string } | null;
}

interface IndexProps extends Record<string, unknown> {
    evaluations: {
        data: EvalRow[];
        meta: PaginationMeta;
        referenceOptions?: Record<string, { code: string; title: string }>;
    };
    employeeOptions?: RemoteOption[];
    selectedEmployee?: RemoteOption | null;
    filters: ServerTableFilters;
    can: { create: boolean };
}

export default function Index({
    evaluations,
    employeeOptions,
    selectedEmployee,
    filters,
    can,
}: IndexProps) {
    const company = useCompany();
    const { params, set, setMany, sortBy, sortDir, onSort, setPage, processing } = useServerTable({
        url: route('admin.evaluations.index'),
        filters,
        only: ['evaluations', 'filters', 'selectedEmployee'],
    });

    const columns: DataTableColumn<EvalRow>[] = [
        {
            key: 'employee',
            header: 'Karyawan',
            cell: (row) => row.employee?.name ?? '-',
        },
        {
            key: 'reference_type',
            header: 'Referensi',
            sortable: true,
            sortKey: 'reference_type',
            cell: (row) => {
                const reference =
                    evaluations.referenceOptions?.[`${row.reference_type}:${row.reference_id}`];

                return (
                    <span className="inline-flex items-center gap-2">
                        <EnumBadge map={EVALUATION_REFERENCE_TYPE} value={row.reference_type} />
                        <span className="font-mono text-xs">
                            {reference?.code ?? `#${row.reference_id}`}
                        </span>
                    </span>
                );
            },
        },
        {
            key: 'score',
            header: 'Nilai',
            sortable: true,
            sortKey: 'score',
            className: 'font-medium',
            cell: (row) => row.score,
        },
        {
            key: 'customer_rating',
            header: 'Penilaian pelanggan',
            cell: (row) => row.customer_rating ?? '-',
        },
        {
            key: 'evaluator',
            header: 'Penilai',
            cell: (row) => row.evaluator?.name ?? '-',
        },
        {
            key: 'evaluated_at',
            header: 'Tanggal',
            sortable: true,
            sortKey: 'evaluated_at',
            cell: (row) => formatDateTime(row.evaluated_at, company),
        },
        {
            key: 'actions',
            header: 'Aksi',
            cell: (row) => <DataTableActions showHref={route('admin.evaluations.show', row.id)} />,
        },
    ];

    const hasFilters = Boolean(params.search || params.employee_id || params.reference_type);
    const create = can.create ? (
        <Button asChild variant="primary" size="sm">
            <Link href={route('admin.evaluations.create')}>Buat evaluasi</Link>
        </Button>
    ) : undefined;

    return (
        <AdminLayout title="Evaluasi">
            <div className="space-y-4">
                <PageHeader
                    title="Evaluasi"
                    subtitle="Penilaian kinerja karyawan atas SPK dan tiket yang mereka kerjakan."
                    actions={create}
                />

                <div className="flex flex-col gap-3 rounded-lg border bg-card p-3 md:flex-row md:flex-wrap md:items-end">
                    <div className="min-w-48 flex-1">
                        <Input
                            id="evaluation-search"
                            label="Cari"
                            value={params.search ?? ''}
                            onChange={(event) => set('search', event.target.value)}
                            placeholder="Nama karyawan"
                        />
                    </div>
                    <RemoteSearchSelect
                        id="evaluation-employee"
                        field="employee"
                        label="Karyawan"
                        className="w-full sm:w-64"
                        options={employeeOptions}
                        selected={selectedEmployee}
                        value={params.employee_id ?? ''}
                        onChange={(value) => set('employee_id', value)}
                        placeholder="Cari nama atau Email karyawan"
                        emptyText="Tidak ada karyawan yang cocok."
                    />
                    <NativeSelect
                        id="evaluation-reference"
                        label="Jenis referensi"
                        wrapperClassName="w-full sm:w-48"
                        value={params.reference_type ?? ''}
                        onChange={(event) => set('reference_type', event.target.value)}
                        options={[
                            { value: '', label: 'Semua jenis' },
                            ...optionsOf(EVALUATION_REFERENCE_TYPE),
                        ]}
                    />
                </div>

                <DataTable
                    columns={columns}
                    data={evaluations.data}
                    pagination={toPagination(evaluations)}
                    sortBy={sortBy}
                    sortDir={sortDir}
                    onSort={onSort}
                    onPageChange={setPage}
                    loading={processing}
                    hasFilters={hasFilters}
                    onResetFilters={() =>
                        setMany({ search: '', employee_id: '', reference_type: '', page: 1 })
                    }
                    itemLabel="evaluasi"
                    emptyTitle="Belum ada evaluasi"
                    emptyDescription="Evaluasi dibuat setelah SPK atau tiket selesai dikerjakan."
                    emptyAction={create}
                />
            </div>
        </AdminLayout>
    );
}
