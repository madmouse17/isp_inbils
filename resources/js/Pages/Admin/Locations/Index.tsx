import type { FormEvent } from 'react';
import { useMemo, useRef, useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import {
    DataTable,
    DataTableActions,
    type DataTableColumn,
    PageHeader,
    StatusFilter,
} from '@/Components/composite';
import { ConfirmDialog } from '@/Components/composite/ConfirmDialog';
import { UsageLine } from '@/Components/composite/UsageLine';
import { Tabs, TabList, Tab, TabPanel } from '@/Components/ui/Tabs';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/Components/ui/dialog';
import {
    Alert,
    AlertDescription,
    AlertTitle,
    Button,
    Card,
    CardContent,
    EmptyCell,
    EnumBadge,
    Input,
    NativeSelect,
    SearchSelect,
    Textarea,
} from '@/Components/ui';
import type { SearchSelectOption } from '@/Components/ui';
import { LatLngMapPicker } from '@/Components/composite/LatLngMapPicker';
import { useMapDefault } from '@/hooks/useMapDefault';
import { useServerTable } from '@/hooks/useServerTable';
import { toPagination } from '@/lib/pagination';
import {
    LIFECYCLE_STATE,
    LOCATION_TYPE,
    labelOf,
    lifecycleStateOf,
    optionsOf,
} from '@/lib/statuses';
import type { Paginated } from '@/types';
import type { LifecycleFields } from '@/types/models';
import {
    LocationsMapView,
    type LocationType,
    type MapMarker,
} from '@/Components/composite/locations/LocationsMapView';

/* ---------- Page props ---------- */

interface LocationRow extends LifecycleFields {
    id: string;
    parent_id: string | null;
    code: string;
    name: string;
    type: LocationType;
    path: string | null;
    address: string | null;
    lat: string | null;
    lng: string | null;
    is_active: boolean;
}

/** A place of the `tree` prop. The rows of the table carry no children: the tree is where they are found. */
interface LocationNode extends LocationRow {
    children: LocationNode[];
}

interface LocationsProps {
    locations: Paginated<LocationRow>;
    tree: { data: LocationNode[] };
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

const typeOptions = optionsOf(LOCATION_TYPE);

const typeFilterOptions = [{ value: '', label: 'Semua tipe' }, ...typeOptions];

/* ---------- Page component ---------- */

export default function Index({ locations, tree, markers, filters, can }: LocationsProps) {
    /* -- View tab -- */
    // A company that has not placed any location opens on the table: an empty map teaches nothing.
    const [viewTab, setViewTab] = useState<string>(markers.length === 0 ? 'table' : 'map');

    /* -- Server table for list view -- */
    const { params, set, setMany, sortBy, sortDir, onSort, processing } = useServerTable({
        url: route('admin.locations.index'),
        filters,
        only: ['locations', 'tree', 'markers', 'filters', 'can'],
    });
    const hasFilters = Boolean(params.search || params.type || params.status);

    /* -- Every place of the tree -- */
    const flat = useMemo(() => flatten(tree.data), [tree.data]);
    const withoutCoordinates = useMemo(
        () => flat.filter((loc) => !(loc.lat && loc.lng)).length,
        [flat],
    );
    const mapDefault = useMapDefault();

    const parentOptions = useMemo<SearchSelectOption[]>(
        () =>
            flat.map((loc) => ({
                value: String(loc.id),
                label: loc.path ?? loc.code,
                description: `${labelOf(LOCATION_TYPE, loc.type)} - ${loc.name}`,
            })),
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
            parent_id: row.parent_id ?? '',
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

    /* -- Delete from the map: a table row deletes through its own menu -- */
    const [markerToDelete, setMarkerToDelete] = useState<MapMarker | null>(null);
    const [deleteProcessing, setDeleteProcessing] = useState(false);
    const [deleteError, setDeleteError] = useState<string>();
    const deleteInFlight = useRef(false);

    const closeDelete = () => {
        setMarkerToDelete(null);
        setDeleteError(undefined);
    };

    const confirmDelete = () => {
        if (!markerToDelete || deleteInFlight.current) return;
        deleteInFlight.current = true;
        setDeleteProcessing(true);
        setDeleteError(undefined);
        router.delete(route('admin.locations.destroy', markerToDelete.id), {
            preserveScroll: true,
            onFinish: () => {
                deleteInFlight.current = false;
                setDeleteProcessing(false);
            },
            onSuccess: closeDelete,
            onError: (errors) => setDeleteError(errors.lifecycle ?? Object.values(errors)[0]),
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
        setMarkerToDelete(marker);
    };

    /* -- DataTable columns -- */
    const columns: DataTableColumn<LocationRow>[] = [
        {
            key: 'code',
            header: 'Kode',
            sortable: true,
            sortKey: 'code',
            cell: (row) => <span className="font-semibold text-foreground">{row.code}</span>,
        },
        {
            key: 'name',
            header: 'Nama',
            sortable: true,
            sortKey: 'name',
            cell: (row) => (
                <div className="space-y-0.5 max-w-xs">
                    <p className={row.deleted_at ? 'text-muted-foreground' : undefined}>
                        {row.name}
                    </p>
                    <UsageLine references={row.references} />
                </div>
            ),
        },
        {
            key: 'type',
            header: 'Tipe',
            sortable: true,
            sortKey: 'type',
            cell: (row) => <EnumBadge map={LOCATION_TYPE} value={row.type} />,
        },
        {
            key: 'path',
            header: 'Jalur',
            sortable: true,
            sortKey: 'path',
            className: 'max-w-48 truncate text-xs text-muted-foreground',
        },
        {
            key: 'is_active',
            header: 'Status',
            sortable: true,
            sortKey: 'is_active',
            cell: (row) => <EnumBadge map={LIFECYCLE_STATE} value={lifecycleStateOf(row)} />,
        },
        {
            key: 'coordinates',
            header: 'Koordinat',
            cell: (row) =>
                row.lat && row.lng ? (
                    <span className="text-xs text-muted-foreground tabular-nums">
                        {Number(row.lat).toFixed(4)}, {Number(row.lng).toFixed(4)}
                    </span>
                ) : (
                    <EmptyCell />
                ),
        },
        {
            key: 'actions',
            header: 'Aksi',
            cell: (row) => (
                <div className="flex items-center gap-1">
                    {can.move && !row.deleted_at && (
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            onClick={() => {
                                setMoveTarget(row);
                                moveForm.setData('new_parent_id', '');
                            }}
                        >
                            Pindahkan
                        </Button>
                    )}
                    <DataTableActions
                        onEdit={can.update ? () => startEdit(row) : undefined}
                        lifecycle={{
                            row,
                            name: row.name,
                            noun: 'Lokasi',
                            routeBase: 'admin.locations',
                            can: { update: can.update, delete: can.delete },
                        }}
                    />
                </div>
            ),
        },
    ];

    const createButton = can.create ? (
        <Button type="button" variant="primary" onClick={() => openCreate()}>
            Buat Lokasi
        </Button>
    ) : undefined;

    // A deleted location has no edit, move or delete route, so the map offers none for the markers of a view that lists them.
    const mapCan =
        params.status === 'dihapus' || params.status === 'semua'
            ? { ...can, update: false, move: false, delete: false }
            : can;

    return (
        <AdminLayout title="Lokasi">
            <div className="space-y-6">
                <PageHeader
                    title="Lokasi"
                    subtitle="Wilayah, area, POP, rak, dan site tempat perangkat jaringan berada."
                    actions={createButton}
                />

                {withoutCoordinates > 0 && (
                    <p className="text-sm text-muted-foreground">
                        {withoutCoordinates} lokasi belum punya koordinat. Titik muncul di peta
                        setelah lintang dan bujurnya diisi.
                    </p>
                )}

                {/* View switcher */}
                <Tabs value={viewTab} onValueChange={setViewTab}>
                    <TabList aria-label="Tampilan lokasi">
                        <Tab value="map">Peta</Tab>
                        <Tab value="table">Tabel</Tab>
                    </TabList>

                    {/* MAP TAB */}
                    <TabPanel value="map">
                        <LocationsMapView
                            markers={markers}
                            can={mapCan}
                            onMarkerEdit={handleMarkerEdit}
                            onMarkerDelete={handleMarkerDelete}
                            onMapClick={can.create ? handleMapClick : undefined}
                            // Leaflet's panes and the map's own controls carry z-indexes up to 1100: isolated, they stay under the dialogs and toasts.
                            className="isolate"
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
                                            placeholder="Kode, nama, jalur, atau alamat"
                                        />
                                    </div>
                                    <NativeSelect
                                        label="Tipe"
                                        wrapperClassName="md:w-40"
                                        value={params.type ?? ''}
                                        onChange={(e) => set('type', e.target.value)}
                                        options={typeFilterOptions}
                                    />
                                    <StatusFilter
                                        id="location-status"
                                        value={params.status ?? ''}
                                        onChange={(value) => set('status', value)}
                                        deactivatable
                                        wrapperClassName="md:w-52"
                                    />
                                </div>

                                <DataTable
                                    columns={columns}
                                    pagination={toPagination(locations)}
                                    sortBy={sortBy}
                                    sortDir={sortDir}
                                    onSort={onSort}
                                    onPageChange={(page) => set('page', page)}
                                    onPerPageChange={(perPage) => set('per_page', perPage)}
                                    emptyTitle="Belum ada lokasi"
                                    emptyDescription="Buat wilayah lebih dulu, lalu area, POP, rak, dan site di bawahnya."
                                    emptyAction={createButton}
                                    hasFilters={hasFilters}
                                    onResetFilters={() =>
                                        setMany({ search: '', type: '', status: '' })
                                    }
                                    itemLabel="lokasi"
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
                    {/* With the map the form is taller than a laptop screen: it scrolls inside the dialog. */}
                    <DialogContent className="max-h-[calc(100dvh-2rem)] max-w-2xl overflow-y-auto">
                        <DialogHeader>
                            <DialogTitle>Buat Lokasi</DialogTitle>
                            <DialogDescription>
                                {createDraftCoords
                                    ? `Koordinat: ${createDraftCoords.lat}, ${createDraftCoords.lng}`
                                    : 'Isi data lokasi baru.'}
                            </DialogDescription>
                        </DialogHeader>
                        <form onSubmit={submitCreate} className="grid gap-4 lg:grid-cols-2">
                            <Input
                                label="Kode"
                                value={createForm.data.code}
                                onChange={(e) => createForm.setData('code', e.target.value)}
                                error={createForm.errors.code}
                                required
                                autoFocus
                            />
                            <Input
                                label="Nama"
                                value={createForm.data.name}
                                onChange={(e) => createForm.setData('name', e.target.value)}
                                error={createForm.errors.name}
                                required
                            />
                            <NativeSelect
                                label="Tipe"
                                value={createForm.data.type}
                                onChange={(e) =>
                                    createForm.setData('type', e.target.value as LocationType)
                                }
                                options={typeOptions}
                                error={createForm.errors.type}
                                required
                            />
                            <SearchSelect
                                label="Induk"
                                value={createForm.data.parent_id}
                                onChange={(value) => createForm.setData('parent_id', value)}
                                options={parentOptions}
                                placeholder="Cari kode atau jalur lokasi"
                                emptyText="Tidak ada lokasi yang cocok."
                                hint="Kosongkan untuk lokasi bertipe Wilayah."
                                error={createForm.errors.parent_id}
                            />
                            <Textarea
                                label="Alamat"
                                value={createForm.data.address}
                                onChange={(e) => createForm.setData('address', e.target.value)}
                                error={createForm.errors.address}
                                className="lg:col-span-2"
                            />
                            <Input
                                label="Lintang"
                                value={createForm.data.lat}
                                onChange={(e) => createForm.setData('lat', e.target.value)}
                                error={createForm.errors.lat}
                                inputMode="decimal"
                            />
                            <Input
                                label="Bujur"
                                value={createForm.data.lng}
                                onChange={(e) => createForm.setData('lng', e.target.value)}
                                error={createForm.errors.lng}
                                inputMode="decimal"
                            />
                            <LatLngMapPicker
                                // isolate: the map keeps its own z-index layers below the dialog.
                                className="isolate lg:col-span-2"
                                lat={createForm.data.lat}
                                lng={createForm.data.lng}
                                onChange={({ lat, lng }) => {
                                    createForm.setData((current) => ({ ...current, lat, lng }));
                                }}
                                defaultCenter={mapDefault.center}
                                defaultZoom={mapDefault.zoom}
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
                                    Batal
                                </Button>
                                <Button
                                    type="submit"
                                    variant="primary"
                                    loading={createForm.processing}
                                >
                                    Simpan
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
                    <DialogContent className="max-h-[calc(100dvh-2rem)] max-w-2xl overflow-y-auto">
                        <DialogHeader>
                            <DialogTitle>Ubah {editTarget?.code ?? 'lokasi'}</DialogTitle>
                            <DialogDescription>
                                Perubahan berlaku setelah disimpan.
                            </DialogDescription>
                        </DialogHeader>
                        <form onSubmit={submitEdit} className="grid gap-4 lg:grid-cols-2">
                            <Input
                                label="Kode"
                                value={editForm.data.code}
                                onChange={(e) => editForm.setData('code', e.target.value)}
                                error={editForm.errors.code}
                                required
                            />
                            <Input
                                label="Nama"
                                value={editForm.data.name}
                                onChange={(e) => editForm.setData('name', e.target.value)}
                                error={editForm.errors.name}
                                required
                            />
                            <NativeSelect
                                label="Tipe"
                                value={editForm.data.type}
                                onChange={(e) =>
                                    editForm.setData('type', e.target.value as LocationType)
                                }
                                options={typeOptions}
                                error={editForm.errors.type}
                                required
                            />
                            <SearchSelect
                                label="Induk"
                                value={editForm.data.parent_id}
                                onChange={(value) => editForm.setData('parent_id', value)}
                                options={parentOptions.filter(
                                    (opt) => opt.value !== String(editTarget?.id),
                                )}
                                placeholder="Cari kode atau jalur lokasi"
                                emptyText="Tidak ada lokasi yang cocok."
                                hint="Kosongkan untuk lokasi bertipe Wilayah."
                                error={editForm.errors.parent_id}
                            />
                            <Textarea
                                label="Alamat"
                                value={editForm.data.address}
                                onChange={(e) => editForm.setData('address', e.target.value)}
                                error={editForm.errors.address}
                                className="lg:col-span-2"
                            />
                            <Input
                                label="Lintang"
                                value={editForm.data.lat}
                                onChange={(e) => editForm.setData('lat', e.target.value)}
                                error={editForm.errors.lat}
                                inputMode="decimal"
                            />
                            <Input
                                label="Bujur"
                                value={editForm.data.lng}
                                onChange={(e) => editForm.setData('lng', e.target.value)}
                                error={editForm.errors.lng}
                                inputMode="decimal"
                            />
                            <LatLngMapPicker
                                className="isolate lg:col-span-2"
                                lat={editForm.data.lat}
                                lng={editForm.data.lng}
                                onChange={({ lat, lng }) => {
                                    editForm.setData((current) => ({ ...current, lat, lng }));
                                }}
                                defaultCenter={mapDefault.center}
                                defaultZoom={mapDefault.zoom}
                            />

                            {/* Coordinate change confirmation */}
                            {coordsChanged && (
                                <Alert variant="warning" role="status" className="lg:col-span-2">
                                    <AlertTitle>Koordinat berubah</AlertTitle>
                                    <AlertDescription>
                                        <span className="block">
                                            Lama: {editOrigCoords?.lat || '-'},{' '}
                                            {editOrigCoords?.lng || '-'}
                                        </span>
                                        <span className="block">
                                            Baru: {editForm.data.lat || '-'},{' '}
                                            {editForm.data.lng || '-'}
                                        </span>
                                    </AlertDescription>
                                </Alert>
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
                                    Batal
                                </Button>
                                <Button type="submit" loading={editForm.processing}>
                                    {coordsChanged ? 'Simpan dan konfirmasi koordinat' : 'Simpan'}
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
                            <DialogTitle>Pindahkan {moveTarget?.code ?? 'lokasi'}</DialogTitle>
                            <DialogDescription>
                                Pilih induk baru untuk lokasi ini.
                            </DialogDescription>
                        </DialogHeader>
                        <form onSubmit={submitMove}>
                            <SearchSelect
                                label="Induk baru"
                                value={moveForm.data.new_parent_id}
                                onChange={(value) => moveForm.setData('new_parent_id', value)}
                                options={parentOptions.filter(
                                    (opt) => opt.value !== String(moveTarget?.id),
                                )}
                                placeholder="Cari kode atau jalur lokasi"
                                emptyText="Tidak ada lokasi yang cocok."
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
                                    Batal
                                </Button>
                                <Button
                                    type="submit"
                                    loading={moveForm.processing}
                                    disabled={moveForm.data.new_parent_id === ''}
                                >
                                    Pindahkan
                                </Button>
                            </DialogFooter>
                        </form>
                    </DialogContent>
                </Dialog>

                {/* ---- DELETE FROM THE MAP ---- */}
                <ConfirmDialog
                    open={markerToDelete !== null}
                    title={`Hapus permanen ${markerToDelete?.code ?? 'lokasi'}?`}
                    description={
                        markerToDelete
                            ? `Lokasi "${markerToDelete.name}" hanya terhapus bila belum pernah dipakai data lain, dan penghapusan permanen tidak bisa dibatalkan. Lokasi yang sudah dipakai bisa dinonaktifkan dari tab Tabel.`
                            : ''
                    }
                    confirmLabel="Hapus permanen"
                    tone="danger"
                    processing={deleteProcessing}
                    error={deleteError}
                    onConfirm={confirmDelete}
                    onClose={closeDelete}
                />
            </div>
        </AdminLayout>
    );
}

/* ---------- Helpers ---------- */

function flatten(locations: LocationNode[]): LocationNode[] {
    return locations.flatMap((loc) => [loc, ...flatten(loc.children)]);
}
