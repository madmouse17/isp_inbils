import { FormEvent, useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { PageHeader } from '@/Components/composite';
import { Badge, Button, Card, CardContent, CardHeader, CardTitle, Input, Select, Textarea } from '@/Components/ui';

type LocationType = 'region' | 'area' | 'pop' | 'rack' | 'site';

interface LocationNode {
    id: number;
    parent_id: number | null;
    code: string;
    name: string;
    type: LocationType;
    path: string | null;
    address: string | null;
    lat: string | null;
    lng: string | null;
    is_active: boolean;
    children: LocationNode[];
}

interface LocationsProps {
    locations: { data: LocationNode[] } | LocationNode[];
    can: { create: boolean; update: boolean; delete: boolean };
}

const typeOptions = [
    { value: 'region', label: 'Region' },
    { value: 'area', label: 'Area' },
    { value: 'pop', label: 'POP' },
    { value: 'rack', label: 'Rack' },
    { value: 'site', label: 'Site' },
];

const emptyLocation = { code: '', name: '', type: 'region' as LocationType, parent_id: '', address: '', lat: '', lng: '', is_active: true };

export default function Index({ locations, can }: LocationsProps) {
    const tree = Array.isArray(locations) ? locations : locations.data;
    const flat = flatten(tree);
    const [open, setOpen] = useState<Set<number>>(() => new Set(flat.map((location) => location.id)));
    const [editing, setEditing] = useState<LocationNode | null>(null);
    const [moving, setMoving] = useState<LocationNode | null>(null);
    const createForm = useForm(emptyLocation);
    const editForm = useForm(emptyLocation);
    const moveForm = useForm({ new_parent_id: '' });

    const parentOptions = [{ value: '', label: 'No parent (region)' }, ...flat.map((location) => ({ value: String(location.id), label: location.path ?? location.code }))];

    const submitCreate = (event: FormEvent) => {
        event.preventDefault();
        createForm.post(route('admin.locations.store'), { onSuccess: () => createForm.reset() });
    };

    const startEdit = (location: LocationNode) => {
        setEditing(location);
        editForm.setData({
            code: location.code,
            name: location.name,
            type: location.type,
            parent_id: location.parent_id ? String(location.parent_id) : '',
            address: location.address ?? '',
            lat: location.lat ?? '',
            lng: location.lng ?? '',
            is_active: location.is_active,
        });
    };

    const submitEdit = (event: FormEvent) => {
        event.preventDefault();
        if (!editing) return;
        editForm.put(route('admin.locations.update', editing.id), { onSuccess: () => setEditing(null) });
    };

    const startMove = (location: LocationNode) => {
        setMoving(location);
        moveForm.setData('new_parent_id', '');
    };

    const submitMove = (event: FormEvent) => {
        event.preventDefault();
        if (!moving) return;
        moveForm.post(route('admin.locations.move', moving.id), { onSuccess: () => setMoving(null) });
    };

    const remove = (location: LocationNode) => {
        if (window.confirm(`Delete ${location.code}?`)) router.delete(route('admin.locations.destroy', location.id));
    };

    const toggle = (id: number) => {
        setOpen((current) => {
            const next = new Set(current);
            next.has(id) ? next.delete(id) : next.add(id);
            return next;
        });
    };

    return (
        <AdminLayout title="Locations">
            <div className="space-y-6">
                <PageHeader title="Locations" subtitle="Region, area, POP, rack, and site topology." />

                {can.create && (
                    <Card>
                        <CardHeader><CardTitle>Create Location</CardTitle></CardHeader>
                        <CardContent>
                            <form onSubmit={submitCreate} className="grid gap-4 lg:grid-cols-4">
                                <Input label="Code" value={createForm.data.code} onChange={(e) => createForm.setData('code', e.target.value)} error={createForm.errors.code} required />
                                <Input label="Name" value={createForm.data.name} onChange={(e) => createForm.setData('name', e.target.value)} error={createForm.errors.name} required />
                                <Select label="Type" value={createForm.data.type} onChange={(e) => createForm.setData('type', e.target.value as LocationType)} options={typeOptions} error={createForm.errors.type} required />
                                <Select label="Parent" value={createForm.data.parent_id} onChange={(e) => createForm.setData('parent_id', e.target.value)} options={parentOptions} error={createForm.errors.parent_id} />
                                <Textarea label="Address" value={createForm.data.address} onChange={(e) => createForm.setData('address', e.target.value)} error={createForm.errors.address} className="lg:col-span-2" />
                                <Input label="Latitude" value={createForm.data.lat} onChange={(e) => createForm.setData('lat', e.target.value)} error={createForm.errors.lat} />
                                <Input label="Longitude" value={createForm.data.lng} onChange={(e) => createForm.setData('lng', e.target.value)} error={createForm.errors.lng} />
                                <div className="lg:col-span-4"><Button type="submit" loading={createForm.processing}>Create</Button></div>
                            </form>
                        </CardContent>
                    </Card>
                )}

                <Card>
                    <CardHeader><CardTitle>Topology Tree</CardTitle></CardHeader>
                    <CardContent>
                        {tree.length === 0 ? (
                            <p className="text-sm text-surface-500 dark:text-surface-400">No locations yet.</p>
                        ) : (
                            <ul className="space-y-2">
                                {tree.map((location) => <TreeItem key={location.id} location={location} open={open} toggle={toggle} can={can} onEdit={startEdit} onMove={startMove} onDelete={remove} />)}
                            </ul>
                        )}
                    </CardContent>
                </Card>

                {editing && can.update && (
                    <Card>
                        <CardHeader><CardTitle>Edit {editing.code}</CardTitle></CardHeader>
                        <CardContent>
                            <form onSubmit={submitEdit} className="grid gap-4 lg:grid-cols-4">
                                <Input label="Code" value={editForm.data.code} onChange={(e) => editForm.setData('code', e.target.value)} error={editForm.errors.code} required />
                                <Input label="Name" value={editForm.data.name} onChange={(e) => editForm.setData('name', e.target.value)} error={editForm.errors.name} required />
                                <Select label="Type" value={editForm.data.type} onChange={(e) => editForm.setData('type', e.target.value as LocationType)} options={typeOptions} error={editForm.errors.type} required />
                                <Select label="Parent" value={editForm.data.parent_id} onChange={(e) => editForm.setData('parent_id', e.target.value)} options={parentOptions.filter((option) => option.value !== String(editing.id))} error={editForm.errors.parent_id} />
                                <Textarea label="Address" value={editForm.data.address} onChange={(e) => editForm.setData('address', e.target.value)} error={editForm.errors.address} className="lg:col-span-2" />
                                <Input label="Latitude" value={editForm.data.lat} onChange={(e) => editForm.setData('lat', e.target.value)} error={editForm.errors.lat} />
                                <Input label="Longitude" value={editForm.data.lng} onChange={(e) => editForm.setData('lng', e.target.value)} error={editForm.errors.lng} />
                                <div className="flex gap-2 lg:col-span-4">
                                    <Button type="submit" loading={editForm.processing}>Save</Button>
                                    <Button type="button" variant="secondary" onClick={() => setEditing(null)}>Cancel</Button>
                                </div>
                            </form>
                        </CardContent>
                    </Card>
                )}

                {moving && can.update && (
                    <Card>
                        <CardHeader><CardTitle>Move {moving.code}</CardTitle></CardHeader>
                        <CardContent>
                            <form onSubmit={submitMove} className="flex flex-col gap-4 sm:flex-row sm:items-end">
                                <Select label="New parent" value={moveForm.data.new_parent_id} onChange={(e) => moveForm.setData('new_parent_id', e.target.value)} options={parentOptions.filter((option) => option.value !== '' && option.value !== String(moving.id))} error={moveForm.errors.new_parent_id} required />
                                <div className="flex gap-2">
                                    <Button type="submit" loading={moveForm.processing}>Move</Button>
                                    <Button type="button" variant="secondary" onClick={() => setMoving(null)}>Cancel</Button>
                                </div>
                            </form>
                        </CardContent>
                    </Card>
                )}
            </div>
        </AdminLayout>
    );
}

function TreeItem({ location, open, toggle, can, onEdit, onMove, onDelete }: { location: LocationNode; open: Set<number>; toggle: (id: number) => void; can: LocationsProps['can']; onEdit: (location: LocationNode) => void; onMove: (location: LocationNode) => void; onDelete: (location: LocationNode) => void }) {
    const hasChildren = location.children.length > 0;

    return (
        <li>
            <div className="flex flex-col gap-3 rounded-lg border border-surface-200 bg-surface-50 p-3 dark:border-surface-800 dark:bg-surface-950 sm:flex-row sm:items-center sm:justify-between">
                <div className="flex items-center gap-3">
                    <Button type="button" variant="ghost" size="sm" onClick={() => toggle(location.id)} disabled={!hasChildren} aria-label={open.has(location.id) ? 'Collapse location' : 'Expand location'}>{hasChildren ? (open.has(location.id) ? '-' : '+') : ' '}</Button>
                    <div>
                        <div className="flex flex-wrap items-center gap-2">
                            <span className="font-semibold text-surface-900 dark:text-surface-100">{location.code}</span>
                            <span className="text-surface-700 dark:text-surface-300">{location.name}</span>
                            <Badge>{location.type}</Badge>
                            {!location.is_active && <Badge variant="warning">Inactive</Badge>}
                        </div>
                        <p className="mt-1 text-xs text-surface-500 dark:text-surface-400">{location.path}</p>
                    </div>
                </div>
                <div className="flex flex-wrap gap-2">
                    {can.update && <Button type="button" variant="ghost" size="sm" onClick={() => onEdit(location)}>Edit</Button>}
                    {can.update && <Button type="button" variant="outline" size="sm" onClick={() => onMove(location)}>Move</Button>}
                    {can.delete && <Button type="button" variant="danger" size="sm" onClick={() => onDelete(location)}>Delete</Button>}
                </div>
            </div>
            {hasChildren && open.has(location.id) && <ul className="ml-5 mt-2 space-y-2 border-l border-surface-200 pl-4 dark:border-surface-800">{location.children.map((child) => <TreeItem key={child.id} location={child} open={open} toggle={toggle} can={can} onEdit={onEdit} onMove={onMove} onDelete={onDelete} />)}</ul>}
        </li>
    );
}

function flatten(locations: LocationNode[]): LocationNode[] {
    return locations.flatMap((location) => [location, ...flatten(location.children)]);
}
