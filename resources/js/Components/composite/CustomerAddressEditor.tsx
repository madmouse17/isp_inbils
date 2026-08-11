import type { FormEvent } from 'react';
import { useForm } from '@inertiajs/react';
import {
    Button,
    Card,
    CardContent,
    CardHeader,
    CardTitle,
    Input,
    Switch,
    Textarea,
} from '@/Components/ui';
import { IndonesiaRegionFields, type IndonesiaRegionOptions, type IndonesiaRegionValue } from '@/Components/composite';
import type { CustomerAddress } from '@/types/models';

interface CustomerAddressEditorProps {
    customerId: number;
    addresses: CustomerAddress[];
    regions: IndonesiaRegionOptions;
    canManage: boolean;
}

type AddressForm = {
    id: number | null;
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

export function CustomerAddressEditor({ customerId, addresses, regions, canManage }: CustomerAddressEditorProps) {
    const { data, setData, post, put, delete: destroy, processing, errors, reset } = useForm<AddressForm>(emptyForm());

    const startCreate = () => {
        reset();
    };

    const startEdit = (row: CustomerAddress) => {
        setData('id', Number(row.id));
        setData('label', row.label ?? '');
        setData('address', row.address ?? '');
        setData('city', row.city ?? '');
        setData('postal_code', row.postal_code ?? '');
        setData('province_code', row.province_code ?? '');
        setData('city_code', row.city_code ?? '');
        setData('district_code', row.district_code ?? '');
        setData('village_code', row.village_code ?? '');
        setData('lat', String(row.lat ?? ''));
        setData('lng', String(row.lng ?? ''));
        setData('is_installation_point', Boolean(row.is_installation_point));
        setData('is_primary', Boolean(row.is_primary));
        setData('notes', row.notes ?? '');
    };

    const updateRegion = (region: IndonesiaRegionValue) => {
        setData((current) => ({ ...current, ...region }));
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (data.id) {
            put(route('admin.customers.addresses.update', [customerId, data.id]), {
                preserveScroll: true,
                onSuccess: () => reset(),
            });
        } else {
            post(route('admin.customers.addresses.store', customerId), {
                preserveScroll: true,
                onSuccess: () => reset(),
            });
        }
    };

    const remove = (row: CustomerAddress) => {
        if (window.confirm(`Delete address ${row.label}?`)) {
            destroy(route('admin.customers.addresses.destroy', [customerId, row.id]), {
                preserveScroll: true,
            });
        }
    };

    return (
        <div className="space-y-4">
            <Card>
                <CardHeader className="flex-row flex-wrap items-center justify-between space-y-0">
                    <CardTitle>Addresses</CardTitle>
                    <Button type="button" variant="success" onClick={startCreate}>Add Address</Button>
                </CardHeader>
                <CardContent className="space-y-3">
                    {addresses.length === 0 ? (
                        <p className="py-6 text-center text-sm text-muted-foreground">No addresses yet.</p>
                    ) : addresses.map((row) => (
                        <div key={row.id} className="flex flex-wrap items-center justify-between gap-2 rounded-lg border p-3">
                            <div className="min-w-0">
                                <p className="text-sm font-medium">{row.label}</p>
                                <p className="text-sm text-muted-foreground">
                                    {[row.village_name, row.district_name, row.city_name, row.province_name].filter(Boolean).join(', ') || row.address}
                                </p>
                            </div>
                            <div className="flex gap-2">
                                <Button type="button" variant="outline" size="sm" onClick={() => startEdit(row)}>Edit</Button>
                                <Button type="button" variant="ghost" size="sm" onClick={() => remove(row)}>Delete</Button>
                            </div>
                        </div>
                    ))}
                </CardContent>
            </Card>

            {canManage ? (
                <Card>
                    <CardHeader>
                        <CardTitle>{data.id ? 'Edit Address' : 'Add Address'}</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <form onSubmit={submit} className="space-y-4">
                            <div className="grid gap-4 md:grid-cols-2">
                                <Input label="Label" value={data.label} onChange={(e) => setData('label', e.target.value)} error={errors.label} required />
                                <IndonesiaRegionFields
                                    idPrefix="customer-address-editor"
                                    value={data}
                                    options={regions}
                                    onChange={updateRegion}
                                    errors={errors}
                                />
                                <Textarea className="md:col-span-2" label="Address" value={data.address} onChange={(e) => setData('address', e.target.value)} error={errors.address} required rows={2} />
                            </div>
                            <div className="grid gap-4 md:grid-cols-2">
                                <Input label="Postal Code" value={data.postal_code} onChange={(e) => setData('postal_code', e.target.value)} error={errors.postal_code} />
                                <div className="grid gap-4 md:col-span-2 md:grid-cols-2">
                                    <Input label="Latitude" value={data.lat} onChange={(e) => setData('lat', e.target.value)} error={errors.lat} />
                                    <Input label="Longitude" value={data.lng} onChange={(e) => setData('lng', e.target.value)} error={errors.lng} />
                                </div>
                            </div>
                            <div className="flex flex-wrap gap-6">
                                <Switch label="Primary address" checked={data.is_primary} onCheckedChange={(checked) => setData('is_primary', checked)} />
                                <Switch label="Installation address" checked={data.is_installation_point} onCheckedChange={(checked) => setData('is_installation_point', checked)} />
                            </div>
                            <Textarea label="Notes" value={data.notes} onChange={(e) => setData('notes', e.target.value)} error={errors.notes} rows={2} />
                            <div className="flex justify-end gap-2">
                                {data.id ? <Button type="button" variant="secondary" onClick={() => reset()}>Cancel</Button> : null}
                                <Button type="submit" variant="success" loading={processing}>{data.id ? 'Save' : 'Add'}</Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>
            ) : null}
        </div>
    );
}
