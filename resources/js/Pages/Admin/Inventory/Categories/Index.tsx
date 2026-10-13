import type { FormEvent } from 'react';
import { useState } from 'react';
import { useForm } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import {
    DataTable,
    DataTableActions,
    type DataTableColumn,
    PageHeader,
} from '@/Components/composite';
import { ExportMenu } from '@/Components/ui/ExportMenu';
import { useServerTable } from '@/hooks/useServerTable';
import { type PaginationLike, toPagination } from '@/lib/pagination';
import {
    Badge,
    Button,
    Card,
    CardContent,
    Input,
    Modal,
    SearchSelect,
    Switch,
    Textarea,
} from '@/Components/ui';
import type { Category, Unit } from '@/types/inventory';

interface IndexProps extends Record<string, unknown> {
    categories: PaginationLike<Category>;
    units: { data: Unit[] };
    filters: { search?: string; sort?: string; direction?: string; per_page?: string | number };
    can: { create: boolean; export: boolean };
}

export default function Index({ categories, units, filters, can }: IndexProps) {
    const [search, setSearch] = useState(filters.search ?? '');
    const table = useServerTable({
        url: route('admin.categories.index'),
        filters,
        only: ['categories', 'filters', 'can'],
    });
    const [modalOpen, setModalOpen] = useState(false);
    const [editing, setEditing] = useState<Category | null>(null);
    const { data, setData, post, put, processing, errors, reset } = useForm({
        name: '',
        code: '',
        parent_id: '',
        unit_id: '',
        description: '',
        is_active: true,
    });

    const unitOptions = units.data.map((unit) => ({
        value: String(unit.id),
        label: `${unit.name} (${unit.symbol})`,
    }));

    const submitFilter = (e: FormEvent) => {
        e.preventDefault();
        table.visit({ search });
    };

    const openCreate = () => {
        setEditing(null);
        reset();
        setModalOpen(true);
    };

    const openEdit = (c: Category) => {
        setEditing(c);
        setData({
            name: c.name ?? '',
            code: c.code ?? '',
            parent_id: c.parent_id ? String(c.parent_id) : '',
            unit_id: c.unit_id ? String(c.unit_id) : '',
            description: c.description ?? '',
            is_active: Boolean(c.is_active),
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

    const columns: DataTableColumn<Category>[] = [
        {
            key: 'code',
            header: 'Code',
            sortable: true,
            sortKey: 'code',
            cell: (c) => <span className="font-mono text-sm">{c.code}</span>,
        },
        { key: 'name', header: 'Name', sortable: true, sortKey: 'name' },
        {
            key: 'unit',
            header: 'Unit',
            cell: (c) => (c.unit ? `${c.unit.name} (${c.unit.symbol})` : '—'),
        },
        {
            key: 'children_count',
            header: 'Children',
            cell: (c) => Number(c.children_count ?? 0) || 0,
        },
        {
            key: 'is_active',
            header: 'Status',
            cell: (c) => (
                <Badge variant={c.is_active ? 'success' : 'danger'}>
                    {c.is_active ? 'Active' : 'Inactive'}
                </Badge>
            ),
        },
        {
            key: 'actions',
            header: 'Actions',
            cell: (c) => (
                <DataTableActions
                    onEdit={() => openEdit(c)}
                    deleteHref={route('admin.categories.destroy', c.id)}
                    deleteMessage={`Delete ${c.name}?`}
                    deleteDescription={`This will permanently remove the category "${c.name}".`}
                />
            ),
        },
    ];

    return (
        <AdminLayout title="Kategori">
            <div className="space-y-6">
                <PageHeader
                    title="Kategori"
                    subtitle="Manage product categories and default unit."
                    actions={
                        <div className="flex flex-wrap gap-2">
                            <ExportMenu
                                exportUrl={route('admin.categories.export')}
                                params={table.params}
                                formats={['csv', 'pdf']}
                                canExport={can.export}
                            />
                            {can.create && (
                                <Button type="button" variant="success" onClick={openCreate}>
                                    Create
                                </Button>
                            )}
                        </div>
                    }
                />
                <Card>
                    <CardContent className="space-y-4 pt-6">
                        <form onSubmit={submitFilter} className="flex flex-wrap gap-2">
                            <Input
                                label="Search"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder="Name or code"
                            />
                            <div className="self-end">
                                <Button type="submit" variant="secondary">
                                    Filter
                                </Button>
                            </div>
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
                        />
                    </CardContent>
                </Card>
            </div>

            <Modal
                open={modalOpen}
                onClose={() => setModalOpen(false)}
                title={editing ? 'Edit Category' : 'Create Category'}
            >
                <form onSubmit={submitForm} className="space-y-4">
                    <Input
                        label="Name"
                        value={data.name}
                        onChange={(e) => setData('name', e.target.value)}
                        error={errors.name}
                        required
                    />
                    <Input
                        label="Code"
                        value={data.code}
                        onChange={(e) => setData('code', e.target.value)}
                        error={errors.code}
                        required
                    />
                    <SearchSelect
                        label="Unit"
                        value={data.unit_id}
                        onChange={(value) => setData('unit_id', value)}
                        options={unitOptions}
                        placeholder="Search unit"
                        emptyText="No units found."
                        error={errors.unit_id}
                        required
                    />
                    <Input
                        label="Parent ID"
                        value={data.parent_id}
                        onChange={(e) => setData('parent_id', e.target.value)}
                        error={errors.parent_id}
                        placeholder="Leave empty for root"
                    />
                    <Textarea
                        label="Description"
                        value={data.description}
                        onChange={(e) => setData('description', e.target.value)}
                        error={errors.description}
                        rows={2}
                    />
                    <Switch
                        label="Active"
                        checked={data.is_active}
                        onCheckedChange={(checked) => setData('is_active', checked)}
                    />
                    <div className="flex justify-end gap-2">
                        <Button
                            type="button"
                            variant="secondary"
                            onClick={() => setModalOpen(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            variant={editing ? 'default' : 'success'}
                            loading={processing}
                        >
                            {editing ? 'Save' : 'Create'}
                        </Button>
                    </div>
                </form>
            </Modal>
        </AdminLayout>
    );
}
