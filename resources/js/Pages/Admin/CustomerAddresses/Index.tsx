import type { FormEvent } from 'react';
import { useState } from 'react';
import { Link, router, useForm } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import {
    DataTableActions,
    IndonesiaRegionFields,
    PageHeader,
    type IndonesiaRegionOptions,
    type IndonesiaRegionValue,
} from '@/Components/composite';
import {
    Button,
    Card,
    CardContent,
    EmptyCell,
    Input,
    MapPicker,
    Switch,
    Textarea,
    Table,
    TBody,
    TD,
    TH,
    THead,
    TR,
    Modal,
    Pagination,
    type GeocodeResult,
} from '@/Components/ui';
import type { Paginated } from '@/types';
import type { CustomerAddress } from '@/types/models';
import { useMapDefault } from '@/hooks/useMapDefault';

interface AddressProps {
    customer: { id: number; code: string; name: string };
    addresses: Paginated<CustomerAddress>;
    regions: IndonesiaRegionOptions;
    geocodeResults?: GeocodeResult[];
}

export default function Index({ customer, addresses, regions, geocodeResults = [] }: AddressProps) {
    const mapDefault = useMapDefault();
    const [modalOpen, setModalOpen] = useState(false);
    const [editId, setEditId] = useState<number | string | null>(null);
    const [geocodeSearching, setGeocodeSearching] = useState(false);
    const addrList = addresses.data;
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
                        <Table>
                            <THead>
                                <TR>
                                    <TH>Label</TH>
                                    <TH>Alamat</TH>
                                    <TH>Kota</TH>
                                    <TH>Kode pos</TH>
                                    <TH>Pemasangan</TH>
                                    <TH>Utama</TH>
                                    <TH>Aksi</TH>
                                </TR>
                            </THead>
                            <TBody>
                                {addrList.length === 0 ? (
                                    <TR>
                                        <TD
                                            colSpan={7}
                                            className="py-10 text-center text-muted-foreground"
                                        >
                                            Belum ada alamat. Pakai tombol Tambah alamat untuk
                                            mencatat yang pertama.
                                        </TD>
                                    </TR>
                                ) : (
                                    addrList.map((a) => (
                                        <TR key={a.id}>
                                            <TD className="font-medium">{a.label}</TD>
                                            <TD>{a.address}</TD>
                                            <TD>{a.city || <EmptyCell />}</TD>
                                            <TD>{a.postal_code || <EmptyCell />}</TD>
                                            <TD>
                                                {a.is_installation_point ? 'Ya' : <EmptyCell />}
                                            </TD>
                                            <TD>{a.is_primary ? 'Ya' : <EmptyCell />}</TD>
                                            <TD>
                                                <DataTableActions
                                                    onEdit={() => openEdit(a)}
                                                    deleteHref={route(
                                                        'admin.customers.addresses.destroy',
                                                        [customer.id, a.id],
                                                    )}
                                                    deleteMessage={`Hapus ${a.label}?`}
                                                    deleteDescription={`Alamat "${a.label}" akan dihapus permanen dari pelanggan.`}
                                                />
                                            </TD>
                                        </TR>
                                    ))
                                )}
                            </TBody>
                        </Table>
                        <Pagination
                            meta={addresses.meta}
                            onPageChange={(page) =>
                                router.get(
                                    route('admin.customers.addresses.index', customer.id),
                                    { page },
                                    { preserveScroll: true },
                                )
                            }
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
