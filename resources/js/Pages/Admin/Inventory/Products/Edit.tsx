import { FormEvent } from 'react';
import { router, useForm } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { PageHeader } from '@/Components/composite';
import { Button, Card, CardContent, CardFooter, CardHeader, CardTitle, Input, Select, Switch, Textarea } from '@/Components/ui';
import type { Category, Product, Unit } from '@/types/inventory';

interface EditProps extends Record<string, unknown> {
    product: { data: Product };
    categories: { data: Category[] };
    units: { data: Unit[] };
}

export default function Edit({ product, categories, units }: EditProps) {
    const p = product.data;
    const { data, setData, post, processing, errors } = useForm({
        sku: p.sku,
        name: p.name,
        category_id: String(p.category_id ?? ''),
        unit_id: String(p.unit_id ?? ''),
        description: p.description ?? '',
        sell_price: p.sell_price ?? '',
        cost_price: p.cost_price ?? '',
        min_stock: String(p.min_stock ?? ''),
        track_stock: p.track_stock,
        is_active: p.is_active,
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        router.put(route('admin.products.update', p.id), data);
    };

    return (
        <AdminLayout title={`Edit ${p.name}`}>
            <div className="space-y-6">
                <PageHeader title="Edit Product" subtitle="Update product master data." />

                <form onSubmit={submit}>
                <Card>
                    <CardHeader><CardTitle>Edit Product</CardTitle></CardHeader>
                    <CardContent className="space-y-4">
                        <div className="grid gap-4 md:grid-cols-2">
                            <Input label="SKU" value={data.sku} onChange={(e) => setData('sku', e.target.value)} error={errors.sku} required />
                            <Input label="Name" value={data.name} onChange={(e) => setData('name', e.target.value)} error={errors.name} required />
                            <Select label="Category" value={data.category_id} onChange={(e) => setData('category_id', e.target.value)} error={errors.category_id} required>
                                <option value="">Select category</option>
                                {categories.data.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                            </Select>
                            <Select label="Unit" value={data.unit_id} onChange={(e) => setData('unit_id', e.target.value)} error={errors.unit_id} required>
                                <option value="">Select unit</option>
                                {units.data.map((u) => <option key={u.id} value={u.id}>{u.name} ({u.symbol})</option>)}
                            </Select>
                            <Input label="Sell Price" type="number" step="0.01" value={data.sell_price} onChange={(e) => setData('sell_price', e.target.value)} error={errors.sell_price} />
                            <Input label="Cost Price" type="number" step="0.01" value={data.cost_price} onChange={(e) => setData('cost_price', e.target.value)} error={errors.cost_price} />
                            <Input label="Min Stock" type="number" step="0.01" value={data.min_stock} onChange={(e) => setData('min_stock', e.target.value)} error={errors.min_stock} />
                            <div className="flex items-center gap-6">
                                <Switch label="Track Stock" checked={data.track_stock} onCheckedChange={(checked) => setData('track_stock', checked)} />
                                <Switch label="Active" checked={data.is_active} onCheckedChange={(checked) => setData('is_active', checked)} />
                            </div>
                        </div>
                        <Textarea label="Description" value={data.description} onChange={(e) => setData('description', e.target.value)} error={errors.description} rows={3} />
                    </CardContent>
                    <CardFooter className="justify-end gap-2">
                        <Button type="button" variant="secondary" onClick={() => router.get(route('admin.products.index'))}>Cancel</Button>
                        <Button type="submit" loading={processing}>Save</Button>
                    </CardFooter>
                </Card>
            </form>
            </div>
        </AdminLayout>
    );
}
