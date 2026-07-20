import type { FormEvent } from 'react';
import { router, useForm } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import {
    Button,
    Card,
    CardContent,
    CardHeader,
    CardTitle,
    Input,
    Select,
    Textarea,
} from '@/Components/ui';
import { PageHeader } from '@/Components/composite';

interface LocRow {
    id: number;
    name: string;
    code: string;
}
interface AssetData {
    id: number;
    name: string;
    asset_type: string;
    serial_number?: string | null;
    mac_address?: string | null;
    ip_address?: string | null;
    management_ip?: string | null;
    location_id?: number | null;
    ownership: string;
    vendor?: string | null;
    model?: string | null;
    purchase_date?: string | null;
    purchase_price?: string | null;
    warranty_expiry?: string | null;
    notes?: string | null;
}

interface EditProps extends Record<string, unknown> {
    asset: { data: AssetData };
    locations: { data: LocRow[] };
}

export default function Edit({ asset, locations }: EditProps) {
    const a = asset.data;
    const { data, setData, put, processing, errors } = useForm({
        name: a.name,
        asset_type: a.asset_type,
        serial_number: a.serial_number ?? '',
        mac_address: a.mac_address ?? '',
        ip_address: a.ip_address ?? '',
        management_ip: a.management_ip ?? '',
        location_id: a.location_id ? String(a.location_id) : '',
        ownership: a.ownership,
        vendor: a.vendor ?? '',
        model: a.model ?? '',
        purchase_date: a.purchase_date ?? '',
        purchase_price: a.purchase_price ?? '',
        warranty_expiry: a.warranty_expiry ?? '',
        notes: a.notes ?? '',
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        put(route('admin.network-assets.update', a.id));
    };

    return (
        <AdminLayout title="Edit Network Asset">
            <div className="space-y-6">
                <PageHeader
                    title="Edit Network Asset"
                    subtitle="Fill required fields, then save."
                />
                <form onSubmit={submit} className="space-y-6">
                    <Card>
                        <CardHeader>
                            <CardTitle>Details</CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-4 md:grid-cols-2">
                            <Input
                                label="Name"
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                error={errors.name}
                                required
                            />
                            <Select
                                label="Asset Type"
                                value={data.asset_type}
                                onChange={(e) => setData('asset_type', e.target.value)}
                                error={errors.asset_type}
                            >
                                <option value="router">Router</option>
                                <option value="switch">Switch</option>
                                <option value="olt">OLT</option>
                                <option value="onu_ont">ONU/ONT</option>
                                <option value="radio">Radio</option>
                                <option value="antenna">Antenna</option>
                                <option value="fiber">Fiber</option>
                                <option value="odp">ODP</option>
                                <option value="odc">ODC</option>
                                <option value="rack">Rack</option>
                                <option value="power">Power</option>
                                <option value="other">Other</option>
                            </Select>
                            <Input
                                label="Serial Number"
                                value={data.serial_number}
                                onChange={(e) => setData('serial_number', e.target.value)}
                                error={errors.serial_number}
                            />
                            <Input
                                label="MAC Address"
                                value={data.mac_address}
                                onChange={(e) => setData('mac_address', e.target.value)}
                                error={errors.mac_address}
                            />
                            <Input
                                label="IP Address"
                                value={data.ip_address}
                                onChange={(e) => setData('ip_address', e.target.value)}
                                error={errors.ip_address}
                            />
                            <Input
                                label="Management IP"
                                value={data.management_ip}
                                onChange={(e) => setData('management_ip', e.target.value)}
                                error={errors.management_ip}
                            />
                            <Select
                                label="Location"
                                value={data.location_id}
                                onChange={(e) => setData('location_id', e.target.value)}
                                error={errors.location_id}
                            >
                                <option value="">None (in storage)</option>
                                {locations.data.map((l) => (
                                    <option key={l.id} value={l.id}>
                                        {l.code} — {l.name}
                                    </option>
                                ))}
                            </Select>
                            <Select
                                label="Ownership"
                                value={data.ownership}
                                onChange={(e) => setData('ownership', e.target.value)}
                            >
                                <option value="owned">Owned</option>
                                <option value="leased">Leased</option>
                                <option value="customer_provided">Customer Provided</option>
                            </Select>
                            <Input
                                label="Vendor"
                                value={data.vendor}
                                onChange={(e) => setData('vendor', e.target.value)}
                                error={errors.vendor}
                            />
                            <Input
                                label="Model"
                                value={data.model}
                                onChange={(e) => setData('model', e.target.value)}
                                error={errors.model}
                            />
                            <Input
                                label="Purchase Date"
                                type="date"
                                value={data.purchase_date}
                                onChange={(e) => setData('purchase_date', e.target.value)}
                                error={errors.purchase_date}
                            />
                            <Input
                                label="Purchase Price"
                                value={data.purchase_price}
                                onChange={(e) => setData('purchase_price', e.target.value)}
                                error={errors.purchase_price}
                            />
                            <Input
                                label="Warranty Expiry"
                                type="date"
                                value={data.warranty_expiry}
                                onChange={(e) => setData('warranty_expiry', e.target.value)}
                                error={errors.warranty_expiry}
                            />
                            <div className="md:col-span-2">
                                <Textarea
                                    label="Notes"
                                    value={data.notes}
                                    onChange={(e) => setData('notes', e.target.value)}
                                    error={errors.notes}
                                    rows={3}
                                />
                            </div>
                        </CardContent>
                    </Card>
                    <div className="flex justify-end gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => router.get(route('admin.network-assets.index'))}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" loading={processing}>
                            Save
                        </Button>
                    </div>
                </form>
            </div>
        </AdminLayout>
    );
}
