import { FormEvent } from 'react';
import { router, useForm } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Button, Card, CardContent, CardFooter, CardHeader, CardTitle, Input, Select, Textarea } from '@/Components/ui';

interface OptRow { id: number; name: string }
interface SubRow { id: number; code: string }
interface LocRow { id: number; name: string; code: string }
interface TechRow { id: number; name: string }

interface CreateProps extends Record<string, unknown> {
    customers: { data: OptRow[] };
    subscriptions: { data: SubRow[] };
    locations: { data: LocRow[] };
    technicians: { data: TechRow[] };
}

export default function Create({ customers, subscriptions, locations }: CreateProps) {
    const { data, setData, post, processing, errors } = useForm({
        type: 'installation', title: '', description: '',
        customer_id: '', subscription_id: '', location_id: '',
        source: 'manual', priority: 'medium', scheduled_date: '',
    });

    const submit = (e: FormEvent) => { e.preventDefault(); post(route('admin.spk.store')); };

    return (
        <AdminLayout title="Create SPK">
            <form onSubmit={submit}>
                <Card>
                    <CardHeader><CardTitle>Create SPK</CardTitle></CardHeader>
                    <CardContent className="space-y-4">
                        <div className="grid gap-4 md:grid-cols-2">
                            <Select label="Type" value={data.type} onChange={(e) => setData('type', e.target.value)} error={errors.type} required>
                                <option value="installation">Installation</option>
                                <option value="maintenance">Maintenance</option>
                                <option value="upgrade_service">Upgrade Service</option>
                                <option value="relocation">Relocation</option>
                            </Select>
                            <Input label="Title" value={data.title} onChange={(e) => setData('title', e.target.value)} error={errors.title} required />
                            <Select label="Customer" value={data.customer_id} onChange={(e) => setData('customer_id', e.target.value)} error={errors.customer_id}>
                                <option value="">Select...</option>
                                {customers.data.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                            </Select>
                            <Select label="Subscription" value={data.subscription_id} onChange={(e) => setData('subscription_id', e.target.value)} error={errors.subscription_id}>
                                <option value="">Select...</option>
                                {subscriptions.data.map((s) => <option key={s.id} value={s.id}>{s.code}</option>)}
                            </Select>
                            <Select label="Location" value={data.location_id} onChange={(e) => setData('location_id', e.target.value)} error={errors.location_id}>
                                <option value="">Select...</option>
                                {locations.data.map((l) => <option key={l.id} value={l.id}>{l.code} — {l.name}</option>)}
                            </Select>
                            <Select label="Priority" value={data.priority} onChange={(e) => setData('priority', e.target.value)}>
                                <option value="low">Low</option>
                                <option value="medium">Medium</option>
                                <option value="high">High</option>
                                <option value="urgent">Urgent</option>
                            </Select>
                            <Input label="Scheduled Date" type="date" value={data.scheduled_date} onChange={(e) => setData('scheduled_date', e.target.value)} error={errors.scheduled_date} />
                        </div>
                        <Textarea label="Description" value={data.description} onChange={(e) => setData('description', e.target.value)} error={errors.description} rows={3} />
                    </CardContent>
                    <CardFooter className="justify-end gap-2">
                        <Button type="button" variant="secondary" onClick={() => router.get(route('admin.spk.index'))}>Cancel</Button>
                        <Button type="submit" loading={processing}>Create</Button>
                    </CardFooter>
                </Card>
            </form>
        </AdminLayout>
    );
}
