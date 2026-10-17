import type { FormEvent } from 'react';
import { Link, router, useForm } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import {
    Button,
    Card,
    CardContent,
    CardHeader,
    CardTitle,
    DatePicker,
    Input,
    NativeSelect,
    Textarea,
} from '@/Components/ui';
import { PageHeader, RemoteSearchSelect, type RemoteOption } from '@/Components/composite';
import { NETWORK_ASSET_OWNERSHIP, NETWORK_ASSET_TYPE, optionsOf } from '@/lib/statuses';

interface AssetData {
    id: string;
    name: string;
    product_id?: string | null;
    asset_type: string;
    serial_number?: string | null;
    mac_address?: string | null;
    ip_address?: string | null;
    management_ip?: string | null;
    location_id?: string | null;
    ownership: string;
    vendor?: string | null;
    model?: string | null;
    purchase_date?: string | null;
    purchase_price?: string | null;
    warranty_expiry?: string | null;
    notes?: string | null;
    product?: { id: string; sku: string; name: string } | null;
    location?: { id: string; code: string; name: string; path?: string | null } | null;
}

interface EditProps extends Record<string, unknown> {
    asset: { data: AssetData };
    /** Sent on a search only (`product_search`), as `SearchOptions` makes it. */
    productOptions?: RemoteOption[];
    locationOptions?: RemoteOption[];
}

const typeOptions = optionsOf(NETWORK_ASSET_TYPE);
const ownershipOptions = optionsOf(NETWORK_ASSET_OWNERSHIP);

export default function Edit({ asset, productOptions, locationOptions }: EditProps) {
    const a = asset.data;
    const { data, setData, put, processing, errors } = useForm({
        name: a.name,
        product_id: a.product_id ? String(a.product_id) : '',
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
        <AdminLayout title="Ubah Aset Jaringan">
            <div className="space-y-6">
                <PageHeader
                    title="Ubah Aset Jaringan"
                    subtitle={`Perbarui data ${a.name}, lalu simpan.`}
                    actions={
                        <Button
                            asChild
                            variant="outline"
                            size="sm"
                            leftIcon={<ArrowLeft className="size-4" />}
                        >
                            <Link href={route('admin.network-assets.show', a.id)}>Kembali</Link>
                        </Button>
                    }
                />
                <form onSubmit={submit} className="space-y-6">
                    <Card>
                        <CardHeader>
                            <CardTitle>Detail</CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-4 md:grid-cols-2">
                            <Input
                                label="Nama"
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                error={errors.name}
                                required
                            />
                            <RemoteSearchSelect
                                field="product"
                                label="Produk"
                                value={data.product_id}
                                onChange={(value) => setData('product_id', value)}
                                options={productOptions}
                                selected={
                                    a.product
                                        ? {
                                              value: a.product.id,
                                              label: `${a.product.sku} - ${a.product.name}`,
                                          }
                                        : null
                                }
                                placeholder="Cari SKU atau nama produk"
                                emptyText="Tidak ada produk aktif yang cocok."
                                error={errors.product_id}
                            />
                            <NativeSelect
                                label="Tipe aset"
                                value={data.asset_type}
                                onChange={(e) => setData('asset_type', e.target.value)}
                                options={typeOptions}
                                error={errors.asset_type}
                            />
                            <Input
                                label="Nomor seri"
                                value={data.serial_number}
                                onChange={(e) => setData('serial_number', e.target.value)}
                                error={errors.serial_number}
                            />
                            <Input
                                label="Alamat MAC"
                                value={data.mac_address}
                                onChange={(e) => setData('mac_address', e.target.value)}
                                error={errors.mac_address}
                            />
                            <Input
                                label="Alamat IP"
                                value={data.ip_address}
                                onChange={(e) => setData('ip_address', e.target.value)}
                                error={errors.ip_address}
                            />
                            <Input
                                label="IP manajemen"
                                value={data.management_ip}
                                onChange={(e) => setData('management_ip', e.target.value)}
                                error={errors.management_ip}
                            />
                            <RemoteSearchSelect
                                field="location"
                                label="Lokasi"
                                value={data.location_id}
                                onChange={(value) => setData('location_id', value)}
                                options={locationOptions}
                                selected={
                                    a.location
                                        ? {
                                              value: a.location.id,
                                              label: `${a.location.code} - ${a.location.name}`,
                                              description: a.location.path,
                                          }
                                        : null
                                }
                                placeholder="Cari kode, nama, atau jalur lokasi"
                                emptyText="Tidak ada lokasi aktif yang cocok."
                                error={errors.location_id}
                                hint="Kosongkan jika aset masih di gudang."
                            />
                            <NativeSelect
                                label="Kepemilikan"
                                value={data.ownership}
                                onChange={(e) => setData('ownership', e.target.value)}
                                options={ownershipOptions}
                                error={errors.ownership}
                            />
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
                            <DatePicker
                                label="Tanggal pembelian"
                                value={data.purchase_date}
                                onChange={(value) => setData('purchase_date', value)}
                                error={errors.purchase_date}
                            />
                            <Input
                                label="Harga beli"
                                value={data.purchase_price}
                                onChange={(e) => setData('purchase_price', e.target.value)}
                                error={errors.purchase_price}
                                inputMode="decimal"
                            />
                            <DatePicker
                                label="Tanggal garansi berakhir"
                                value={data.warranty_expiry}
                                onChange={(value) => setData('warranty_expiry', value)}
                                error={errors.warranty_expiry}
                            />
                            <div className="md:col-span-2">
                                <Textarea
                                    label="Catatan"
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
                            Batal
                        </Button>
                        <Button type="submit" loading={processing}>
                            Simpan
                        </Button>
                    </div>
                </form>
            </div>
        </AdminLayout>
    );
}
