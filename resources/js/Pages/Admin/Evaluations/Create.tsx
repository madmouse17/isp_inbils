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
    Textarea,
} from '@/Components/ui';
import { PageHeader, RemoteSearchSelect } from '@/Components/composite';
import type { RemoteOption } from '@/Components/composite';
import { EVALUATION_REFERENCE_TYPE, optionsOf } from '@/lib/statuses';

interface CreateProps extends Record<string, unknown> {
    employeeOptions?: RemoteOption[];
    ticketOptions?: RemoteOption[];
    workOrderOptions?: RemoteOption[];
}

export default function Create({ employeeOptions, ticketOptions, workOrderOptions }: CreateProps) {
    const { data, setData, post, processing, errors } = useForm({
        employee_id: '',
        reference_type: 'Ticket',
        reference_id: '',
        score: '5.0',
        customer_rating: '',
        comment: '',
    });
    const isTicket = data.reference_type === 'Ticket';

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post(route('admin.evaluations.store'));
    };

    const pickReferenceType = (value: string) => {
        setData((current) => ({ ...current, reference_type: value, reference_id: '' }));
    };

    return (
        <AdminLayout title="Buat Evaluasi">
            <div className="space-y-6">
                <PageHeader
                    title="Buat Evaluasi"
                    subtitle="Nilai pekerjaan karyawan berdasarkan SPK atau tiket yang mereka kerjakan."
                    actions={
                        <Button
                            asChild
                            variant="outline"
                            size="sm"
                            leftIcon={<ArrowLeft className="size-4" />}
                        >
                            <Link href={route('admin.evaluations.index')}>Kembali</Link>
                        </Button>
                    }
                />

                <form onSubmit={submit} className="space-y-6">
                    <Card>
                        <CardHeader>
                            <CardTitle>Data evaluasi</CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-4 md:grid-cols-2">
                            <RemoteSearchSelect
                                field="employee"
                                label="Karyawan"
                                options={employeeOptions}
                                value={data.employee_id}
                                onChange={(value) => setData('employee_id', value)}
                                placeholder="Cari nama atau Email karyawan"
                                emptyText="Tidak ada karyawan yang cocok."
                                error={errors.employee_id}
                                required
                            />
                            <NativeSelect
                                label="Jenis referensi"
                                value={data.reference_type}
                                onChange={(event) => pickReferenceType(event.target.value)}
                                options={optionsOf(EVALUATION_REFERENCE_TYPE)}
                                error={errors.reference_type}
                                required
                            />
                            <RemoteSearchSelect
                                field={isTicket ? 'ticket' : 'workOrder'}
                                label={isTicket ? 'Tiket' : 'SPK'}
                                options={isTicket ? ticketOptions : workOrderOptions}
                                value={data.reference_id}
                                onChange={(value) => setData('reference_id', value)}
                                placeholder={
                                    isTicket
                                        ? 'Cari kode atau judul tiket'
                                        : 'Cari kode atau judul SPK'
                                }
                                emptyText={
                                    isTicket
                                        ? 'Tidak ada tiket yang cocok.'
                                        : 'Tidak ada SPK yang cocok.'
                                }
                                error={errors.reference_id}
                                required
                            />
                            <Input
                                label="Nilai (1,0 sampai 5,0)"
                                type="number"
                                step="0.1"
                                min="1"
                                max="5"
                                value={data.score}
                                onChange={(event) => setData('score', event.target.value)}
                                error={errors.score}
                                required
                            />
                            <Input
                                label="Penilaian pelanggan (opsional)"
                                type="number"
                                step="0.1"
                                min="1"
                                max="5"
                                value={data.customer_rating}
                                onChange={(event) => setData('customer_rating', event.target.value)}
                                error={errors.customer_rating}
                            />
                            <div className="md:col-span-2">
                                <Textarea
                                    label="Komentar"
                                    value={data.comment}
                                    onChange={(event) => setData('comment', event.target.value)}
                                    error={errors.comment}
                                    rows={3}
                                />
                            </div>
                        </CardContent>
                    </Card>
                    <div className="flex justify-end gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => router.get(route('admin.evaluations.index'))}
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
