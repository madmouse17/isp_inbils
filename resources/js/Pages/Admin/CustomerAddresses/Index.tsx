import type { FormEvent } from 'react';
import { useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import {
    IndonesiaRegionFields,
    PageHeader,
    type IndonesiaRegionOptions,
    type IndonesiaRegionValue,
} from '@/Components/composite';
import {
    Badge,
    Button,
    Card,
    CardContent,
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
import type { CustomerAddress } from '@/types/models';

interface AddressProps {
    customer: { id: number; code: string; name: string };
    addresses: { data: CustomerAddress[]; current_page: number; last_page: number };
    regions: IndonesiaRegionOptions;
    geocodeResults?: GeocodeResult[];
}

export default function Index({ customer, addresses, regions, geocodeResults = [] }: AddressProps) {
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

    const remove = (a: CustomerAddress) => {
        if (window.confirm(`Delete ${a.label}?`))
            router.delete(route('admin.customers.addresses.destroy', [customer.id, a.id]));
    };

    return (
        <AdminLayout title="Customer Addresses">
            <div className="space-y-6">
                <PageHeader
                    title="Addresses"
                    subtitle={`${customer.code} — ${customer.name}`}
                    actions={
                        <>
                            <Button
                                type="button"
                                variant="secondary"
                                onClick={() =>
                                    router.get(route('admin.customers.show', customer.id))
                                }
                            >
                                Back
                            </Button>
                            <Button type="button" onClick={openCreate}>
                                Add Address
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
                                    <TH>Address</TH>
                                    <TH>City</TH>
                                    <TH>Postal</TH>
                                    <TH>Install</TH>
                                    <TH>Primary</TH>
                                    <TH>Actions</TH>
                                </TR>
                            </THead>
                            <TBody>
                                {addrList.length === 0 ? (
                                    <TR>
                                        <TD
                                            colSpan={7}
                                            className="py-10 text-center text-muted-foreground"
                                        >
                                            No addresses.
                                        </TD>
                                    </TR>
                                ) : (
                                    addrList.map((a) => (
                                        <TR key={a.id}>
                                            <TD>{a.label}</TD>
                                            <TD>{a.address}</TD>
                                            <TD>{a.city ?? '-'}</TD>
                                            <TD>{a.postal_code ?? '-'}</TD>
                                            <TD>
                                                {a.is_installation_point ? (
                                                    <Badge variant="success">Yes</Badge>
                                                ) : (
                                                    '-'
                                                )}
                                            </TD>
                                            <TD>
                                                {a.is_primary ? (
                                                    <Badge variant="brand">Yes</Badge>
                                                ) : (
                                                    '-'
                                                )}
                                            </TD>
                                            <TD>
                                                <div className="flex gap-2">
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() => openEdit(a)}
                                                    >
                                                        Edit
                                                    </Button>
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() => remove(a)}
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
                        <Pagination
                            currentPage={addresses.current_page}
                            lastPage={addresses.last_page}
                            onPageChange={(page) =>
                                router.get(route('admin.customers.addresses.index', customer.id), {
                                    page,
                                })
                            }
                        />
                    </CardContent>
                </Card>

                <Modal
                    open={modalOpen}
                    onClose={() => setModalOpen(false)}
                    title={editId ? 'Edit Address' : 'Add Address'}
                >
                    <form onSubmit={submit} className="space-y-4">
                        <Input
                            label="Label"
                            value={data.label}
                            onChange={(e) => setData('label', e.target.value)}
                            error={errors.label}
                            required
                        />
                        <MapPicker
                            latitude={data.lat}
                            longitude={data.lng}
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
                            label="Address"
                            value={data.address}
                            onChange={(e) => setData('address', e.target.value)}
                            error={errors.address}
                            required
                            rows={2}
                        />
                        <div className="grid gap-4 md:grid-cols-2">
                            <Input
                                label="Postal Code"
                                value={data.postal_code}
                                onChange={(e) => setData('postal_code', e.target.value)}
                                error={errors.postal_code}
                            />
                        </div>
                        <div className="flex gap-6">
                            <Switch
                                label="Installation Point"
                                checked={data.is_installation_point}
                                onCheckedChange={(c) => setData('is_installation_point', c)}
                            />
                            <Switch
                                label="Primary"
                                checked={data.is_primary}
                                onCheckedChange={(c) => setData('is_primary', c)}
                            />
                        </div>
                        <Textarea
                            label="Notes"
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
