import { FormEvent } from 'react';
import { router, useForm } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Button, Card, CardContent, CardHeader, CardTitle, Input, Select, Textarea } from '@/Components/ui';
import { PageHeader } from '@/Components/composite';

interface CatRow { id: number; name: string }
interface CustRow { id: number; name: string }
interface SubRow { id: number; code: string }
interface AssetRow { id: number; code: string; name: string }
interface LocRow { id: number; name: string; code: string }

interface CreateProps extends Record<string, unknown> {
    categories: { data: CatRow[] };
    customers: { data: CustRow[] };
    subscriptions: { data: SubRow[] };
    assets: { data: AssetRow[] };
    locations: { data: LocRow[] };
}

export default function Create({ categories, customers, subscriptions, assets, locations }: CreateProps) {
    const { data, setData, post, processing, errors } = useForm({
        title: '', description: '', source: 'customer', category_id: '', priority: 'medium',
        customer_id: '', subscription_id: '', network_asset_id: '', location_id: '',
    });

    const submit = (e: FormEvent) => { e.preventDefault(); post(route('admin.tickets.store')); };

    return (
        <AdminLayout title="Create Ticket">
            <div className="space-y-6">
                <PageHeader title="Create Ticket" subtitle="Fill required fields, then save." />
                <form onSubmit={submit} className="space-y-6">
                    <Card>
                        <CardHeader>
                            <CardTitle>Details</CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-4 md:grid-cols-2">
                            <Input label="Title" value={data.title} onChange={(e) => setData('title', e.target.value)} error={errors.title} required />
                            <Select label="Source" value={data.source} onChange={(e) => setData('source', e.target.value)} error={errors.source} required>
                                <option value="customer">Customer</option>
                                <option value="noc">NOC</option>
                                <option value="internal">Internal</option>
                            </Select>
                            <Select label="Category" value={data.category_id} onChange={(e) => setData('category_id', e.target.value)} error={errors.category_id} required>
                                <option value="">Select...</option>
                                {categories.data.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                            </Select>
                            <Select label="Priority" value={data.priority} onChange={(e) => setData('priority', e.target.value)} error={errors.priority}>
                                <option value="low">Low</option>
                                <option value="medium">Medium</option>
                                <option value="high">High</option>
                                <option value="urgent">Urgent</option>
                            </Select>
                            <Select label="Customer" value={data.customer_id} onChange={(e) => setData('customer_id', e.target.value)} error={errors.customer_id}>
                                <option value="">Select...</option>
                                {customers.data.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                            </Select>
                            <Select label="Subscription" value={data.subscription_id} onChange={(e) => setData('subscription_id', e.target.value)} error={errors.subscription_id}>
                                <option value="">None</option>
                                {subscriptions.data.map((s) => <option key={s.id} value={s.id}>{s.code}</option>)}
                            </Select>
                            <Select label="Network Asset" value={data.network_asset_id} onChange={(e) => setData('network_asset_id', e.target.value)} error={errors.network_asset_id}>
                                <option value="">None</option>
                                {assets.data.map((a) => <option key={a.id} value={a.id}>{a.code} — {a.name}</option>)}
                            </Select>
                            <Select label="Location" value={data.location_id} onChange={(e) => setData('location_id', e.target.value)} error={errors.location_id}>
                                <option value="">None</option>
                                {locations.data.map((l) => <option key={l.id} value={l.id}>{l.code} — {l.name}</option>)}
                            </Select>
                            <Textarea className="md:col-span-2" label="Description" value={data.description} onChange={(e) => setData('description', e.target.value)} error={errors.description} rows={3} />
                        </CardContent>
                    </Card>
                    <div className="flex justify-end gap-2">
                        <Button type="button" variant="outline" onClick={() => router.get(route('admin.tickets.index'))}>Cancel</Button>
                        <Button type="submit" loading={processing}>Create</Button>
                    </div>
                </form>
            </div>
        </AdminLayout>
    );
}
