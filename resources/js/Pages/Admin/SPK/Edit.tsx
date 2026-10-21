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
    EnumBadge,
    Input,
    NativeSelect,
    Textarea,
} from '@/Components/ui';
import { ConflictBanner, PageHeader, RemoteSearchSelect } from '@/Components/composite';
import type { RemoteOption } from '@/Components/composite';
import { optionsOf, PRIORITY, WORK_ORDER_STATUS, WORK_ORDER_TYPE } from '@/lib/statuses';

interface WorkOrderData {
    id: string;
    code: string;
    type: string;
    title: string;
    description?: string | null;
    status: string;
    priority: string;
    scheduled_date?: string | null;
    /** The version this form was rendered from: the server refuses the save when the SPK moved on since. */
    updated_at: string;
    customer_id?: string | null;
    subscription_id?: string | null;
    location_id?: string | null;
    customer?: { id: string; code: string; name: string } | null;
    subscription?: { id: string; code: string } | null;
    location?: { id: string; code: string; name: string; path?: string | null } | null;
}

interface EditProps extends Record<string, unknown> {
    workOrder: { data: WorkOrderData };
    /** Sent only after a search (`customer_search`, `subscription_search`, `location_search`) asks for them. */
    customerOptions?: RemoteOption[];
    subscriptionOptions?: RemoteOption[];
    locationOptions?: RemoteOption[];
}

/** These kinds of SPK start from a customer's subscription, so both are asked for. */
const NEEDS_SUBSCRIPTION = ['installation', 'upgrade_service', 'relocation'];

/** The page of an SPK that is still a plan (draft or waiting for a technician): the header fields, and the visit day. */
export default function Edit({
    workOrder,
    customerOptions,
    subscriptionOptions,
    locationOptions,
}: EditProps) {
    const w = workOrder.data;
    const needsSubscription = NEEDS_SUBSCRIPTION.includes(w.type);
    const { data, setData, put, processing, errors } = useForm({
        title: w.title,
        description: w.description ?? '',
        customer_id: w.customer_id ?? '',
        subscription_id: w.subscription_id ?? '',
        location_id: w.location_id ?? '',
        priority: w.priority,
        scheduled_date: w.scheduled_date?.slice(0, 10) ?? '',
        expected_updated_at: w.updated_at,
    });

    const pickCustomer = (value: string) => {
        setData((previous) => ({ ...previous, customer_id: value, subscription_id: '' }));
        // The server offers only this customer's subscriptions from now on.
        router.get(
            route('admin.spk.edit', w.id),
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
        put(route('admin.spk.update', w.id));
    };

    return (
        <AdminLayout title="Ubah SPK">
            <div className="space-y-6">
                <PageHeader
                    title="Ubah SPK"
                    subtitle={w.code}
                    actions={
                        <Button
                            asChild
                            variant="outline"
                            size="sm"
                            leftIcon={<ArrowLeft className="size-4" />}
                        >
                            <Link href={route('admin.spk.show', w.id)}>Kembali</Link>
                        </Button>
                    }
                />

                <ConflictBanner />

                <form onSubmit={submit} className="space-y-6">
                    <Card>
                        <CardHeader>
                            <CardTitle>Data pekerjaan</CardTitle>
                            <p className="flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                                Jenis <EnumBadge map={WORK_ORDER_TYPE} value={w.type} /> dan status{' '}
                                <EnumBadge map={WORK_ORDER_STATUS} value={w.status} /> tidak bisa
                                diubah di sini.
                            </p>
                        </CardHeader>
                        <CardContent className="grid gap-4 md:grid-cols-2">
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
                                selected={
                                    w.customer
                                        ? {
                                              value: w.customer.id,
                                              label: w.customer.name,
                                              description: w.customer.code,
                                          }
                                        : null
                                }
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
                                selected={
                                    w.subscription
                                        ? {
                                              value: w.subscription.id,
                                              label: w.subscription.code,
                                              description: w.customer?.name,
                                          }
                                        : null
                                }
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
                                selected={
                                    w.location
                                        ? {
                                              value: w.location.id,
                                              label: `${w.location.code} - ${w.location.name}`,
                                              description: w.location.path,
                                          }
                                        : null
                                }
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
                                label="Tanggal kunjungan"
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
                            onClick={() => router.get(route('admin.spk.show', w.id))}
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
