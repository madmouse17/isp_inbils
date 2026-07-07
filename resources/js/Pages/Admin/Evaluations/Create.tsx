import { FormEvent } from 'react';
import { router, useForm } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Button, Card, CardContent, CardHeader, CardTitle, Input, Select, Textarea } from '@/Components/ui';
import { PageHeader } from '@/Components/composite';

interface EmpRow { id: number; name: string }

interface CreateProps extends Record<string, unknown> {
    employees: { data: EmpRow[] };
}

export default function Create({ employees }: CreateProps) {
    const { data, setData, post, processing, errors } = useForm({
        employee_id: '', reference_type: 'Ticket', reference_id: '',
        score: '5.0', customer_rating: '', comment: '',
    });

    const submit = (e: FormEvent) => { e.preventDefault(); post(route('admin.evaluations.store')); };

    return (
        <AdminLayout title="Create Evaluation">
            <div className="space-y-6">
                <PageHeader title="Create Evaluation" subtitle="Fill required fields, then save." />

                <form onSubmit={submit} className="space-y-6">
                    <Card>
                    <CardHeader><CardTitle>Details</CardTitle></CardHeader>
                    <CardContent className="space-y-4">
                        <div className="grid gap-4 md:grid-cols-2">
                            <Select label="Employee" value={data.employee_id} onChange={(e) => setData('employee_id', e.target.value)} error={errors.employee_id} required>
                                <option value="">Select...</option>
                                {employees.data.map((e) => <option key={e.id} value={e.id}>{e.name}</option>)}
                            </Select>
                            <Select label="Reference Type" value={data.reference_type} onChange={(e) => setData('reference_type', e.target.value)} error={errors.reference_type} required>
                                <option value="Ticket">Ticket</option>
                                <option value="WorkOrder">SPK (WorkOrder)</option>
                            </Select>
                            <Input label="Reference ID" type="number" value={data.reference_id} onChange={(e) => setData('reference_id', e.target.value)} error={errors.reference_id} required />
                            <Input label="Score (1.0-5.0)" type="number" step="0.1" min="1" max="5" value={data.score} onChange={(e) => setData('score', e.target.value)} error={errors.score} required />
                            <Input label="Customer Rating (optional)" type="number" step="0.1" min="1" max="5" value={data.customer_rating} onChange={(e) => setData('customer_rating', e.target.value)} error={errors.customer_rating} />
                        </div>
                        <Textarea label="Comment" value={data.comment} onChange={(e) => setData('comment', e.target.value)} error={errors.comment} rows={3} />
                    </CardContent>
                    </Card>
                    <div className="flex justify-end gap-2">
                        <Button type="button" variant="outline" onClick={() => router.get(route('admin.evaluations.index'))}>Cancel</Button>
                        <Button type="submit" loading={processing}>Create</Button>
                    </div>
                </form>
            </div>
        </AdminLayout>
    );
}
