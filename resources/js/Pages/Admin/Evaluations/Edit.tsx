import { FormEvent } from 'react';
import { router, useForm } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Button, Card, CardContent, CardHeader, CardTitle, Input, Textarea } from '@/Components/ui';
import { PageHeader } from '@/Components/composite';

interface EvalData {
    id: number; score: string; customer_rating?: string | null; comment?: string | null;
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

    const submit = (e: FormEvent) => { e.preventDefault(); put(route('admin.evaluations.update', ev.id)); };

    return (
        <AdminLayout title="Edit Evaluation">
            <div className="space-y-6">
                <PageHeader title="Edit Evaluation" subtitle="Update evaluation details." />

                <form onSubmit={submit} className="space-y-6">
                    <Card>
                    <CardHeader><CardTitle>Details</CardTitle></CardHeader>
                    <CardContent className="space-y-4">
                        <p className="text-sm text-surface-500">Employee: {ev.employee?.name ?? '-'}</p>
                        <div className="grid gap-4 md:grid-cols-2">
                            <Input label="Score (1.0-5.0)" type="number" step="0.1" min="1" max="5" value={data.score} onChange={(e) => setData('score', e.target.value)} error={errors.score} required />
                            <Input label="Customer Rating (optional)" type="number" step="0.1" min="1" max="5" value={data.customer_rating} onChange={(e) => setData('customer_rating', e.target.value)} error={errors.customer_rating} />
                        </div>
                        <Textarea label="Comment" value={data.comment} onChange={(e) => setData('comment', e.target.value)} error={errors.comment} rows={3} />
                    </CardContent>
                    </Card>
                    <div className="flex justify-end gap-2">
                        <Button type="button" variant="outline" onClick={() => router.get(route('admin.evaluations.show', ev.id))}>Cancel</Button>
                        <Button type="submit" loading={processing}>Save</Button>
                    </div>
                </form>
            </div>
        </AdminLayout>
    );
}
