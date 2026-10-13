import type { FormEvent } from 'react';
import { useMemo, useRef, useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { DataTable, type DataTableColumn, PageHeader } from '@/Components/composite';
import { Tabs, TabList, Tab, TabPanel } from '@/Components/ui/Tabs';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/Components/ui/alert-dialog';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/Components/ui/dialog';
import { buttonVariants } from '@/Components/ui/Button';
import { Spinner } from '@/Components/ui/Spinner';
import { cn } from '@/lib/utils';
import { Badge, Button, Card, CardContent, Input, NativeSelect, Textarea } from '@/Components/ui';
import { useServerTable } from '@/hooks/useServerTable';
import { toPagination } from '@/lib/pagination';
import type { Paginated } from '@/types';
import {
    LocationsMapView,
    type MapMarker,
} from '@/Components/composite/locations/LocationsMapView';
import {
    type LocationNode,
    type LocationType,
    locationTypeOptions,
} from '@/Components/composite/locations/LocationShowSections';

/* ---------- Page props ---------- */

interface LocationRow {
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
    children: LocationRow[];
}

interface LocationsProps {
    locations: Paginated<LocationRow>;
    tree?: { data: LocationNode[] } | LocationNode[];
    markers: MapMarker[];
    filters: {
        search?: string | null;
        type?: string | null;
        status?: string | null;
        sort?: string | null;
        direction?: string | null;
        per_page?: string | number | null;
        page?: string | number | null;
    };
    can: { create: boolean; update: boolean; move: boolean; delete: boolean };
}

/* ---------- Form defaults ---------- */

const emptyLocation = {
    code: '',
    name: '',
    type: 'pop' as LocationType,
    parent_id: '',
    address: '',
    lat: '',
    lng: '',
    is_active: true,
};

const typeFilterOptions = [
    { value: '', label: 'Semua tipe' },
    { value: 'region', label: 'Region' },
    { value: 'area', label: 'Area' },
    { value: 'pop', label: 'POP' },
    { value: 'rack', label: 'Rack' },
    { value: 'site', label: 'Site' },
];

const statusFilterOptions = [
    { value: '', label: 'Semua status' },
    { value: 'active', label: 'Active' },
    { value: 'inactive', label: 'Inactive' },
];

/* ---------- Page component ---------- */

export default function Index({
    locations,
    tree: treeProp,
    markers,
    filters,
    can,
}: LocationsProps) {
    /* -- View tab -- */
    const [viewTab, setViewTab] = useState<string>('map');

    /* -- Server table for list view -- */
    const { params, set, sortBy, sortDir, onSort, processing } = useServerTable({
        url: route('admin.locations.index'),
        filters,
        only: ['locations', 'tree', 'markers', 'filters', 'can'],
    });

    /* -- Flatten tree for parent options -- */
    const treeSource = treeProp ?? locations;
    const tree = Array.isArray(treeSource) ? treeSource : treeSource.data;
    const flat = useMemo(() => flatten(tree), [tree]);

    const parentOptions = useMemo(
        () => [
            { value: '', label: 'No parent (region)' },
            ...flat.map((loc) => ({
                value: String(loc.id),
                label: loc.path ?? loc.code,
            })),
        ],
        [flat],
    );

    /* -- Create dialog -- */
    const [createOpen, setCreateOpen] = useState(false);
    const [createDraftCoords, setCreateDraftCoords] = useState<{
        lat: string;
        lng: string;
        type: LocationType;
    } | null>(null);
    const createForm = useForm(emptyLocation);

    const openCreate = (draftType?: LocationType, lat?: string, lng?: string) => {
        createForm.reset();
        if (draftType) createForm.setData('type', draftType);
        if (lat) createForm.setData('lat', lat);
        if (lng) createForm.setData('lng', lng);
        setCreateDraftCoords(lat && lng ? { lat, lng, type: draftType ?? 'pop' } : null);
        setCreateOpen(true);
    };

    const submitCreate = (e: FormEvent) => {
        e.preventDefault();
        createForm.post(route('admin.locations.store'), {
            onSuccess: () => {
                createForm.reset();
                setCreateOpen(false);
                setCreateDraftCoords(null);
            },
        });
    };

    /* -- Edit dialog -- */
    const [editTarget, setEditTarget] = useState<LocationRow | null>(null);
    const editForm = useForm(emptyLocation);
    const [editOrigCoords, setEditOrigCoords] = useState<{ lat: string; lng: string } | null>(null);

    const startEdit = (row: LocationRow) => {
        setEditTarget(row);
        setEditOrigCoords({
            lat: String(row.lat ?? ''),
            lng: String(row.lng ?? ''),
        });
        editForm.setData({
            code: row.code,
            name: row.name,
            type: row.type,
            parent_id: row.parent_id ? String(row.parent_id) : '',
            address: row.address ?? '',
            lat: row.lat != null ? String(row.lat) : '',
            lng: row.lng != null ? String(row.lng) : '',
            is_active: row.is_active,
        });
    };

    const submitEdit = (e: FormEvent) => {
        e.preventDefault();
        if (!editTarget) return;
        editForm.put(route('admin.locations.update', editTarget.id), {
            onSuccess: () => {
                setEditTarget(null);
                setEditOrigCoords(null);
            },
        });
    };

    const coordsChanged =
        editOrigCoords &&
        (editForm.data.lat !== editOrigCoords.lat || editForm.data.lng !== editOrigCoords.lng);

    /* -- Move dialog -- */
    const [moveTarget, setMoveTarget] = useState<LocationRow | null>(null);
    const moveForm = useForm({ new_parent_id: '' });

    const submitMove = (e: FormEvent) => {
        e.preventDefault();
        if (!moveTarget) return;
        moveForm.post(route('admin.locations.move', moveTarget.id), {
            onSuccess: () => setMoveTarget(null),
        });
    };

    /* -- Delete dialog -- */
    const [deleteTarget, setDeleteTarget] = useState<LocationRow | MapMarker | null>(null);
    const [deleteProcessing, setDeleteProcessing] = useState(false);
    const deleteInFlight = useRef(false);

    const confirmDelete = () => {
        if (!deleteTarget || deleteInFlight.current) return;
        deleteInFlight.current = true;
        setDeleteProcessing(true);
        router.delete(route('admin.locations.destroy', deleteTarget.id), {
            preserveScroll: true,
            onFinish: () => {
                deleteInFlight.current = false;
                setDeleteProcessing(false);
            },
            onSuccess: () => {
                setDeleteTarget(null);
            },
        });
    };

    /* -- Map callbacks -- */
    const handleMapClick = (lat: number, lng: number, type: 'pop' | 'site') => {
        openCreate(type, lat.toFixed(7), lng.toFixed(7));
    };

    const handleMarkerEdit = (marker: MapMarker) => {
        const row = flat.find((location) => location.id === marker.id);
        if (!row) return;

        startEdit(row);
    };

    const handleMarkerDelete = (marker: MapMarker) => {
        setDeleteTarget(marker);
    };

    /* -- DataTable columns -- */
    const columns: DataTableColumn<LocationRow>[] = useMemo(
        () => [
            {
                key: 'code',
                header: 'Code',
                sortable: true,
                sortKey: 'code',
                cell: (row) => <span className="font-semibold text-foreground">{row.code}</span>,
            },
            {
                key: 'name',
                header: 'Name',
                sortable: true,
                sortKey: 'name',
            },
            {
                key: 'type',
                header: 'Type',
                sortable: true,
                sortKey: 'type',
                cell: (row) => <Badge>{row.type.toUpperCase()}</Badge>,
            },
            {
                key: 'path',
                header: 'Path',
                sortable: true,
                sortKey: 'path',
                className: 'max-w-48 truncate text-xs text-muted-foreground',
            },
            {
                key: 'is_active',
                header: 'Status',
                sortable: true,
                sortKey: 'is_active',
                cell: (row) => (
                    <Badge variant={row.is_active ? 'success' : 'warning'}>
                        {row.is_active ? 'Active' : 'Inactive'}
                    </Badge>
                ),
            },
            {
                key: 'coordinates',
                header: 'Coordinates',
                cell: (row) =>
                    row.lat && row.lng ? (
                        <span className="text-xs text-muted-foreground">
                            {Number(row.lat).toFixed(4)}, {Number(row.lng).toFixed(4)}
                        </span>
                    ) : (
                        <span className="text-xs text-muted-foreground">-</span>
                    ),
            },
            {
                key: 'actions',
                header: 'Actions',
                cell: (row) => (
                    <div className="flex flex-wrap gap-1">
                        {can.update && (
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() => startEdit(row)}
                            >
                                Edit
                            </Button>
                        )}
                        {can.move && (
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() => {
                                    setMoveTarget(row);
                                    moveForm.setData('new_parent_id', '');
                                }}
                            >
                                Move
                            </Button>
                        )}
                        {can.delete && (
                            <Button
                                type="button"
                                variant="danger"
                                size="sm"
                                onClick={() => setDeleteTarget(row)}
                                disabled={row.children.length > 0}
                            >
                                Delete
                            </Button>
                        )}
                    </div>
                ),
            },
        ],
        // eslint-disable-next-line react-hooks/exhaustive-deps -- startEdit/moveForm are stable enough; including them causes unnecessary re-renders
        [can],
    );

    return (
        <AdminLayout title="Lokasi">
            <div className="space-y-6">
                <PageHeader
                    title="Lokasi"
                    subtitle="Region, area, POP, rack, and site topology."
                    actions={
                        can.create ? (
                            <Button type="button" variant="primary" onClick={() => openCreate()}>
                                Tambah Lokasi
                            </Button>
                        ) : undefined
                    }
                />

                {/* View switcher */}
                <Tabs value={viewTab} onValueChange={setViewTab}>
                    <TabList aria-label="Location view mode">
                        <Tab value="map">Peta</Tab>
                        <Tab value="table">Tabel</Tab>
                    </TabList>

                    {/* MAP TAB */}
                    <TabPanel value="map">
                        <LocationsMapView
                            markers={markers}
                            can={can}
                            onMarkerEdit={handleMarkerEdit}
                            onMarkerDelete={handleMarkerDelete}
                            onMapClick={can.create ? handleMapClick : undefined}
                        />
                    </TabPanel>

                    {/* TABLE TAB */}
                    <TabPanel value="table">
                        <Card>
                            <CardContent className="space-y-4 pt-6">
                                {/* Filter bar */}
                                <div className="flex flex-col gap-3 rounded-lg border bg-card p-3 md:flex-row md:items-end">
                                    <div className="min-w-48 flex-1">
                                        <Input
                                            label="Cari"
                                            value={params.search ?? ''}
                                            onChange={(e) => set('search', e.target.value)}
                                            placeholder="Code, name, path, address"
                                        />
                                    </div>
                                    <div className="w-full md:w-40">
                                        <NativeSelect
                                            label="Type"
                                            value={params.type ?? ''}
                                            onChange={(e) => set('type', e.target.value)}
                                            options={typeFilterOptions}
                                        />
                                    </div>
                                    <div className="w-full md:w-40">
                                        <NativeSelect
                                            label="Status"
                                            value={params.status ?? ''}
                                            onChange={(e) => set('status', e.target.value)}
                                            options={statusFilterOptions}
                                        />
                                    </div>
                                </div>

                                <DataTable
                                    columns={columns}
                                    pagination={toPagination(locations)}
                                    sortBy={sortBy}
                                    sortDir={sortDir}
                                    onSort={onSort}
                                    onPageChange={(page) => set('page', page)}
                                    onPerPageChange={(perPage) => set('per_page', perPage)}
                                    emptyTitle="Tidak ada lokasi"
                                    emptyDescription="Tidak ada lokasi yang sesuai dengan filter."
                                    loading={processing}
                                />
                            </CardContent>
                        </Card>
                    </TabPanel>
                </Tabs>

                {/* ---- CREATE DIALOG ---- */}
                <Dialog
                    open={createOpen}
                    onOpenChange={(open) => {
                        if (!open && !createForm.processing) {
                            setCreateOpen(false);
                            setCreateDraftCoords(null);
                        }
                    }}
                >
                    <DialogContent className="max-w-2xl">
                        <DialogHeader>
                            <DialogTitle>Tambah Lokasi</DialogTitle>
                            <DialogDescription>
                                {createDraftCoords
                                    ? `Koordinat: ${createDraftCoords.lat}, ${createDraftCoords.lng}`
                                    : 'Isi data lokasi baru.'}
                            </DialogDescription>
                        </DialogHeader>
                        <form onSubmit={submitCreate} className="grid gap-4 lg:grid-cols-2">
                            <Input
                                label="Code"
                                value={createForm.data.code}
                                onChange={(e) => createForm.setData('code', e.target.value)}
                                error={createForm.errors.code}
                                required
                                autoFocus
                            />
                            <Input
                                label="Name"
                                value={createForm.data.name}
                                onChange={(e) => createForm.setData('name', e.target.value)}
                                error={createForm.errors.name}
                                required
                            />
                            <NativeSelect
                                label="Type"
                                value={createForm.data.type}
                                onChange={(e) =>
                                    createForm.setData('type', e.target.value as LocationType)
                                }
                                options={locationTypeOptions}
                                error={createForm.errors.type}
                                required
                            />
                            <NativeSelect
                                label="Parent"
                                value={createForm.data.parent_id}
                                onChange={(e) => createForm.setData('parent_id', e.target.value)}
                                options={parentOptions}
                                error={createForm.errors.parent_id}
                            />
                            <Textarea
                                label="Address"
                                value={createForm.data.address}
                                onChange={(e) => createForm.setData('address', e.target.value)}
                                error={createForm.errors.address}
                                className="lg:col-span-2"
                            />
                            <Input
                                label="Latitude"
                                value={createForm.data.lat}
                                onChange={(e) => createForm.setData('lat', e.target.value)}
                                error={createForm.errors.lat}
                                inputMode="decimal"
                            />
                            <Input
                                label="Longitude"
                                value={createForm.data.lng}
                                onChange={(e) => createForm.setData('lng', e.target.value)}
                                error={createForm.errors.lng}
                                inputMode="decimal"
                            />
                            <DialogFooter className="lg:col-span-2">
                                <Button
                                    type="button"
                                    variant="secondary"
                                    onClick={() => {
                                        setCreateOpen(false);
                                        setCreateDraftCoords(null);
                                    }}
                                    disabled={createForm.processing}
                                >
                                    Cancel
                                </Button>
                                <Button
                                    type="submit"
                                    variant="primary"
                                    loading={createForm.processing}
                                >
                                    Create
                                </Button>
                            </DialogFooter>
                        </form>
                    </DialogContent>
                </Dialog>

                {/* ---- EDIT DIALOG ---- */}
                <Dialog
                    open={editTarget !== null}
                    onOpenChange={(open) => {
                        if (!open && !editForm.processing) {
                            setEditTarget(null);
                            setEditOrigCoords(null);
                        }
                    }}
                >
                    <DialogContent className="max-w-2xl">
                        <DialogHeader>
                            <DialogTitle>Edit {editTarget?.code ?? 'Location'}</DialogTitle>
                            <DialogDescription>
                                Update data lokasi. Perubahan berlaku setelah disimpan.
                            </DialogDescription>
                        </DialogHeader>
                        <form onSubmit={submitEdit} className="grid gap-4 lg:grid-cols-2">
                            <Input
                                label="Code"
                                value={editForm.data.code}
                                onChange={(e) => editForm.setData('code', e.target.value)}
                                error={editForm.errors.code}
                                required
                            />
                            <Input
                                label="Name"
                                value={editForm.data.name}
                                onChange={(e) => editForm.setData('name', e.target.value)}
                                error={editForm.errors.name}
                                required
                            />
                            <NativeSelect
                                label="Type"
                                value={editForm.data.type}
                                onChange={(e) =>
                                    editForm.setData('type', e.target.value as LocationType)
                                }
                                options={locationTypeOptions}
                                error={editForm.errors.type}
                                required
                            />
                            <NativeSelect
                                label="Parent"
                                value={editForm.data.parent_id}
                                onChange={(e) => editForm.setData('parent_id', e.target.value)}
                                options={parentOptions.filter(
                                    (opt) => opt.value !== String(editTarget?.id),
                                )}
                                error={editForm.errors.parent_id}
                            />
                            <Textarea
                                label="Address"
                                value={editForm.data.address}
                                onChange={(e) => editForm.setData('address', e.target.value)}
                                error={editForm.errors.address}
                                className="lg:col-span-2"
                            />
                            <Input
                                label="Latitude"
                                value={editForm.data.lat}
                                onChange={(e) => editForm.setData('lat', e.target.value)}
                                error={editForm.errors.lat}
                                inputMode="decimal"
                            />
                            <Input
                                label="Longitude"
                                value={editForm.data.lng}
                                onChange={(e) => editForm.setData('lng', e.target.value)}
                                error={editForm.errors.lng}
                                inputMode="decimal"
                            />

                            {/* Coordinate change confirmation */}
                            {coordsChanged && (
                                <div className="rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm lg:col-span-2 dark:border-amber-700 dark:bg-amber-950">
                                    <p className="font-medium text-amber-800 dark:text-amber-200">
                                        Koordinat berubah
                                    </p>
                                    <p className="mt-1 text-amber-700 dark:text-amber-300">
                                        Lama: {editOrigCoords?.lat || '-'},{' '}
                                        {editOrigCoords?.lng || '-'}
                                    </p>
                                    <p className="text-amber-700 dark:text-amber-300">
                                        Baru: {editForm.data.lat || '-'}, {editForm.data.lng || '-'}
                                    </p>
                                </div>
                            )}

                            <DialogFooter className="lg:col-span-2">
                                <Button
                                    type="button"
                                    variant="secondary"
                                    onClick={() => {
                                        setEditTarget(null);
                                        setEditOrigCoords(null);
                                    }}
                                    disabled={editForm.processing}
                                >
                                    Cancel
                                </Button>
                                <Button type="submit" loading={editForm.processing}>
                                    {coordsChanged ? 'Simpan & Konfirmasi Koordinat' : 'Simpan'}
                                </Button>
                            </DialogFooter>
                        </form>
                    </DialogContent>
                </Dialog>

                {/* ---- MOVE DIALOG ---- */}
                <Dialog
                    open={moveTarget !== null}
                    onOpenChange={(open) => {
                        if (!open && !moveForm.processing) {
                            setMoveTarget(null);
                        }
                    }}
                >
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>Pindah {moveTarget?.code ?? 'Location'}</DialogTitle>
                            <DialogDescription>
                                Pilih parent baru untuk lokasi ini.
                            </DialogDescription>
                        </DialogHeader>
                        <form onSubmit={submitMove}>
                            <NativeSelect
                                label="New parent"
                                value={moveForm.data.new_parent_id}
                                onChange={(e) => moveForm.setData('new_parent_id', e.target.value)}
                                options={parentOptions.filter(
                                    (opt) =>
                                        opt.value !== '' && opt.value !== String(moveTarget?.id),
                                )}
                                error={moveForm.errors.new_parent_id}
                                required
                            />
                            <DialogFooter className="mt-4">
                                <Button
                                    type="button"
                                    variant="secondary"
                                    onClick={() => setMoveTarget(null)}
                                    disabled={moveForm.processing}
                                >
                                    Cancel
                                </Button>
                                <Button type="submit" loading={moveForm.processing}>
                                    Pindah
                                </Button>
                            </DialogFooter>
                        </form>
                    </DialogContent>
                </Dialog>

                {/* ---- DELETE DIALOG ---- */}
                <AlertDialog
                    open={deleteTarget !== null}
                    onOpenChange={(open) => {
                        if (deleteInFlight.current && !open) return;
                        if (!open) setDeleteTarget(null);
                    }}
                >
                    <AlertDialogContent>
                        <AlertDialogHeader>
                            <AlertDialogTitle>
                                Hapus {deleteTarget?.code ?? 'location'}?
                            </AlertDialogTitle>
                            <AlertDialogDescription>
                                Lokasi &quot;{deleteTarget?.name}&quot; akan dihapus permanen.
                                Tindakan ini tidak dapat dibatalkan.
                            </AlertDialogDescription>
                        </AlertDialogHeader>
                        <AlertDialogFooter>
                            <AlertDialogCancel disabled={deleteProcessing}>
                                Cancel
                            </AlertDialogCancel>
                            <AlertDialogAction
                                className={cn(buttonVariants({ variant: 'destructive' }), 'gap-2')}
                                onClick={(e) => {
                                    e.preventDefault();
                                    confirmDelete();
                                }}
                                disabled={deleteProcessing}
                            >
                                {deleteProcessing ? <Spinner size="sm" /> : null}
                                Hapus
                            </AlertDialogAction>
                        </AlertDialogFooter>
                    </AlertDialogContent>
                </AlertDialog>
            </div>
        </AdminLayout>
    );
}

/* ---------- Helpers ---------- */

function flatten(locations: LocationRow[]): LocationRow[] {
    return locations.flatMap((loc) => [loc, ...flatten(loc.children)]);
}
