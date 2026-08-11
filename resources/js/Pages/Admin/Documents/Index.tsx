import type { FormEvent } from 'react';
import { useMemo, useState } from 'react';
import { Link, router, useForm } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { PageHeader } from '@/Components/composite/PageHeader';
import { DataTable, type DataTableColumn } from '@/Components/composite/DataTable';
import { ExportMenu } from '@/Components/ExportMenu';
import { Badge } from '@/Components/ui/Badge';
import { Button } from '@/Components/ui/Button';
import { Card, CardContent } from '@/Components/ui/Card';
import { Input } from '@/Components/ui/Input';
import { Modal } from '@/Components/ui/Modal';
import { NativeSelect } from '@/Components/composite';
import { Switch } from '@/Components/ui/Switch';
import { useServerTable } from '@/hooks/useServerTable';
import { toPagination } from '@/lib/pagination';

interface DtRow {
    id: number;
    name: string;
    code: string;
    applies_to?: string | null;
    is_required: boolean;
    expiry_days?: number | null;
    is_active: boolean;
}

interface IndexProps extends Record<string, unknown> {
    documentTypes: {
        data: DtRow[];
        current_page: number;
        last_page: number;
        per_page?: number;
        total?: number;
    };
    filters: { search?: string; sort?: string; direction?: string; per_page?: string | number };
    can: { export: boolean };
}

export default function Index({ documentTypes, filters, can }: IndexProps) {
    const [modalOpen, setModalOpen] = useState(false);
    const [editId, setEditId] = useState<number | null>(null);
    const { data, setData, post, put, processing, errors, reset } = useForm({
        name: '',
        code: '',
        applies_to: '',
        is_required: false,
        expiry_days: '',
        is_active: true,
    });

    const { params, set, sortBy, sortDir, onSort } = useServerTable({
        url: route('admin.documents.index'),
        filters,
        only: ['documentTypes', 'filters', 'can'],
    });

    const columns: DataTableColumn<DtRow>[] = useMemo(
        () => [
            { key: 'name', header: 'Name', sortable: true, sortKey: 'name', cell: (d) => d.name },
            {
                key: 'code',
                header: 'Code',
                sortable: true,
                sortKey: 'code',
                cell: (d) => <span className="font-mono text-sm">{d.code}</span>,
            },
            {
                key: 'applies_to',
                header: 'Applies To',
                sortable: true,
                sortKey: 'applies_to',
                cell: (d) => d.applies_to ?? '-',
            },
            {
                key: 'is_required',
                header: 'Required',
                cell: (d) => (d.is_required ? <Badge variant="brand">Yes</Badge> : '-'),
            },
            {
                key: 'expiry_days',
                header: 'Expiry',
                cell: (d) => (d.expiry_days ? `${d.expiry_days} days` : '-'),
            },
            {
                key: 'is_active',
                header: 'Status',
                cell: (d) => (
                    <Badge variant={d.is_active ? 'success' : 'danger'}>
                        {d.is_active ? 'Active' : 'Inactive'}
                    </Badge>
                ),
            },
            {
                key: 'actions',
                header: 'Actions',
                cell: (d) => (
                    <div className="flex gap-2">
                        <Button type="button" variant="ghost" size="sm" onClick={() => openEdit(d)}>
                            Edit
                        </Button>
                        <Button type="button" variant="ghost" size="sm" onClick={() => remove(d)}>
                            Delete
                        </Button>
                    </div>
                ),
            },
        ],
        [],
    );

    const openCreate = () => {
        reset();
        setEditId(null);
        setModalOpen(true);
    };
    const openEdit = (d: DtRow) => {
        setData('name', d.name);
        setData('code', d.code);
        setData('applies_to', d.applies_to ?? '');
        setData('is_required', d.is_required);
        setData('expiry_days', d.expiry_days ? String(d.expiry_days) : '');
        setData('is_active', d.is_active);
        setEditId(d.id);
        setModalOpen(true);
    };
    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (editId) {
            put(route('admin.documents.update', editId), { onSuccess: () => setModalOpen(false) });
        } else {
            post(route('admin.documents.store'), { onSuccess: () => setModalOpen(false) });
        }
    };
    const remove = (d: DtRow) => {
        if (window.confirm(`Delete ${d.name}?`))
            router.delete(route('admin.documents.destroy', d.id));
    };

    return (
        <AdminLayout title="Document Types">
            <div className="space-y-6">
                <PageHeader
                    title="Document Types"
                    subtitle="Business document categories. Files stored via Spatie MediaLibrary."
                    actions={
                        <div className="flex flex-wrap gap-2">
                            {can.export ? (
                                <ExportMenu
                                    exportUrl={route('admin.documents.export')}
                                    params={params}
                                    canExport={can.export}
                                />
                            ) : null}
                            <Button type="button" onClick={openCreate}>
                                Add Type
                            </Button>
                        </div>
                    }
                />

                <Card>
                    <CardContent className="space-y-4 pt-6">
                        <div className="flex flex-col gap-3 rounded-lg border bg-card p-3 md:flex-row md:items-end">
                            <div className="min-w-[12rem] flex-1 space-y-1">
                                <label
                                    className="text-xs font-medium text-muted-foreground"
                                    htmlFor="doc-search"
                                >
                                    Search
                                </label>
                                <Input
                                    id="doc-search"
                                    value={params.search ?? ''}
                                    onChange={(e) => set('search', e.target.value)}
                                    placeholder="Name or code…"
                                />
                            </div>
                        </div>

                        <DataTable
                            columns={columns}
                            pagination={toPagination(documentTypes)}
                            sortBy={sortBy}
                            sortDir={sortDir}
                            onSort={onSort}
                            onPageChange={(page) => set('page', page)}
                            emptyTitle="No document types"
                            emptyDescription="No document types match the current filters."
                        />
                    </CardContent>
                </Card>

                <Modal
                    open={modalOpen}
                    onClose={() => setModalOpen(false)}
                    title={editId ? 'Edit Type' : 'Add Type'}
                >
                    <form onSubmit={submit} className="space-y-4">
                        <div className="grid gap-4 md:grid-cols-2">
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
                            <Input
                                label="Applies To"
                                value={data.applies_to}
                                onChange={(e) => setData('applies_to', e.target.value)}
                                placeholder="Customer, Employee, Vehicle..."
                            />
                            <Input
                                label="Expiry (days)"
                                type="number"
                                value={data.expiry_days}
                                onChange={(e) => setData('expiry_days', e.target.value)}
                            />
                        </div>
                        <div className="flex gap-6">
                            <Switch
                                label="Required"
                                checked={data.is_required}
                                onCheckedChange={(c) => setData('is_required', c)}
                            />
                            <Switch
                                label="Active"
                                checked={data.is_active}
                                onCheckedChange={(c) => setData('is_active', c)}
                            />
                        </div>
                        <div className="flex justify-end gap-2">
                            <Button
                                type="button"
                                variant="secondary"
                                onClick={() => setModalOpen(false)}
                            >
                                Cancel
                            </Button>
                            <Button type="submit" loading={processing}>
                                {editId ? 'Save' : 'Add'}
                            </Button>
                        </div>
                    </form>
                </Modal>
            </div>
        </AdminLayout>
    );
}
