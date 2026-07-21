import type { FormEvent } from 'react';
import { useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { PageHeader } from '@/Components/composite';
import {
    Badge,
    Button,
    Card,
    CardContent,
    Input,
    Modal,
    Pagination,
    SearchSelect,
    Switch,
    Table,
    TBody,
    TD,
    TH,
    THead,
    TR,
    Textarea,
} from '@/Components/ui';
import type { Category, Unit } from '@/types/inventory';

interface IndexProps extends Record<string, unknown> {
    categories: {
        data: Category[];
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
    units: { data: Unit[] };
    can: { create: boolean };
}

export default function Index({ categories, units, can }: IndexProps) {
    const [search, setSearch] = useState('');
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
        router.get(route('admin.categories.index'), { search }, { preserveState: true });
    };

    const openCreate = () => {
        setEditing(null);
        reset();
        setModalOpen(true);
    };

    const openEdit = (c: Category) => {
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

    const remove = (c: Category) => {
        if (window.confirm(`Delete ${c.name}?`)) {
            router.delete(route('admin.categories.destroy', c.id));
        }
    };

    return (
        <AdminLayout title="Categories">
            <div className="space-y-6">
                <PageHeader
                    title="Categories"
                    subtitle="Manage product categories and default unit."
                    actions={
                        can.create && (
                            <Button type="button" onClick={openCreate}>
                                Create
                            </Button>
                        )
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
                        <Table>
                            <THead>
                                <TR>
                                    <TH>Code</TH>
                                    <TH>Name</TH>
                                    <TH>Unit</TH>
                                    <TH>Children</TH>
                                    <TH>Status</TH>
                                    <TH>Actions</TH>
                                </TR>
                            </THead>
                            <TBody>
                                {categories.data.length === 0 ? (
                                    <TR>
                                        <TD
                                            colSpan={6}
                                            className="py-10 text-center text-muted-foreground"
                                        >
                                            No data found.
                                        </TD>
                                    </TR>
                                ) : (
                                    categories.data.map((c) => (
                                        <TR key={c.id}>
                                            <TD className="font-mono text-sm">{c.code}</TD>
                                            <TD>{c.name}</TD>
                                            <TD>
                                                {c.unit
                                                    ? `${c.unit.name} (${c.unit.symbol})`
                                                    : '—'}
                                            </TD>
                                            <TD>{c.children_count ?? 0}</TD>
                                            <TD>
                                                <Badge variant={c.is_active ? 'success' : 'danger'}>
                                                    {c.is_active ? 'Active' : 'Inactive'}
                                                </Badge>
                                            </TD>
                                            <TD>
                                                <div className="flex gap-2">
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() => openEdit(c)}
                                                    >
                                                        Edit
                                                    </Button>
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() => remove(c)}
                                                    >
                                                        Delete
                                                    </Button>
                                                </div>
                                            </TD>
                                        </TR>
                                    ))
                                )}
                            </TBody>
                        </Table>
                        <Pagination
                            currentPage={categories.current_page}
                            lastPage={categories.last_page}
                            onPageChange={(page) =>
                                router.get(route('admin.categories.index'), { page })
                            }
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
                        <Button type="submit" loading={processing}>
                            {editing ? 'Save' : 'Create'}
                        </Button>
                    </div>
                </form>
            </Modal>
        </AdminLayout>
    );
}
