import type { FormEvent } from 'react';
import { useState } from 'react';
import { Link, router, useForm } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import {
    DataTable,
    DataTableActions,
    IndonesiaRegionFields,
    PageHeader,
    StatusFilter,
    type DataTableColumn,
    type IndonesiaRegionOptions,
    type IndonesiaRegionValue,
} from '@/Components/composite';
import { UsageLine } from '@/Components/composite/UsageLine';
import {
    Button,
    Card,
    CardContent,
    EmptyCell,
    EnumBadge,
    Input,
    MapPicker,
    Switch,
    Textarea,
    Modal,
    type GeocodeResult,
} from '@/Components/ui';
import { useServerTable } from '@/hooks/useServerTable';
import { toPagination } from '@/lib/pagination';
import { LIFECYCLE_STATE } from '@/lib/statuses';
import type { Paginated } from '@/types';
import type { CustomerAddress, LifecycleFields } from '@/types/models';
import { useMapDefault } from '@/hooks/useMapDefault';

type AddressRow = CustomerAddress & LifecycleFields;

interface AddressProps {
    customer: { id: string; code: string; name: string };
    addresses: Paginated<AddressRow>;
    filters: { status?: string };
    can: { update: boolean; delete: boolean };
    regions: IndonesiaRegionOptions;
    geocodeResults?: GeocodeResult[];
}

export default function Index({
    customer,
    addresses,
    filters,
    can,
    regions,
    geocodeResults = [],
}: AddressProps) {
    const mapDefault = useMapDefault();
    const [modalOpen, setModalOpen] = useState(false);
    const [editId, setEditId] = useState<string | null>(null);
    const [geocodeSearching, setGeocodeSearching] = useState(false);
    const {
        params,
        set,
        setMany,
        processing: tableProcessing,
    } = useServerTable({
        url: route('admin.customers.addresses.index', customer.id),
        filters,
        only: ['addresses', 'filters', 'can'],
    });
    const { data, setData, post, put, processing, errors, reset } = useForm({
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

    const openCreate = () => {
        reset();
        setEditId(null);
        setModalOpen(true);
    };

    const openEdit = (a: CustomerAddress) => {
        setData('label', a.label ?? '');
        setData('address', a.address ?? '');
        setData('city', a.city ?? '');
        setData('postal_code', `${a.postal_code ?? ''}`);
        setData('province_code', `${a.province_code ?? ''}`);
        setData('city_code', `${a.city_code ?? ''}`);
        setData('district_code', `${a.district_code ?? ''}`);
        setData('village_code', `${a.village_code ?? ''}`);
        setData('lat', `${a.lat ?? ''}`);
        setData('lng', `${a.lng ?? ''}`);
        setData('is_installation_point', Boolean(a.is_installation_point));
        setData('is_primary', Boolean(a.is_primary));
        setData('notes', `${a.notes ?? ''}`);
        setEditId(a.id);
        setModalOpen(true);
        reloadRegions({
            province_code: `${a.province_code ?? ''}`,
            city_code: `${a.city_code ?? ''}`,
            district_code: `${a.district_code ?? ''}`,
        });
    };

    const reloadRegions = (region: {
        province_code: string;
        city_code: string;
        district_code: string;
    }) => {
        router.get(
            route('admin.customers.addresses.index', customer.id),
            {
                region_provinces: region.province_code ? [region.province_code] : [],
                region_cities: region.city_code ? [region.city_code] : [],
                region_districts: region.district_code ? [region.district_code] : [],
            },
            { only: ['regions'], preserveState: true, preserveScroll: true, replace: true },
        );
    };

    const updateRegion = (region: IndonesiaRegionValue) => {
        setData((current) => ({ ...current, ...region }));
        reloadRegions(region);
    };

    const searchAddress = (query: string) => {
        setGeocodeSearching(true);
        router.get(
            route('admin.customers.addresses.index', customer.id),
            { geocode_query: query },
            {
                only: ['geocodeResults'],
                preserveState: true,
                preserveScroll: true,
                replace: true,
                onFinish: () => setGeocodeSearching(false),
            },
        );
    };

    const selectGeocodeResult = (result: GeocodeResult) => {
        const region = {
            province_code: result.province_code,
            city_code: result.city_code,
            district_code: result.district_code,
            village_code: result.village_code,
            city: result.city,
        };
        setData((current) => ({
            ...current,
            ...region,
            postal_code: result.postal_code,
            lat: Number(result.lat).toFixed(7),
            lng: Number(result.lng).toFixed(7),
        }));
        reloadRegions(region);
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (editId !== null) {
            put(route('admin.customers.addresses.update', [customer.id, editId]), {
                onSuccess: () => setModalOpen(false),
            });
        } else {
            post(route('admin.customers.addresses.store', customer.id), {
                onSuccess: () => setModalOpen(false),
            });
        }
    };

    const columns: DataTableColumn<AddressRow>[] = [
        {
            key: 'label',
            header: 'Label',
            cell: (a) => (
                <div className="space-y-0.5">
                    <div className="flex flex-wrap items-center gap-1.5">
                        <span
                            className={
                                a.deleted_at ? 'font-medium text-muted-foreground' : 'font-medium'
                            }
                        >
                            {a.label}
                        </span>
                        {a.deleted_at ? <EnumBadge map={LIFECYCLE_STATE} value="dihapus" /> : null}
                    </div>
                    <UsageLine references={a.references} />
                </div>
            ),
        },
        { key: 'address', header: 'Alamat' },
        { key: 'city', header: 'Kota' },
        { key: 'postal_code', header: 'Kode pos' },
        {
            key: 'is_installation_point',
            header: 'Pemasangan',
            cell: (a) => (a.is_installation_point ? 'Ya' : <EmptyCell />),
        },
        {
            key: 'is_primary',
            header: 'Utama',
            cell: (a) => (a.is_primary ? 'Ya' : <EmptyCell />),
        },
        {
            key: 'actions',
            header: 'Aksi',
            cell: (a) => {
                const label = a.label ?? 'alamat';
                const unused = (a.references ?? []).every((reference) => reference.count === 0);

                // The restore route takes the address alone; the delete route needs its customer too, so a live
                // row keeps the plain delete and offers it only while no subscription uses the address.
                return (
                    <DataTableActions
                        onEdit={can.update ? () => openEdit(a) : undefined}
                        deleteHref={
                            can.delete && unused
                                ? route('admin.customers.addresses.destroy', [customer.id, a.id])
                                : undefined
                        }
                        deleteMessage={`Hapus permanen ${label}?`}
                        deleteDescription={`Alamat "${label}" belum pernah dipakai langganan. Penghapusan permanen tidak bisa dibatalkan.`}
                        lifecycle={
                            a.deleted_at
                                ? {
                                      row: a,
                                      name: label,
                                      noun: 'Alamat',
                                      routeBase: 'admin.customers.addresses',
                                      can: { update: can.update, delete: can.delete },
                                  }
                                : undefined
                        }
                    />
                );
            },
        },
    ];

    return (
        <AdminLayout title="Alamat Pelanggan">
            <div className="space-y-6">
                <PageHeader
                    title="Alamat"
                    subtitle={`${customer.code} - ${customer.name}`}
                    actions={
                        <>
                            <Button
                                asChild
                                variant="outline"
                                size="sm"
                                leftIcon={<ArrowLeft className="size-4" />}
                            >
                                <Link href={route('admin.customers.show', customer.id)}>
                                    Kembali
                                </Link>
                            </Button>
                            <Button type="button" variant="primary" size="sm" onClick={openCreate}>
                                Tambah alamat
                            </Button>
                        </>
                    }
                />

                <Card>
                    <CardContent className="space-y-4 pt-6">
                        <div className="flex flex-wrap items-end gap-3">
                            <StatusFilter
                                id="address-status"
                                value={params.status ?? ''}
                                onChange={(value) => set('status', value)}
                            />
                        </div>
                        <DataTable
                            columns={columns}
                            data={addresses.data}
                            pagination={toPagination(addresses)}
                            onPageChange={(page) => set('page', page)}
                            loading={tableProcessing}
                            itemLabel="alamat"
                            hasFilters={Boolean(params.status)}
                            onResetFilters={() => setMany({ status: '' })}
                            emptyTitle="Belum ada alamat"
                            emptyDescription="Pakai tombol Tambah alamat untuk mencatat yang pertama."
                        />
                    </CardContent>
                </Card>

                <Modal
                    open={modalOpen}
                    onClose={() => setModalOpen(false)}
                    title={editId ? 'Ubah Alamat' : 'Tambah Alamat'}
                >
                    <form onSubmit={submit} className="space-y-4">
                        <Input
                            label="Label"
                            placeholder="Rumah / Kantor / Pemasangan"
                            value={data.label}
                            onChange={(e) => setData('label', e.target.value)}
                            error={errors.label}
                            required
                        />
                        <MapPicker
                            latitude={data.lat}
                            longitude={data.lng}
                            defaultCenter={mapDefault.center}
                            defaultZoom={mapDefault.zoom}
                            onChange={(latitude, longitude) =>
                                setData((current) => ({
                                    ...current,
                                    lat: latitude.toFixed(7),
                                    lng: longitude.toFixed(7),
                                }))
                            }
                            latitudeError={errors.lat}
                            longitudeError={errors.lng}
                            searchResults={geocodeResults}
                            searching={geocodeSearching}
                            onSearch={searchAddress}
                            onSelectSearchResult={selectGeocodeResult}
                        />
                        <IndonesiaRegionFields
                            idPrefix="customer-address-modal"
                            value={data}
                            options={regions}
                            onChange={updateRegion}
                            errors={errors}
                        />
                        <Textarea
                            label="Alamat"
                            value={data.address}
                            onChange={(e) => setData('address', e.target.value)}
                            error={errors.address}
                            required
                            rows={2}
                        />
                        <div className="grid gap-4 md:grid-cols-2">
                            <Input
                                label="Kode pos"
                                value={data.postal_code}
                                onChange={(e) => setData('postal_code', e.target.value)}
                                error={errors.postal_code}
                            />
                        </div>
                        <div className="flex gap-6">
                            <Switch
                                label="Titik pemasangan"
                                checked={data.is_installation_point}
                                onCheckedChange={(c) => setData('is_installation_point', c)}
                            />
                            <Switch
                                label="Alamat utama"
                                checked={data.is_primary}
                                onCheckedChange={(c) => setData('is_primary', c)}
                            />
                        </div>
                        <Textarea
                            label="Catatan"
                            value={data.notes}
                            onChange={(e) => setData('notes', e.target.value)}
                            error={errors.notes}
                            rows={2}
                        />
                        <div className="flex justify-end gap-2">
                            <Button
                                type="button"
                                variant="secondary"
                                onClick={() => setModalOpen(false)}
                            >
                                Batal
                            </Button>
                            <Button type="submit" variant="primary" loading={processing}>
                                {editId ? 'Simpan' : 'Tambah'}
                            </Button>
                        </div>
                    </form>
                </Modal>
            </div>
        </AdminLayout>
    );
}
