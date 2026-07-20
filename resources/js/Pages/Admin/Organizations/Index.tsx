import type { FormEvent } from 'react';
import { useState } from 'react';
import { router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { PageHeader } from '@/Components/composite';
import { useInertiaForm } from '@/hooks/useInertiaForm';
import {
    Badge,
    Button,
    Card,
    CardContent,
    Input,
    SearchSelect,
    Switch,
    Select,
    Table,
    TBody,
    TD,
    TH,
    THead,
    TR,
    Modal,
    useToast,
} from '@/Components/ui';

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
    organizations: { data: OrgRow[] };
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

export default function Index({ organizations }: IndexProps) {
    const [modalOpen, setModalOpen] = useState(false);
    const [editId, setEditId] = useState<number | null>(null);
    const [parentSearch, setParentSearch] = useState('');
    const { toast } = useToast();
    const { data, setData, post, put, processing, errors, reset } =
        useInertiaForm<OrganizationForm>(emptyForm);

    const childMap = buildChildMap(organizations.data);
    const rowMap = new Map(organizations.data.map((organization) => [organization.id, organization]));
    const parentOptions = organizations.data.map((organization) => ({
        id: organization.id,
        value: String(organization.id),
        label: `${organization.code} - ${organization.name}`,
        description: organization.path ?? organization.type,
    }));
    const availableParentOptions = parentOptions.filter((option) => option.id !== editId);

    const openCreate = () => {
        reset();
        setParentSearch('');
        setEditId(null);
        setModalOpen(true);
    };
    const openEdit = (o: OrgRow) => {
        setData('code', o.code);
        setData('name', o.name);
        setData('type', o.type as OrgType);
        setData('parent_id', o.parent_id ? String(o.parent_id) : '');
        setData('address', o.address ?? '');
        setData('phone', o.phone ?? '');
        setData('email', o.email ?? '');
        setData('is_active', o.is_active);
        setParentSearch(parentOptions.find((option) => option.id === o.parent_id)?.value ?? '');
        setEditId(o.id);
        setModalOpen(true);
    };
    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (editId) {
            put(route('admin.organizations.update', editId), {
                successMessage: 'Organization unit updated.',
                errorMessage: 'Failed to update organization unit.',
                onSuccess: () => {
                    reset();
                    setParentSearch('');
                    setModalOpen(false);
                },
            });
        } else {
            post(route('admin.organizations.store'), {
                successMessage: 'Organization unit created.',
                errorMessage: 'Failed to create organization unit.',
                onSuccess: () => {
                    reset();
                    setParentSearch('');
                    setModalOpen(false);
                },
            });
        }
    };
    const remove = (o: OrgRow) => {
        if (window.confirm(`Delete ${o.name}?`))
            router.delete(route('admin.organizations.destroy', o.id), {
                onSuccess: () => toast({ title: 'Organization unit deleted.', variant: 'success' }),
                onError: () =>
                    toast({ title: 'Failed to delete organization unit.', variant: 'danger' }),
            });
    };

    const updateParent = (value: string) => {
        setParentSearch(value);
        setData('parent_id', value);
    };

    return (
        <AdminLayout title="Organization">
            <div className="space-y-6">
                <PageHeader
                    title="Organization Units"
                    subtitle="Branch, area, unit, team hierarchy."
                    actions={
                        <Button type="button" onClick={openCreate}>
                            Add Unit
                        </Button>
                    }
                />
                <Card>
                    <CardContent className="space-y-4 pt-6">
                        <Table>
                            <THead>
                                <TR>
                                    <TH>Code</TH>
                                    <TH>Name</TH>
                                    <TH>Type</TH>
                                    <TH>Path</TH>
                                    <TH>Children</TH>
                                    <TH>Status</TH>
                                    <TH>Actions</TH>
                                </TR>
                            </THead>
                            <TBody>
                                {organizations.data.length === 0 ? (
                                    <TR>
                                        <TD
                                            colSpan={7}
                                            className="py-10 text-center text-muted-foreground"
                                        >
                                            No data found.
                                        </TD>
                                    </TR>
                                ) : (
                                    organizations.data.map((o) => (
                                        <TR key={o.id}>
                                            <TD className="font-mono text-sm">{o.code}</TD>
                                            <TD>
                                                <div>
                                                    <p>{o.name}</p>
                                                    <p className="mt-1 text-xs text-muted-foreground">
                                                        {o.parent_id
                                                            ? `Parent: ${rowMap.get(o.parent_id)?.name ?? '-'}`
                                                            : 'Root unit'}
                                                    </p>
                                                </div>
                                            </TD>
                                            <TD>
                                                <Badge variant="neutral">{o.type}</Badge>
                                            </TD>
                                            <TD className="text-sm text-surface-500">
                                                {o.path ?? '-'}
                                            </TD>
                                            <TD>
                                                <ChildrenSummary children={childMap.get(o.id) ?? []} />
                                            </TD>
                                            <TD>
                                                <Badge variant={o.is_active ? 'success' : 'danger'}>
                                                    {o.is_active ? 'Active' : 'Inactive'}
                                                </Badge>
                                            </TD>
                                            <TD>
                                                <div className="flex gap-2">
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() => openEdit(o)}
                                                    >
                                                        Edit
                                                    </Button>
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() => remove(o)}
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
                                onChange={(e) => setData('code', e.target.value)}
                                error={errors.code}
                                required
                            />
                            <Input
                                label="Name"
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                error={errors.name}
                                required
                            />
                            <Select
                                label="Type"
                                value={data.type}
                                onChange={(e) => setData('type', e.target.value as OrgType)}
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
                                onChange={(e) => setData('phone', e.target.value)}
                            />
                            <Input
                                label="Email"
                                value={data.email}
                                onChange={(e) => setData('email', e.target.value)}
                            />
                        </div>
                        <Input
                            label="Address"
                            value={data.address}
                            onChange={(e) => setData('address', e.target.value)}
                        />
                        <Switch
                            label="Active"
                            checked={data.is_active}
                            onCheckedChange={(c) => setData('is_active', c)}
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

function ChildrenSummary({ children }: { children: OrgRow[] }) {
    if (children.length === 0) {
        return <span className="text-sm text-muted-foreground">No children</span>;
    }

    return (
        <div className="space-y-1">
            <Badge variant="neutral">
                {children.length} {children.length === 1 ? 'child' : 'children'}
            </Badge>
            <p className="max-w-xs truncate text-xs text-muted-foreground">
                {children.map((child) => child.name).join(', ')}
            </p>
        </div>
    );
}

function buildChildMap(rows: OrgRow[]): Map<number, OrgRow[]> {
    const map = new Map<number, OrgRow[]>();

    rows.forEach((row) => {
        if (row.parent_id === null) {
            return;
        }

        map.set(row.parent_id, [...(map.get(row.parent_id) ?? []), row]);
    });

    return map;
}
