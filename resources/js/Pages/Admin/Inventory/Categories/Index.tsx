import type { FormEvent } from 'react';
import { useState } from 'react';
import { useForm } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import {
    DataTable,
    DataTableActions,
    type DataTableColumn,
    PageHeader,
    StatusFilter,
} from '@/Components/composite';
import { UsageLine } from '@/Components/composite/UsageLine';
import { ExportMenu } from '@/Components/ui/ExportMenu';
import { useServerTable } from '@/hooks/useServerTable';
import { type PaginationLike, toPagination } from '@/lib/pagination';
import { LIFECYCLE_STATE, lifecycleStateOf } from '@/lib/statuses';
import {
    Button,
    Card,
    CardContent,
    EmptyCell,
    EnumBadge,
    Input,
    Modal,
    SearchSelect,
    Switch,
    Textarea,
} from '@/Components/ui';
import type { LifecycleFields } from '@/types/models';
import type { Category, Unit } from '@/types/inventory';

type CategoryRow = Category & LifecycleFields;

/** A category as the parent picker needs it: every category, not only the page of the table. */
interface CategoryOption {
    id: string;
    code: string;
    name: string;
}

interface IndexProps extends Record<string, unknown> {
    categories: PaginationLike<CategoryRow>;
    units: { data: Unit[] };
    categoryOptions: CategoryOption[];
    filters: {
        search?: string;
        status?: string;
        sort?: string;
        direction?: string;
        per_page?: string | number;
    };
    can: { create: boolean; export: boolean; update: boolean; delete: boolean };
}

export default function Index({ categories, units, categoryOptions, filters, can }: IndexProps) {
    const [search, setSearch] = useState(filters.search ?? '');
    const table = useServerTable({
        url: route('admin.categories.index'),
        filters,
        only: ['categories', 'filters', 'can'],
    });
    const [modalOpen, setModalOpen] = useState(false);
    const [editing, setEditing] = useState<CategoryRow | null>(null);
    const { data, setData, post, put, processing, errors, reset } = useForm({
        name: '',
        code: '',
        parent_id: '',
        unit_id: '',
        description: '',
        is_active: true,
    });

    const unitChoices = units.data.map((unit) => ({
        value: String(unit.id),
        label: `${unit.name} (${unit.symbol})`,
    }));
    // The picker lists active units only; the category's own unit may be inactive and must still show.
    const editingUnit = editing?.unit
        ? {
              value: String(editing.unit.id),
              label: `${editing.unit.name} (${editing.unit.symbol})`,
          }
        : null;
    // A category cannot be its own parent, so the one being edited is not offered.
    const parentChoices = categoryOptions
        .filter((category) => !editing || String(category.id) !== String(editing.id))
        .map((category) => ({
            value: String(category.id),
            label: `${category.code} - ${category.name}`,
        }));

    const submitFilter = (e: FormEvent) => {
        e.preventDefault();
        table.visit({ search });
    };

    const resetFilters = () => {
        setSearch('');
        table.visit({ search: '', status: '' });
    };

    const openCreate = () => {
        setEditing(null);
        reset();
        setModalOpen(true);
    };

    const openEdit = (c: CategoryRow) => {
        setEditing(c);
        setData({
            name: c.name,
            code: c.code,
            parent_id: c.parent_id ? String(c.parent_id) : '',
            unit_id: c.unit_id ? String(c.unit_id) : '',
            description: c.description ?? '',
            is_active: c.is_active,
        });
        setModalOpen(true);
    };

    const submitForm = (e: FormEvent) => {
        e.preventDefault();
        if (editing) {
            put(route('admin.categories.update', editing.id), {
                onSuccess: () => {
                    setModalOpen(false);
                    reset();
                },
            });
        } else {
            post(route('admin.categories.store'), {
                onSuccess: () => {
                    setModalOpen(false);
                    reset();
                },
            });
        }
    };

    const columns: DataTableColumn<CategoryRow>[] = [
        {
            key: 'code',
            header: 'Kode',
            sortable: true,
            sortKey: 'code',
            cell: (c) => <span className="font-mono text-sm">{c.code}</span>,
        },
        {
            key: 'name',
            header: 'Nama',
            sortable: true,
            sortKey: 'name',
            cell: (c) => (
                <div className="space-y-0.5">
                    <span className={c.deleted_at ? 'text-muted-foreground' : undefined}>
                        {c.name}
                    </span>
                    <UsageLine references={c.references} />
                </div>
            ),
        },
        {
            key: 'unit',
            header: 'Satuan',
            cell: (c) => (c.unit ? `${c.unit.name} (${c.unit.symbol})` : <EmptyCell />),
        },
        {
            key: 'children_count',
            header: 'Sub-kategori',
            cell: (c) => Number(c.children_count ?? 0),
        },
        {
            key: 'status',
            header: 'Status',
            cell: (c) => <EnumBadge map={LIFECYCLE_STATE} value={lifecycleStateOf(c)} />,
        },
        {
            key: 'actions',
            header: 'Aksi',
            cell: (c) => (
                <DataTableActions
                    onEdit={() => openEdit(c)}
                    lifecycle={{
                        row: c,
                        name: c.name,
                        noun: 'Kategori barang',
                        routeBase: 'admin.categories',
                        can: { update: can.update, delete: can.delete },
                    }}
                />
            ),
        },
    ];

    const createButton = can.create ? (
        <Button type="button" variant="primary" onClick={openCreate}>
            Buat Kategori
        </Button>
    ) : undefined;

    return (
        <AdminLayout title="Kategori">
            <div className="space-y-6">
                <PageHeader
                    title="Kategori"
                    subtitle="Kelompokkan barang dan tentukan satuan bawaannya."
                    actions={
                        <div className="flex flex-wrap gap-2">
                            <ExportMenu
                                exportUrl={route('admin.categories.export')}
                                params={table.params}
                                formats={['csv', 'pdf']}
                                canExport={can.export}
                            />
                            {createButton}
                        </div>
                    }
                />
                <Card>
                    <CardContent className="space-y-4 pt-6">
                        <form onSubmit={submitFilter} className="flex flex-wrap items-end gap-2">
                            <Input
                                label="Cari kategori"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder="Nama atau kode"
                            />
                            <StatusFilter
                                id="category-status"
                                value={table.params.status ?? ''}
                                onChange={(value) => table.set('status', value)}
                                deactivatable
                            />
                            <Button type="submit" variant="secondary">
                                Filter
                            </Button>
                        </form>
                        <DataTable
                            columns={columns}
                            pagination={toPagination(categories)}
                            sortBy={table.sortBy}
                            sortDir={table.sortDir}
                            onSort={table.onSort}
                            onPageChange={table.setPage}
                            onPerPageChange={table.setPerPage}
                            loading={table.processing}
                            itemLabel="kategori"
                            emptyDescription="Buat kategori lebih dulu, lalu pilih kategori itu saat membuat barang."
                            emptyAction={createButton}
                            hasFilters={Boolean(filters.search || filters.status)}
                            onResetFilters={resetFilters}
                        />
                    </CardContent>
                </Card>
            </div>

            <Modal
                open={modalOpen}
                onClose={() => setModalOpen(false)}
                title={editing ? 'Ubah Kategori' : 'Buat Kategori'}
            >
                <form onSubmit={submitForm} className="space-y-4">
                    <Input
                        label="Nama"
                        value={data.name}
                        onChange={(e) => setData('name', e.target.value)}
                        error={errors.name}
                        required
                    />
                    <Input
                        label="Kode"
                        value={data.code}
                        onChange={(e) => setData('code', e.target.value)}
                        error={errors.code}
                        required
                    />
                    <SearchSelect
                        label="Satuan"
                        value={data.unit_id}
                        onChange={(value) => setData('unit_id', value)}
                        options={unitChoices}
                        selected={editingUnit}
                        placeholder="Cari satuan"
                        emptyText="Tidak ada satuan yang cocok."
                        error={errors.unit_id}
                        required
                    />
                    <SearchSelect
                        label="Induk"
                        value={data.parent_id}
                        onChange={(value) => setData('parent_id', value)}
                        options={parentChoices}
                        placeholder="Tanpa induk (kategori utama)"
                        emptyText="Tidak ada kategori yang cocok."
                        error={errors.parent_id}
                    />
                    <Textarea
                        label="Deskripsi"
                        value={data.description}
                        onChange={(e) => setData('description', e.target.value)}
                        error={errors.description}
                        rows={2}
                    />
                    <Switch
                        label="Aktif"
                        checked={data.is_active}
                        onCheckedChange={(checked) => setData('is_active', checked)}
                    />
                    <div className="flex justify-end gap-2">
                        <Button
                            type="button"
                            variant="secondary"
                            onClick={() => setModalOpen(false)}
                        >
                            Batal
                        </Button>
                        <Button type="submit" variant="primary" loading={processing}>
                            {editing ? 'Simpan' : 'Buat'}
                        </Button>
                    </div>
                </form>
            </Modal>
        </AdminLayout>
    );
}
