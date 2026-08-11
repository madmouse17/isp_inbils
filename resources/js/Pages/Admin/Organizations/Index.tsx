import type { FormEvent } from 'react';
import { useCallback, useMemo, useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { PageHeader } from '@/Components/composite/PageHeader';
import { DataTable, type DataTableColumn } from '@/Components/composite/DataTable';
import { ExportMenu } from '@/Components/ExportMenu';
import {
    Badge,
    Button,
    Card,
    CardContent,
    Input,
    Modal,
    SearchSelect,
    Switch,
    NativeSelect,
} from '@/Components/ui';
import { useServerTable } from '@/hooks/useServerTable';
import { toPagination } from '@/lib/pagination';

interface OrgRow {
    id: number;
    parent_id: number | null;
    code: string;
    name: string;
    type: string;
    path?: string | null;
    address?: string | null;
    phone?: string | null;
    email?: string | null;
    is_active: boolean;
    children_count?: number;
}

interface IndexProps extends Record<string, unknown> {
    organizations: {
        data: OrgRow[];
        current_page: number;
        last_page: number;
        per_page?: number;
        total?: number;
    };
    parentOptions: { data: OrgRow[] };
    filters: { search?: string; sort?: string; direction?: string; per_page?: string | number };
    can: { export: boolean };
}

type OrgType = 'company' | 'branch' | 'area' | 'unit' | 'team';

interface OrganizationForm {
    code: string;
    name: string;
    type: OrgType;
    parent_id: string;
    address: string;
    phone: string;
    email: string;
    is_active: boolean;
}

interface ParentOption {
    id: number;
    value: string;
    label: string;
    description: string;
}

const orgTypeOptions = [
    { value: 'company', label: 'Company' },
    { value: 'branch', label: 'Branch' },
    { value: 'area', label: 'Area' },
    { value: 'unit', label: 'Unit' },
    { value: 'team', label: 'Team' },
];

const emptyForm: OrganizationForm = {
    code: '',
    name: '',
    type: 'branch',
    parent_id: '',
    address: '',
    phone: '',
    email: '',
    is_active: true,
};

export default function Index({ organizations, parentOptions, filters, can }: IndexProps) {
    const [modalOpen, setModalOpen] = useState(false);
    const [editId, setEditId] = useState<number | null>(null);
    const [parentSearch, setParentSearch] = useState('');
    const { data, setData, post, put, processing, errors, reset } =
        useForm<OrganizationForm>(emptyForm);

    const { params, set, sortBy, sortDir, onSort } = useServerTable({
        url: route('admin.organizations.index'),
        filters,
        only: ['organizations', 'parentOptions', 'filters', 'can'],
    });

    const rowMap = useMemo(
        () => new Map(parentOptions.data.map((organization) => [organization.id, organization])),
        [parentOptions.data],
    );
    const parentSelectOptions = useMemo(
        () =>
            parentOptions.data.map((organization) => ({
                id: organization.id,
                value: String(organization.id),
                label: `${organization.code} - ${organization.name}`,
                description: organization.path ?? organization.type,
            })),
        [parentOptions.data],
    );
    const availableParentOptions = parentSelectOptions.filter((option) => option.id !== editId);

    const openCreate = () => {
        reset();
        setParentSearch('');
        setEditId(null);
        setModalOpen(true);
    };

    const openEdit = useCallback(
        (o: OrgRow) => {
            setData('code', o.code);
            setData('name', o.name);
            setData('type', o.type as OrgType);
            setData('parent_id', o.parent_id ? String(o.parent_id) : '');
            setData('address', o.address ?? '');
            setData('phone', o.phone ?? '');
            setData('email', o.email ?? '');
            setData('is_active', o.is_active);
            setParentSearch(
                parentSelectOptions.find((option) => option.id === o.parent_id)?.value ?? '',
            );
            setEditId(o.id);
            setModalOpen(true);
        },
        [parentSelectOptions, setData],
    );

    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (editId) {
            put(route('admin.organizations.update', editId), {
                onSuccess: () => {
                    reset();
                    setParentSearch('');
                    setModalOpen(false);
                },
            });
            return;
        }

        post(route('admin.organizations.store'), {
            onSuccess: () => {
                reset();
                setParentSearch('');
                setModalOpen(false);
            },
        });
    };

    const remove = useCallback((o: OrgRow) => {
        if (window.confirm(`Delete ${o.name}?`)) {
            router.delete(route('admin.organizations.destroy', o.id), {
                preserveScroll: true,
            });
        }
    }, []);

    const updateParent = (value: string) => {
        setParentSearch(value);
        setData('parent_id', value);
    };

    const columns: DataTableColumn<OrgRow>[] = useMemo(
        () => [
            {
                key: 'code',
                header: 'Code',
                sortable: true,
                sortKey: 'code',
                cell: (o) => <span className="font-mono text-sm">{o.code}</span>,
            },
            {
                key: 'name',
                header: 'Name',
                sortable: true,
                sortKey: 'name',
                cell: (o) => (
                    <div>
                        <p className="font-medium text-foreground">{o.name}</p>
                        <p className="mt-1 text-xs text-muted-foreground">
                            {o.parent_id
                                ? `Parent: ${rowMap.get(o.parent_id)?.name ?? '-'}`
                                : 'Root unit'}
                        </p>
                    </div>
                ),
            },
            {
                key: 'type',
                header: 'Type',
                sortable: true,
                sortKey: 'type',
                cell: (o) => <Badge variant="neutral">{o.type}</Badge>,
            },
            {
                key: 'path',
                header: 'Path',
                cell: (o) => <span className="text-sm text-muted-foreground">{o.path ?? '-'}</span>,
            },
            {
                key: 'children_count',
                header: 'Children',
                cell: (o) => (
                    <Badge variant="neutral">
                        {o.children_count ?? 0}{' '}
                        {(o.children_count ?? 0) === 1 ? 'child' : 'children'}
                    </Badge>
                ),
            },
            {
                key: 'is_active',
                header: 'Status',
                cell: (o) => (
                    <Badge variant={o.is_active ? 'success' : 'danger'}>
                        {o.is_active ? 'Active' : 'Inactive'}
                    </Badge>
                ),
            },
            {
                key: 'actions',
                header: 'Actions',
                cell: (o) => (
                    <div className="flex flex-wrap gap-2">
                        <Button type="button" variant="ghost" size="sm" onClick={() => openEdit(o)}>
                            Edit
                        </Button>
                        <Button type="button" variant="ghost" size="sm" onClick={() => remove(o)}>
                            Delete
                        </Button>
                    </div>
                ),
            },
        ],
        [rowMap, openEdit, remove],
    );

    return (
        <AdminLayout title="Organization">
            <div className="space-y-6">
                <PageHeader
                    title="Organization Units"
                    subtitle="Branch, area, unit, team hierarchy."
                    actions={
                        <div className="flex flex-wrap gap-2">
                            {can.export ? (
                                <ExportMenu
                                    exportUrl={route('admin.organizations.export')}
                                    params={params}
                                    canExport={can.export}
                                />
                            ) : null}
                            <Button type="button" onClick={openCreate}>
                                Add Unit
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
                                    htmlFor="org-search"
                                >
                                    Search
                                </label>
                                <Input
                                    id="org-search"
                                    value={params.search ?? ''}
                                    onChange={(event) => set('search', event.target.value)}
                                    placeholder="Code, name, path…"
                                />
                            </div>
                            <div className="space-y-1">
                                <label className="text-xs font-medium text-muted-foreground">
                                    Per page
                                </label>
                                <NativeSelect
                                    value={params.per_page || '10'}
                                    onChange={(event) => set('per_page', event.target.value)}
                                    options={[
                                        { value: '10', label: '10 / page' },
                                        { value: '25', label: '25 / page' },
                                        { value: '50', label: '50 / page' },
                                    ]}
                                    className="w-32"
                                />
                            </div>
                        </div>

                        <DataTable
                            columns={columns}
                            pagination={toPagination(organizations)}
                            sortBy={sortBy}
                            sortDir={sortDir}
                            onSort={onSort}
                            onPageChange={(page) => set('page', page)}
                            onPerPageChange={(perPage) => set('per_page', perPage)}
                            emptyTitle="No organization units"
                            emptyDescription="No organization units match the current filters."
                        />
                    </CardContent>
                </Card>

                <Modal
                    open={modalOpen}
                    onClose={() => setModalOpen(false)}
                    title={editId ? 'Edit Unit' : 'Add Unit'}
                >
                    <form onSubmit={submit} className="space-y-4">
                        <div className="grid gap-4 md:grid-cols-2">
                            <Input
                                label="Code"
                                value={data.code}
                                onChange={(event) => setData('code', event.target.value)}
                                error={errors.code}
                                required
                            />
                            <Input
                                label="Name"
                                value={data.name}
                                onChange={(event) => setData('name', event.target.value)}
                                error={errors.name}
                                required
                            />
                            <NativeSelect
                                label="Type"
                                value={data.type}
                                onChange={(event) => setData('type', event.target.value as OrgType)}
                                options={orgTypeOptions}
                                error={errors.type}
                                required
                            />
                            <ParentSearchInput
                                value={parentSearch}
                                options={availableParentOptions}
                                error={errors.parent_id}
                                onChange={updateParent}
                            />
                            <Input
                                label="Phone"
                                value={data.phone}
                                onChange={(event) => setData('phone', event.target.value)}
                            />
                            <Input
                                label="Email"
                                value={data.email}
                                onChange={(event) => setData('email', event.target.value)}
                            />
                        </div>
                        <Input
                            label="Address"
                            value={data.address}
                            onChange={(event) => setData('address', event.target.value)}
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
                                {editId ? 'Save' : 'Add'}
                            </Button>
                        </div>
                    </form>
                </Modal>
            </div>
        </AdminLayout>
    );
}

function ParentSearchInput({
    value,
    options,
    error,
    onChange,
}: {
    value: string;
    options: ParentOption[];
    error?: string;
    onChange: (value: string) => void;
}) {
    return (
        <SearchSelect
            label="Parent"
            value={value}
            onChange={onChange}
            options={options}
            placeholder={options.length ? 'Search parent unit' : 'No parent units yet'}
            hint="Clear field for root unit."
            error={error}
            disabled={options.length === 0}
        />
    );
}
