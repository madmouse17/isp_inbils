import type { FormEvent } from 'react';
import { Link, router, useForm, usePage } from '@inertiajs/react';
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
    SearchSelect,
    Textarea,
} from '@/Components/ui';
import { PageHeader, RemoteSearchSelect, type RemoteOption } from '@/Components/composite';
import { NETWORK_ASSET_OWNERSHIP, NETWORK_ASSET_TYPE, optionsOf } from '@/lib/statuses';

interface CreateProps extends Record<string, unknown> {
    /** Sent on a search only (`product_search`), as `SearchOptions` makes it. */
    productOptions?: RemoteOption[];
    locationOptions?: RemoteOption[];
}

const typeOptions = optionsOf(NETWORK_ASSET_TYPE);
const ownershipOptions = optionsOf(NETWORK_ASSET_OWNERSHIP);

/** The type a link asks for (`?asset_type=odp`, "Daftarkan ODP" of the ODN page); an ONT as before when it asks for none. */
function typeFromUrl(url: string): string {
    const asked = new URLSearchParams(url.split('?')[1] ?? '').get('asset_type') ?? '';

    return typeOptions.some((option) => option.value === asked) ? asked : 'onu_ont';
}

export default function Create({ productOptions, locationOptions }: CreateProps) {
    const { url } = usePage();
    const { data, setData, post, processing, errors } = useForm({
        name: '',
        product_id: '',
        asset_type: typeFromUrl(url),
        serial_number: '',
        mac_address: '',
        ip_address: '',
        management_ip: '',
        location_id: '',
        ownership: 'owned',
        vendor: '',
        model: '',
        purchase_date: '',
        purchase_price: '',
        warranty_expiry: '',
        notes: '',
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        post(route('admin.network-assets.store'));
    };

    return (
        <AdminLayout title="Buat Aset Jaringan">
            <div className="space-y-6">
                <PageHeader
                    title="Buat Aset Jaringan"
                    subtitle="Isi data perangkat, lalu simpan."
                    actions={
                        <Button
                            asChild
                            variant="outline"
                            size="sm"
                            leftIcon={<ArrowLeft className="size-4" />}
                        >
                            <Link href={route('admin.network-assets.index')}>Kembali</Link>
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
                                placeholder="Cari SKU atau nama produk"
                                emptyText="Tidak ada produk aktif yang cocok."
                                error={errors.product_id}
                            />
                            <SearchSelect
                                id="asset-type"
                                label="Tipe aset"
                                value={data.asset_type}
                                onChange={(value) => setData('asset_type', value)}
                                options={typeOptions}
                                placeholder="Cari tipe aset"
                                emptyText="Tidak ada tipe aset yang cocok."
                                error={errors.asset_type}
                                required
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
                                hint="Alamat perangkat itu sendiri, bila tetap. Boleh kosong."
                                error={errors.ip_address}
                            />
                            <Input
                                label="IP manajemen"
                                value={data.management_ip}
                                onChange={(e) => setData('management_ip', e.target.value)}
                                hint="Alamat untuk mengelola perangkat. Router NAS memakainya sebagai alamat NAS dan target CoA."
                                error={errors.management_ip}
                            />
                            <RemoteSearchSelect
                                field="location"
                                label="Lokasi"
                                value={data.location_id}
                                onChange={(value) => setData('location_id', value)}
                                options={locationOptions}
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
                        <Button type="submit" variant="primary" loading={processing}>
                            Simpan
                        </Button>
                    </div>
                </form>
            </div>
        </AdminLayout>
    );
}
