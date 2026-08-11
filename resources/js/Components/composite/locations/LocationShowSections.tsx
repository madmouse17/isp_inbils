import {
    Button,
    Card,
    CardContent,
    CardHeader,
    CardTitle,
    Input,
    Textarea,
    Badge,
} from '@/Components/ui';
import { NativeSelect } from '@/Components/composite';

export type LocationType = 'region' | 'area' | 'pop' | 'rack' | 'site';

export interface LocationNode {
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

export interface LocationFormState {
    data: {
        code: string;
        name: string;
        type: LocationType;
        parent_id: string;
        address: string;
        lat: string;
        lng: string;
        is_active: boolean;
    };
    errors: Record<string, string | undefined>;
    processing: boolean;
    setData: (field: string, value: string | boolean) => void;
    post: (url: string, options?: { onSuccess?: () => void }) => void;
    put: (url: string, options?: { onSuccess?: () => void }) => void;
    reset: () => void;
}

export interface LocationMoveFormState {
    data: { new_parent_id: string };
    errors: Record<string, string | undefined>;
    processing: boolean;
    setData: (field: 'new_parent_id', value: string) => void;
    post: (url: string, options?: { onSuccess?: () => void }) => void;
}

export const locationTypeOptions = [
    { value: 'region', label: 'Region' },
    { value: 'area', label: 'Area' },
    { value: 'pop', label: 'POP' },
    { value: 'rack', label: 'Rack' },
    { value: 'site', label: 'Site' },
];

export function LocationCreateFormCard({
    createForm,
    parentOptions,
}: {
    createForm: LocationFormState;
    parentOptions: { value: string; label: string }[];
}) {
    return (
        <Card>
            <CardHeader>
                <CardTitle>Create Location</CardTitle>
            </CardHeader>
            <CardContent>
                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        createForm.post(route('admin.locations.store'), {
                            onSuccess: () => createForm.reset(),
                        });
                    }}
                    className="grid gap-4 lg:grid-cols-4"
                >
                    <Input
                        label="Code"
                        value={createForm.data.code}
                        onChange={(event) => createForm.setData('code', event.target.value)}
                        error={createForm.errors.code}
                        required
                    />
                    <Input
                        label="Name"
                        value={createForm.data.name}
                        onChange={(event) => createForm.setData('name', event.target.value)}
                        error={createForm.errors.name}
                        required
                    />
                    <NativeSelect
                        label="Type"
                        value={createForm.data.type}
                        onChange={(event) => createForm.setData('type', event.target.value)}
                        options={locationTypeOptions}
                        error={createForm.errors.type}
                        required
                    />
                    <NativeSelect
                        label="Parent"
                        value={createForm.data.parent_id}
                        onChange={(event) => createForm.setData('parent_id', event.target.value)}
                        options={parentOptions}
                        error={createForm.errors.parent_id}
                    />
                    <Textarea
                        label="Address"
                        value={createForm.data.address}
                        onChange={(event) => createForm.setData('address', event.target.value)}
                        error={createForm.errors.address}
                        className="lg:col-span-2"
                    />
                    <Input
                        label="Latitude"
                        value={createForm.data.lat}
                        onChange={(event) => createForm.setData('lat', event.target.value)}
                        error={createForm.errors.lat}
                    />
                    <Input
                        label="Longitude"
                        value={createForm.data.lng}
                        onChange={(event) => createForm.setData('lng', event.target.value)}
                        error={createForm.errors.lng}
                    />
                    <div className="lg:col-span-4">
                        <Button type="submit" loading={createForm.processing}>
                            Create
                        </Button>
                    </div>
                </form>
            </CardContent>
        </Card>
    );
}

export function LocationEditFormCard({
    location,
    editForm,
    parentOptions,
    onClose,
}: {
    location: LocationNode;
    editForm: LocationFormState;
    parentOptions: { value: string; label: string }[];
    onClose: () => void;
}) {
    return (
        <Card>
            <CardHeader>
                <CardTitle>Edit {location.code}</CardTitle>
            </CardHeader>
            <CardContent>
                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        editForm.put(route('admin.locations.update', location.id), {
                            onSuccess: onClose,
                        });
                    }}
                    className="grid gap-4 lg:grid-cols-4"
                >
                    <Input
                        label="Code"
                        value={editForm.data.code}
                        onChange={(event) => editForm.setData('code', event.target.value)}
                        error={editForm.errors.code}
                        required
                    />
                    <Input
                        label="Name"
                        value={editForm.data.name}
                        onChange={(event) => editForm.setData('name', event.target.value)}
                        error={editForm.errors.name}
                        required
                    />
                    <NativeSelect
                        label="Type"
                        value={editForm.data.type}
                        onChange={(event) => editForm.setData('type', event.target.value)}
                        options={locationTypeOptions}
                        error={editForm.errors.type}
                        required
                    />
                    <NativeSelect
                        label="Parent"
                        value={editForm.data.parent_id}
                        onChange={(event) => editForm.setData('parent_id', event.target.value)}
                        options={parentOptions.filter(
                            (option) => option.value !== String(location.id),
                        )}
                        error={editForm.errors.parent_id}
                    />
                    <Textarea
                        label="Address"
                        value={editForm.data.address}
                        onChange={(event) => editForm.setData('address', event.target.value)}
                        error={editForm.errors.address}
                        className="lg:col-span-2"
                    />
                    <Input
                        label="Latitude"
                        value={editForm.data.lat}
                        onChange={(event) => editForm.setData('lat', event.target.value)}
                        error={editForm.errors.lat}
                    />
                    <Input
                        label="Longitude"
                        value={editForm.data.lng}
                        onChange={(event) => editForm.setData('lng', event.target.value)}
                        error={editForm.errors.lng}
                    />
                    <div className="flex gap-2 lg:col-span-4">
                        <Button type="submit" loading={editForm.processing}>
                            Save
                        </Button>
                        <Button type="button" variant="secondary" onClick={onClose}>
                            Cancel
                        </Button>
                    </div>
                </form>
            </CardContent>
        </Card>
    );
}

export function LocationMoveFormCard({
    location,
    moveForm,
    parentOptions,
    onClose,
}: {
    location: LocationNode;
    moveForm: LocationMoveFormState;
    parentOptions: { value: string; label: string }[];
    onClose: () => void;
}) {
    return (
        <Card>
            <CardHeader>
                <CardTitle>Move {location.code}</CardTitle>
            </CardHeader>
            <CardContent>
                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        moveForm.post(route('admin.locations.move', location.id), {
                            onSuccess: onClose,
                        });
                    }}
                    className="flex flex-col gap-4 sm:flex-row sm:items-end"
                >
                    <NativeSelect
                        label="New parent"
                        value={moveForm.data.new_parent_id}
                        onChange={(event) => moveForm.setData('new_parent_id', event.target.value)}
                        options={parentOptions.filter(
                            (option) => option.value !== '' && option.value !== String(location.id),
                        )}
                        error={moveForm.errors.new_parent_id}
                        required
                    />
                    <div className="flex gap-2">
                        <Button type="submit" loading={moveForm.processing}>
                            Move
                        </Button>
                        <Button type="button" variant="secondary" onClick={onClose}>
                            Cancel
                        </Button>
                    </div>
                </form>
            </CardContent>
        </Card>
    );
}

export function LocationTree({
    tree,
    open,
    toggle,
    can,
    onEdit,
    onMove,
    onDelete,
}: {
    tree: LocationNode[];
    open: Set<number>;
    toggle: (id: number) => void;
    can: { create: boolean; update: boolean; delete: boolean };
    onEdit: (location: LocationNode) => void;
    onMove: (location: LocationNode) => void;
    onDelete: (location: LocationNode) => void;
}) {
    return (
        <Card>
            <CardHeader>
                <CardTitle>Topology Tree</CardTitle>
            </CardHeader>
            <CardContent>
                {tree.length === 0 ? (
                    <p className="text-sm text-surface-500 dark:text-surface-400">
                        No locations yet.
                    </p>
                ) : (
                    <ul className="space-y-2">
                        {tree.map((location) => (
                            <TreeItem
                                key={location.id}
                                location={location}
                                open={open}
                                toggle={toggle}
                                can={can}
                                onEdit={onEdit}
                                onMove={onMove}
                                onDelete={onDelete}
                            />
                        ))}
                    </ul>
                )}
            </CardContent>
        </Card>
    );
}

function TreeItem({
    location,
    open,
    toggle,
    can,
    onEdit,
    onMove,
    onDelete,
}: {
    location: LocationNode;
    open: Set<number>;
    toggle: (id: number) => void;
    can: { create: boolean; update: boolean; delete: boolean };
    onEdit: (location: LocationNode) => void;
    onMove: (location: LocationNode) => void;
    onDelete: (location: LocationNode) => void;
}) {
    const hasChildren = location.children.length > 0;

    return (
        <li>
            <div className="flex flex-col gap-3 rounded-lg border border-surface-200 bg-surface-50 p-3 dark:border-surface-800 dark:bg-surface-950 sm:flex-row sm:items-center sm:justify-between">
                <div className="flex items-center gap-3">
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={() => toggle(location.id)}
                        disabled={!hasChildren}
                        aria-label={open.has(location.id) ? 'Collapse location' : 'Expand location'}
                    >
                        {hasChildren ? (open.has(location.id) ? '-' : '+') : ' '}
                    </Button>
                    <div>
                        <div className="flex flex-wrap items-center gap-2">
                            <span className="font-semibold text-surface-900 dark:text-surface-100">
                                {location.code}
                            </span>
                            <span className="text-surface-700 dark:text-surface-300">
                                {location.name}
                            </span>
                            <Badge>{location.type}</Badge>
                            {!location.is_active && <Badge variant="warning">Inactive</Badge>}
                        </div>
                        <p className="mt-1 text-xs text-surface-500 dark:text-surface-400">
                            {location.path}
                        </p>
                    </div>
                </div>
                <div className="flex flex-wrap gap-2">
                    {can.update && (
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            onClick={() => onEdit(location)}
                        >
                            Edit
                        </Button>
                    )}
                    {can.update && (
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() => onMove(location)}
                        >
                            Move
                        </Button>
                    )}
                    {can.delete && (
                        <Button
                            type="button"
                            variant="danger"
                            size="sm"
                            onClick={() => onDelete(location)}
                        >
                            Delete
                        </Button>
                    )}
                </div>
            </div>
            {hasChildren && open.has(location.id) && (
                <ul className="ml-5 mt-2 space-y-2 border-l border-surface-200 pl-4 dark:border-surface-800">
                    {location.children.map((child) => (
                        <TreeItem
                            key={child.id}
                            location={child}
                            open={open}
                            toggle={toggle}
                            can={can}
                            onEdit={onEdit}
                            onMove={onMove}
                            onDelete={onDelete}
                        />
                    ))}
                </ul>
            )}
        </li>
    );
}
