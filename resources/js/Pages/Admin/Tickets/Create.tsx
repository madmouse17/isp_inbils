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
    Input,
    NativeSelect,
    SearchSelect,
    Textarea,
} from '@/Components/ui';
import { PageHeader, RemoteSearchSelect } from '@/Components/composite';
import type { RemoteOption } from '@/Components/composite';
import { optionsOf, PRIORITY, TICKET_SOURCE } from '@/lib/statuses';

interface CreateProps extends Record<string, unknown> {
    categories: { data: { id: string; name: string }[] };
    locations: { data: { id: string; name: string; code: string }[] };
    canChooseLocation: boolean;
    /** Sent only after a search: `customer_search`, `subscription_search` and `networkAsset_search` ask for them. */
    customerOptions?: RemoteOption[];
    subscriptionOptions?: RemoteOption[];
    networkAssetOptions?: RemoteOption[];
}

export default function Create({
    categories,
    locations,
    canChooseLocation,
    customerOptions,
    subscriptionOptions,
    networkAssetOptions,
}: CreateProps) {
    const { data, setData, post, processing, errors } = useForm({
        title: '',
        description: '',
        source: 'customer',
        category_id: '',
        priority: 'medium',
        customer_id: '',
        subscription_id: '',
        network_asset_id: '',
        location_id: '',
    });

    // The server offers only the subscriptions and assets of what is picked, so a mismatch is never offered.
    const narrowOptions = (customerId: string, subscriptionId: string, only: string[]) =>
        router.get(
            route('admin.tickets.create'),
            { customer_id: customerId, subscription_id: subscriptionId },
            { only, preserveState: true, preserveScroll: true, replace: true },
        );

    const pickCustomer = (value: string) => {
        setData((previous) => ({
            ...previous,
            customer_id: value,
            subscription_id: '',
            network_asset_id: '',
        }));
        narrowOptions(value, '', ['subscriptionOptions', 'networkAssetOptions']);
    };

    const pickSubscription = (value: string) => {
        setData((previous) => ({ ...previous, subscription_id: value, network_asset_id: '' }));
        narrowOptions(data.customer_id, value, ['networkAssetOptions']);
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        post(route('admin.tickets.store'));
    };

    return (
        <AdminLayout title="Buat Tiket">
            <div className="space-y-6">
                <PageHeader
                    title="Buat Tiket"
                    subtitle="Catat keluhan atau gangguan, lalu simpan."
                    actions={
                        <Button
                            asChild
                            variant="outline"
                            size="sm"
                            leftIcon={<ArrowLeft className="size-4" />}
                        >
                            <Link href={route('admin.tickets.index')}>Kembali</Link>
                        </Button>
                    }
                />
                <form onSubmit={submit} className="space-y-6">
                    <Card>
                        <CardHeader>
                            <CardTitle>Data tiket</CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-4 md:grid-cols-2">
                            <Input
                                label="Judul"
                                value={data.title}
                                onChange={(e) => setData('title', e.target.value)}
                                error={errors.title}
                                required
                            />
                            <NativeSelect
                                label="Sumber"
                                value={data.source}
                                onChange={(e) => setData('source', e.target.value)}
                                options={optionsOf(TICKET_SOURCE)}
                                error={errors.source}
                                required
                            />
                            <SearchSelect
                                label="Kategori"
                                value={data.category_id}
                                onChange={(value) => setData('category_id', value)}
                                options={categories.data.map((c) => ({
                                    value: String(c.id),
                                    label: c.name,
                                }))}
                                placeholder="Cari kategori"
                                error={errors.category_id}
                                required
                            />
                            <NativeSelect
                                label="Prioritas"
                                value={data.priority}
                                onChange={(e) => setData('priority', e.target.value)}
                                options={optionsOf(PRIORITY)}
                                error={errors.priority}
                            />
                            <RemoteSearchSelect
                                field="customer"
                                label="Pelanggan"
                                options={customerOptions}
                                value={data.customer_id}
                                onChange={pickCustomer}
                                placeholder="Cari nama atau kode pelanggan"
                                error={errors.customer_id}
                            />
                            <RemoteSearchSelect
                                field="subscription"
                                label="Langganan"
                                options={subscriptionOptions}
                                value={data.subscription_id}
                                onChange={pickSubscription}
                                placeholder="Cari kode langganan"
                                error={errors.subscription_id}
                            />
                            <RemoteSearchSelect
                                field="networkAsset"
                                label="Aset jaringan"
                                options={networkAssetOptions}
                                value={data.network_asset_id}
                                onChange={(value) => setData('network_asset_id', value)}
                                placeholder="Cari kode atau nama aset"
                                error={errors.network_asset_id}
                            />
                            {canChooseLocation && (
                                <SearchSelect
                                    label="Lokasi"
                                    value={data.location_id}
                                    onChange={(value) => setData('location_id', value)}
                                    options={locations.data.map((l) => ({
                                        value: String(l.id),
                                        label: `${l.code} - ${l.name}`,
                                    }))}
                                    placeholder="Cari kode atau nama lokasi"
                                    error={errors.location_id}
                                />
                            )}
                            <Textarea
                                className="md:col-span-2"
                                label="Deskripsi"
                                value={data.description}
                                onChange={(e) => setData('description', e.target.value)}
                                error={errors.description}
                                rows={3}
                            />
                        </CardContent>
                    </Card>
                    <div className="flex justify-end gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => router.get(route('admin.tickets.index'))}
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
