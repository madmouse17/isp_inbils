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
    Textarea,
} from '@/Components/ui';
import { PageHeader, RemoteSearchSelect } from '@/Components/composite';
import type { RemoteOption } from '@/Components/composite';

interface CreateProps extends Record<string, unknown> {
    customerOptions?: RemoteOption[];
    subscriptionOptions?: RemoteOption[];
}

export default function Create({ customerOptions, subscriptionOptions }: CreateProps) {
    const { data, setData, post, processing, errors } = useForm({
        customer_id: '',
        subscription_id: '',
        issue_date: '',
        due_date: '',
        notes: '',
    });

    const pickCustomer = (value: string) => {
        setData((previous) => ({ ...previous, customer_id: value, subscription_id: '' }));
        // The server offers only this customer's subscriptions from now on.
        router.get(
            route('admin.invoices.create'),
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
        post(route('admin.invoices.store'));
    };

    return (
        <AdminLayout title="Buat tagihan">
            <div className="space-y-6">
                <PageHeader
                    title="Buat tagihan"
                    subtitle="Pilih pelanggan, atur tanggal, lalu simpan sebagai draf. Item ditambahkan di halaman tagihan."
                    actions={
                        <Button
                            asChild
                            variant="outline"
                            size="sm"
                            leftIcon={<ArrowLeft className="size-4" />}
                        >
                            <Link href={route('admin.invoices.index')}>Kembali</Link>
                        </Button>
                    }
                />
                <form onSubmit={submit} className="space-y-6">
                    <Card>
                        <CardHeader>
                            <CardTitle>Data tagihan</CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-4 md:grid-cols-2">
                            <RemoteSearchSelect
                                id="customer_id"
                                field="customer"
                                label="Pelanggan"
                                placeholder="Cari nama atau kode pelanggan"
                                options={customerOptions}
                                value={data.customer_id}
                                onChange={pickCustomer}
                                error={errors.customer_id}
                                required
                            />
                            <RemoteSearchSelect
                                id="subscription_id"
                                field="subscription"
                                label="Langganan (opsional)"
                                placeholder="Cari kode langganan"
                                options={subscriptionOptions}
                                value={data.subscription_id}
                                onChange={(value) => setData('subscription_id', value)}
                                error={errors.subscription_id}
                            />
                            <DatePicker
                                id="issue_date"
                                label="Tanggal terbit"
                                hint="Kosongkan untuk memakai hari ini."
                                value={data.issue_date}
                                onChange={(value) => setData('issue_date', value)}
                                error={errors.issue_date}
                            />
                            <DatePicker
                                id="due_date"
                                label="Jatuh tempo"
                                hint="Kosongkan untuk memakai 14 hari setelah tanggal terbit."
                                value={data.due_date}
                                onChange={(value) => setData('due_date', value)}
                                error={errors.due_date}
                            />
                            <Textarea
                                className="md:col-span-2"
                                label="Catatan"
                                value={data.notes}
                                onChange={(e) => setData('notes', e.target.value)}
                                error={errors.notes}
                                rows={3}
                            />
                        </CardContent>
                    </Card>
                    <div className="flex justify-end gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => router.get(route('admin.invoices.index'))}
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
