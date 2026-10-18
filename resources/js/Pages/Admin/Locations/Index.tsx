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
import { useServerTable } from '@/hooks/useServerTable';
import { toPagination } from '@/lib/pagination';
import { ACTIVE_STATE, LOCATION_TYPE, labelOf, optionsOf } from '@/lib/statuses';
import type { Paginated } from '@/types';
import {
    LocationsMapView,
    type LocationType,
    type MapMarker,
} from '@/Components/composite/locations/LocationsMapView';

/* ---------- Page props ---------- */

interface LocationRow {
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

/** The server filters on `active` and `inactive`; the words come from the same map as every other status. */
const statusFilterOptions = [
    { value: '', label: 'Semua status' },
    { value: 'active', label: ACTIVE_STATE.true.label },
    { value: 'inactive', label: ACTIVE_STATE.false.label },
];

/* ---------- Page component ---------- */

export default function Index({ locations, tree, markers, filters, can }: LocationsProps) {
    /* -- View tab -- */
    const [viewTab, setViewTab] = useState<string>('map');

    /* -- Server table for list view -- */
    const { params, set, setMany, sortBy, sortDir, onSort, processing } = useServerTable({
        url: route('admin.locations.index'),
        filters,
        only: ['locations', 'tree', 'markers', 'filters', 'can'],
    });
    const hasFilters = Boolean(params.search || params.type || params.status);

    /* -- Every place, and which of them have places under them -- */
    const flat = useMemo(() => flatten(tree.data), [tree.data]);
    const parentIds = useMemo(
        () => new Set(flat.flatMap((loc) => (loc.parent_id === null ? [] : [loc.parent_id]))),
        [flat],
    );

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
            cell: (row) => <EnumBadge map={ACTIVE_STATE} value={row.is_active} />,
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
                <div className="flex flex-wrap items-center gap-1">
                    {can.update && (
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() => startEdit(row)}
                        >
                            Ubah
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
                            Pindahkan
                        </Button>
                    )}
                    {can.delete && (
                        <Button
                            type="button"
                            variant="destructive-outline"
                            size="sm"
                            onClick={() => setDeleteTarget(row)}
                            disabled={parentIds.has(row.id)}
                            title={
                                parentIds.has(row.id)
                                    ? 'Lokasi ini masih punya lokasi turunan.'
                                    : undefined
                            }
                        >
                            Hapus
                        </Button>
                    )}
                </div>
            ),
        },
    ];

    const createButton = can.create ? (
        <Button type="button" variant="primary" onClick={() => openCreate()}>
            Buat Lokasi
        </Button>
    ) : undefined;

    return (
        <AdminLayout title="Lokasi">
            <div className="space-y-6">
                <PageHeader
                    title="Lokasi"
                    subtitle="Wilayah, area, POP, rak, dan site tempat perangkat jaringan berada."
                    actions={createButton}
                />

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
                                    <NativeSelect
                                        label="Status"
                                        wrapperClassName="md:w-40"
                                        value={params.status ?? ''}
                                        onChange={(e) => set('status', e.target.value)}
                                        options={statusFilterOptions}
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
                    <DialogContent className="max-w-2xl">
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
                    <DialogContent className="max-w-2xl">
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
                                Hapus {deleteTarget?.code ?? 'lokasi'}?
                            </AlertDialogTitle>
                            <AlertDialogDescription>
                                Lokasi &quot;{deleteTarget?.name}&quot; akan dihapus. Tindakan ini
                                tidak dapat dibatalkan.
                            </AlertDialogDescription>
                        </AlertDialogHeader>
                        <AlertDialogFooter>
                            <AlertDialogCancel disabled={deleteProcessing}>Batal</AlertDialogCancel>
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

function flatten(locations: LocationNode[]): LocationNode[] {
    return locations.flatMap((loc) => [loc, ...flatten(loc.children)]);
}
