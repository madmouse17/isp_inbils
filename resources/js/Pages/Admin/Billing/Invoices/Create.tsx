import { FormEvent } from 'react';
import { router, useForm } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Button, Card, CardContent, CardFooter, CardHeader, CardTitle, Input, Select, Textarea } from '@/Components/ui';

interface CustRow { id: number; name: string }
interface SubRow { id: number; code: string }

interface CreateProps extends Record<string, unknown> {
    customers: { data: CustRow[] };
    subscriptions: { data: SubRow[] };
}

export default function Create({ customers, subscriptions }: CreateProps) {
    const { data, setData, post, processing, errors } = useForm({
        customer_id: '', subscription_id: '', issue_date: '', due_date: '', notes: '',
    });

    const submit = (e: FormEvent) => { e.preventDefault(); post(route('admin.invoices.store')); };

    return (
        <AdminLayout title="Create Invoice">
            <form onSubmit={submit}>
                <Card>
                    <CardHeader><CardTitle>Create Invoice</CardTitle></CardHeader>
                    <CardContent className="space-y-4">
                        <div className="grid gap-4 md:grid-cols-2">
                            <Select label="Customer" value={data.customer_id} onChange={(e) => setData('customer_id', e.target.value)} error={errors.customer_id} required>
                                <option value="">Select...</option>
                                {customers.data.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                            </Select>
                            <Select label="Subscription (optional)" value={data.subscription_id} onChange={(e) => setData('subscription_id', e.target.value)} error={errors.subscription_id}>
                                <option value="">None</option>
                                {subscriptions.data.map((s) => <option key={s.id} value={s.id}>{s.code}</option>)}
                            </Select>
                            <Input label="Issue Date" type="date" value={data.issue_date} onChange={(e) => setData('issue_date', e.target.value)} error={errors.issue_date} />
                            <Input label="Due Date" type="date" value={data.due_date} onChange={(e) => setData('due_date', e.target.value)} error={errors.due_date} />
                        </div>
                        <Textarea label="Notes" value={data.notes} onChange={(e) => setData('notes', e.target.value)} error={errors.notes} rows={3} />
                    </CardContent>
                    <CardFooter className="justify-end gap-2">
                        <Button type="button" variant="secondary" onClick={() => router.get(route('admin.invoices.index'))}>Cancel</Button>
                        <Button type="submit" loading={processing}>Create</Button>
                    </CardFooter>
                </Card>
            </form>
        </AdminLayout>
    );
}
