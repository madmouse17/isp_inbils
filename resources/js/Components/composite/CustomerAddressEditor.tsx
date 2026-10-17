import type { FormEvent } from 'react';
import { useEffect, useRef, useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import {
    Button,
    Card,
    CardContent,
    CardHeader,
    CardTitle,
    Input,
    MapPicker,
    Switch,
    Textarea,
    type GeocodeResult,
} from '@/Components/ui';
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
import { buttonVariants } from '@/Components/ui/Button';
import { Spinner } from '@/Components/ui/Spinner';
import { cn } from '@/lib/utils';
import { useMapDefault } from '@/hooks/useMapDefault';
import {
    IndonesiaRegionFields,
    type IndonesiaRegionOptions,
    type IndonesiaRegionValue,
} from '@/Components/composite';
import type { CustomerAddress } from '@/types/models';

interface CustomerAddressEditorProps {
    customerId: string;
    addresses: CustomerAddress[];
    regions: IndonesiaRegionOptions;
    geocodeResults?: GeocodeResult[];
    geocodeIndex?: number;
    canManage: boolean;
}

type AddressForm = {
    id: string | null;
    label: string;
    address: string;
    city: string;
    postal_code: string;
    province_code: string;
    city_code: string;
    district_code: string;
    village_code: string;
    lat: string;
    lng: string;
    is_installation_point: boolean;
    is_primary: boolean;
    notes: string;
};

const emptyForm = (): AddressForm => ({
    id: null,
    label: '',
    address: '',
    city: '',
    postal_code: '',
    province_code: '',
    city_code: '',
    district_code: '',
    village_code: '',
    lat: '',
    lng: '',
    is_installation_point: false,
    is_primary: false,
    notes: '',
});

const regionCodes = (form: AddressForm) => ({
    region_provinces: form.province_code ? [form.province_code] : [],
    region_cities: form.city_code ? [form.city_code] : [],
    region_districts: form.district_code ? [form.district_code] : [],
});

export function CustomerAddressEditor({
    customerId,
    addresses,
    regions,
    geocodeResults = [],
    geocodeIndex,
    canManage,
}: CustomerAddressEditorProps) {
    const mapDefault = useMapDefault();
    const {
        data,
        setData,
        post,
        put,
        delete: destroy,
        processing,
        errors,
        reset,
    } = useForm<AddressForm>(emptyForm());

    // Editing state lives outside the Inertia form so it survives partial reloads.
    const [editingId, setEditingId] = useState<string | null>(null);
    const [deleteTarget, setDeleteTarget] = useState<CustomerAddress | null>(null);
    const [deleteProcessing, setDeleteProcessing] = useState(false);
    const [regionLoading, setRegionLoading] = useState(false);
    const [geocodeSearching, setGeocodeSearching] = useState(false);
    // Stale guards: ignore finishing events from superseded requests.
    const regionRequestRef = useRef(0);
    const geocodeRequestRef = useRef(0);
    const mountedRef = useRef(true);

    useEffect(() => {
        mountedRef.current = true;
        return () => {
            mountedRef.current = false;
        };
    }, []);

    const startCreate = () => {
        setEditingId(null);
        setData(emptyForm());
    };

    const startEdit = (row: CustomerAddress) => {
        setEditingId(row.id);
        setData('id', row.id);
        setData('label', row.label ?? '');
        setData('address', row.address ?? '');
        setData('city', row.city ?? '');
        setData('postal_code', row.postal_code ?? '');
        setData('province_code', row.province_code ?? '');
        setData('city_code', row.city_code ?? '');
        setData('district_code', row.district_code ?? '');
        setData('village_code', row.village_code ?? '');
        setData('lat', row.lat === null || row.lat === undefined ? '' : String(row.lat));
        setData('lng', row.lng === null || row.lng === undefined ? '' : String(row.lng));
        setData('is_installation_point', Boolean(row.is_installation_point));
        setData('is_primary', Boolean(row.is_primary));
        setData('notes', row.notes ?? '');
    };

    const cancelEdit = () => {
        setEditingId(null);
        reset();
    };

    const reloadRegions = (form: AddressForm) => {
        const requestId = ++regionRequestRef.current;
        setRegionLoading(true);
        router.get(route('admin.customers.edit', customerId), regionCodes(form), {
            only: ['regions'],
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onFinish: () => {
                if (mountedRef.current && regionRequestRef.current === requestId) {
                    setRegionLoading(false);
                }
            },
        });
    };

    const updateRegion = (region: IndonesiaRegionValue) => {
        setData('province_code', region.province_code);
        setData('city_code', region.city_code);
        setData('district_code', region.district_code);
        setData('village_code', region.village_code);
        setData('city', region.city);
        reloadRegions({ ...data, ...region });
    };

    const updateCoordinates = (latitude: number, longitude: number) => {
        setData((current) => ({
            ...current,
            lat: latitude.toFixed(7),
            lng: longitude.toFixed(7),
        }));
    };

    const searchLocation = (query: string) => {
        const requestId = ++geocodeRequestRef.current;
        setGeocodeSearching(true);
        router.get(
            route('admin.customers.edit', customerId),
            { geocode_query: query, geocode_index: 0 },
            {
                only: ['geocodeResults', 'geocodeIndex'],
                preserveState: true,
                preserveScroll: true,
                replace: true,
                onFinish: () => {
                    if (mountedRef.current && geocodeRequestRef.current === requestId) {
                        setGeocodeSearching(false);
                    }
                },
            },
        );
    };

    const selectGeocodeResult = (result: GeocodeResult) => {
        setData('postal_code', result.postal_code);
        setData('lat', Number(result.lat).toFixed(7));
        setData('lng', Number(result.lng).toFixed(7));
        setData('province_code', result.province_code);
        setData('city_code', result.city_code);
        setData('district_code', result.district_code);
        setData('village_code', result.village_code);
        setData('city', result.city);
        reloadRegions({
            ...data,
            province_code: result.province_code,
            city_code: result.city_code,
            district_code: result.district_code,
        });
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (processing) return;

        if (editingId) {
            put(route('admin.customers.addresses.update', [customerId, editingId]), {
                preserveScroll: true,
                onSuccess: () => cancelEdit(),
            });
        } else {
            post(route('admin.customers.addresses.store', customerId), {
                preserveScroll: true,
                onSuccess: () => cancelEdit(),
            });
        }
    };

    const confirmDelete = () => {
        if (!deleteTarget) return;
        if (processing || deleteProcessing) return;
        setDeleteProcessing(true);
        destroy(route('admin.customers.addresses.destroy', [customerId, deleteTarget.id]), {
            preserveScroll: true,
            onFinish: () => setDeleteProcessing(false),
            onSuccess: () => {
                setDeleteTarget(null);
                setDeleteProcessing(false);
            },
        });
    };

    return (
        <div className="space-y-4">
            <Card>
                <CardHeader className="space-y-0 flex-row flex-wrap items-center justify-between">
                    <CardTitle>Alamat</CardTitle>
                    {canManage ? (
                        <Button
                            type="button"
                            variant="primary"
                            size="sm"
                            onClick={startCreate}
                            disabled={processing}
                        >
                            Tambah alamat
                        </Button>
                    ) : null}
                </CardHeader>
                <CardContent className="space-y-3">
                    {addresses.length === 0 ? (
                        <p className="py-6 text-center text-sm text-muted-foreground">
                            {canManage
                                ? 'Belum ada alamat. Tambahkan alamat pertama lewat formulir di bawah.'
                                : 'Belum ada alamat.'}
                        </p>
                    ) : (
                        addresses.map((row) => (
                            <div
                                key={row.id}
                                className="flex flex-wrap items-center justify-between gap-2 rounded-lg border p-3"
                            >
                                <div className="min-w-0">
                                    <p className="text-sm font-medium">
                                        {row.label}
                                        {row.is_primary ? (
                                            <span className="ml-2 text-xs text-muted-foreground">
                                                (utama)
                                            </span>
                                        ) : null}
                                        {row.is_installation_point ? (
                                            <span className="ml-2 text-xs text-muted-foreground">
                                                (pemasangan)
                                            </span>
                                        ) : null}
                                    </p>
                                    <p className="text-sm text-muted-foreground">
                                        {[
                                            row.village_name,
                                            row.district_name,
                                            row.city_name,
                                            row.province_name,
                                        ]
                                            .filter(Boolean)
                                            .join(', ') || row.address}
                                    </p>
                                </div>
                                {canManage ? (
                                    <div className="flex gap-2">
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            onClick={() => startEdit(row)}
                                            disabled={processing}
                                        >
                                            Ubah
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="danger"
                                            size="sm"
                                            onClick={() => setDeleteTarget(row)}
                                            disabled={processing}
                                        >
                                            Hapus
                                        </Button>
                                    </div>
                                ) : null}
                            </div>
                        ))
                    )}
                </CardContent>
            </Card>

            {canManage ? (
                <Card>
                    <CardHeader>
                        <CardTitle>{editingId ? 'Ubah Alamat' : 'Tambah Alamat'}</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <form
                            onSubmit={submit}
                            className="space-y-4"
                            aria-busy={processing || regionLoading}
                        >
                            <div className="grid gap-4 md:grid-cols-2">
                                <Input
                                    label="Label"
                                    value={data.label}
                                    placeholder="Rumah / Kantor / Pemasangan"
                                    onChange={(e) => setData('label', e.target.value)}
                                    error={errors.label}
                                    required
                                />
                                <MapPicker
                                    latitude={data.lat}
                                    defaultCenter={mapDefault.center}
                                    defaultZoom={mapDefault.zoom}
                                    longitude={data.lng}
                                    onChange={updateCoordinates}
                                    latitudeError={errors.lat}
                                    longitudeError={errors.lng}
                                    searchResults={geocodeIndex === 0 ? geocodeResults : []}
                                    searching={geocodeSearching}
                                    onSearch={searchLocation}
                                    onSelectSearchResult={selectGeocodeResult}
                                />
                                <IndonesiaRegionFields
                                    idPrefix="customer-address-editor"
                                    value={data}
                                    options={regions}
                                    onChange={updateRegion}
                                    errors={errors}
                                />
                                {regionLoading ? (
                                    <p
                                        className="text-sm text-muted-foreground md:col-span-2"
                                        role="status"
                                    >
                                        Memuat pilihan wilayah...
                                    </p>
                                ) : null}
                                <Textarea
                                    className="md:col-span-2"
                                    label="Alamat"
                                    value={data.address}
                                    onChange={(e) => setData('address', e.target.value)}
                                    error={errors.address}
                                    required
                                    rows={2}
                                />
                                <Input
                                    label="Kode pos"
                                    value={data.postal_code}
                                    onChange={(e) => setData('postal_code', e.target.value)}
                                    error={errors.postal_code}
                                />
                                <div className="flex flex-wrap items-end gap-4">
                                    <Switch
                                        label="Alamat utama"
                                        checked={data.is_primary}
                                        onCheckedChange={(checked) =>
                                            setData('is_primary', checked)
                                        }
                                    />
                                    <Switch
                                        label="Alamat pemasangan"
                                        checked={data.is_installation_point}
                                        onCheckedChange={(checked) =>
                                            setData('is_installation_point', checked)
                                        }
                                    />
                                </div>
                                <Textarea
                                    className="md:col-span-2"
                                    label="Catatan"
                                    value={data.notes}
                                    onChange={(e) => setData('notes', e.target.value)}
                                    error={errors.notes}
                                    rows={2}
                                />
                            </div>
                            <div className="flex justify-end gap-2">
                                <Button
                                    type="button"
                                    variant="secondary"
                                    onClick={cancelEdit}
                                    disabled={processing}
                                >
                                    Batal
                                </Button>
                                <Button
                                    type="submit"
                                    variant="primary"
                                    loading={processing}
                                    disabled={processing}
                                >
                                    {editingId ? 'Simpan' : 'Tambah'}
                                </Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>
            ) : null}

            <AlertDialog
                open={deleteTarget !== null}
                onOpenChange={(open) => {
                    if (!open && !deleteProcessing) setDeleteTarget(null);
                }}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            Hapus alamat {deleteTarget?.label ? `"${deleteTarget.label}"` : ''}?
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            Alamat ini akan dihapus permanen dari pelanggan.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel disabled={deleteProcessing}>Batal</AlertDialogCancel>
                        <AlertDialogAction
                            className={cn(buttonVariants({ variant: 'destructive' }), 'gap-2')}
                            onClick={(event) => {
                                event.preventDefault();
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
    );
}
