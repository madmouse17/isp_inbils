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
import { PageHeader, RemoteSearchSelect } from '@/Components/composite';
import type { RemoteOption } from '@/Components/composite';
import { optionsOf, PRIORITY, WORK_ORDER_TYPE } from '@/lib/statuses';

interface CreateProps extends Record<string, unknown> {
    /** Sent only after a search: `customer_search`, `subscription_search` and `location_search` ask for them. */
    customerOptions?: RemoteOption[];
    subscriptionOptions?: RemoteOption[];
    locationOptions?: RemoteOption[];
}

/** These kinds of SPK start from a customer's subscription, so both are asked for. */
const NEEDS_SUBSCRIPTION = ['installation', 'upgrade_service', 'relocation'];

export default function Create({
    customerOptions,
    subscriptionOptions,
    locationOptions,
}: CreateProps) {
    const { data, setData, post, processing, errors } = useForm({
        type: 'installation',
        title: '',
        description: '',
        customer_id: '',
        subscription_id: '',
        location_id: '',
        source: 'manual',
        priority: 'medium',
        scheduled_date: '',
    });
    const needsSubscription = NEEDS_SUBSCRIPTION.includes(data.type);

    const pickCustomer = (value: string) => {
        setData((previous) => ({ ...previous, customer_id: value, subscription_id: '' }));
        // The server offers only this customer's subscriptions from now on.
        router.get(
            route('admin.spk.create'),
            { customer_id: value },
            {
                only: ['subscriptionOptions'],
                preserveState: true,
                preserveScroll: true,
                replace: true,
            },
        );
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        post(route('admin.spk.store'));
    };

    return (
        <AdminLayout title="Buat SPK">
            <div className="space-y-6">
                <PageHeader
                    title="Buat SPK"
                    subtitle="Isi data pekerjaan, lalu simpan sebagai draf."
                    actions={
                        <Button
                            asChild
                            variant="outline"
                            size="sm"
                            leftIcon={<ArrowLeft className="size-4" />}
                        >
                            <Link href={route('admin.spk.index')}>Kembali</Link>
                        </Button>
                    }
                />

                <form onSubmit={submit} className="space-y-6">
                    <Card>
                        <CardHeader>
                            <CardTitle>Data pekerjaan</CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-4 md:grid-cols-2">
                            <NativeSelect
                                label="Jenis"
                                value={data.type}
                                onChange={(e) => setData('type', e.target.value)}
                                options={optionsOf(WORK_ORDER_TYPE)}
                                error={errors.type}
                                required
                            />
                            <Input
                                label="Judul"
                                value={data.title}
                                onChange={(e) => setData('title', e.target.value)}
                                error={errors.title}
                                required
                            />
                            <RemoteSearchSelect
                                field="customer"
                                label="Pelanggan"
                                options={customerOptions}
                                value={data.customer_id}
                                onChange={pickCustomer}
                                placeholder="Cari nama atau kode pelanggan"
                                error={errors.customer_id}
                                required={needsSubscription}
                            />
                            <RemoteSearchSelect
                                field="subscription"
                                label="Langganan"
                                options={subscriptionOptions}
                                value={data.subscription_id}
                                onChange={(value) => setData('subscription_id', value)}
                                placeholder="Cari kode langganan"
                                hint={
                                    needsSubscription
                                        ? 'Wajib untuk jenis SPK ini. Pilih pelanggan dulu agar daftar menyempit.'
                                        : undefined
                                }
                                error={errors.subscription_id}
                                required={needsSubscription}
                            />
                            <RemoteSearchSelect
                                field="location"
                                label="Lokasi"
                                options={locationOptions}
                                value={data.location_id}
                                onChange={(value) => setData('location_id', value)}
                                placeholder="Cari kode atau nama lokasi"
                                error={errors.location_id}
                            />
                            <NativeSelect
                                label="Prioritas"
                                value={data.priority}
                                onChange={(e) => setData('priority', e.target.value)}
                                options={optionsOf(PRIORITY)}
                                error={errors.priority}
                            />
                            <DatePicker
                                label="Tanggal jadwal"
                                value={data.scheduled_date}
                                onChange={(value) => setData('scheduled_date', value)}
                                error={errors.scheduled_date}
                            />
                            <div className="md:col-span-2">
                                <Textarea
                                    label="Deskripsi"
                                    value={data.description}
                                    onChange={(e) => setData('description', e.target.value)}
                                    error={errors.description}
                                    rows={3}
                                />
                            </div>
                        </CardContent>
                    </Card>
                    <div className="flex justify-end gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => router.get(route('admin.spk.index'))}
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
