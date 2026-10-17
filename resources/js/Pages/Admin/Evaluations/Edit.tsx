import type { FormEvent } from 'react';
import { Link, router, useForm } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Button, Card, CardContent, CardHeader, CardTitle, Input, Textarea } from '@/Components/ui';
import { PageHeader } from '@/Components/composite';

interface EvalData {
    id: string;
    score: string;
    customer_rating?: string | null;
    comment?: string | null;
    employee?: { name: string } | null;
}

interface EditProps extends Record<string, unknown> {
    evaluation: { data: EvalData };
}

export default function Edit({ evaluation }: EditProps) {
    const ev = evaluation.data;
    const { data, setData, put, processing, errors } = useForm({
        score: ev.score,
        customer_rating: ev.customer_rating ?? '',
        comment: ev.comment ?? '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        put(route('admin.evaluations.update', ev.id));
    };

    return (
        <AdminLayout title="Ubah Evaluasi">
            <div className="space-y-6">
                <PageHeader
                    title="Ubah Evaluasi"
                    subtitle={`Perbarui hasil penilaian ${ev.employee?.name ?? 'karyawan'}.`}
                    actions={
                        <Button
                            asChild
                            variant="outline"
                            size="sm"
                            leftIcon={<ArrowLeft className="size-4" />}
                        >
                            <Link href={route('admin.evaluations.show', ev.id)}>Kembali</Link>
                        </Button>
                    }
                />

                <form onSubmit={submit} className="space-y-6">
                    <Card>
                        <CardHeader>
                            <CardTitle>Hasil penilaian</CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-4 md:grid-cols-2">
                            <p className="text-sm text-muted-foreground md:col-span-2">
                                Karyawan:{' '}
                                <span className="font-medium text-foreground">
                                    {ev.employee?.name ?? '-'}
                                </span>
                            </p>
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
                            onClick={() => router.get(route('admin.evaluations.show', ev.id))}
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
