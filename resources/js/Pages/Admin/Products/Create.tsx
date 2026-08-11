import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Button } from '@/Components/ui/Button';
import { Input } from '@/Components/ui/Input';
import { Label } from '@/Components/ui/Label';
import { Textarea } from '@/Components/ui/Textarea';
import { Switch } from '@/Components/ui/Switch';
import { NativeSelect } from '@/Components/ui';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/Card';

type Option = { id: number | string; name: string };

type Props = {
    categories?: Option[];
    brands?: Option[];
    units?: Option[];
};

export default function Create({ categories = [], brands = [], units = [] }: Props) {
    const { data, setData, post, processing, errors } = useForm({
        sku: '',
        name: '',
        category_id: '',
        brand_id: '',
        unit_id: '',
        sell_price: '',
        cost_price: '',
        min_stock: '',
        track_stock: true as boolean,
        is_active: true as boolean,
        description: '',
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        post(route('admin.products.store'));
    };

    return (
        <AdminLayout>
            <Head title="Create Product" />
            <Card>
                <CardHeader>
                    <CardTitle>Create Product</CardTitle>
                </CardHeader>
                <CardContent>
                    <form onSubmit={submit} className="space-y-4">
                        <div className="grid gap-2">
                            <Label htmlFor="sku">SKU</Label>
                            <Input
                                id="sku"
                                value={data.sku}
                                onChange={(e) => setData('sku', e.target.value)}
                            />
                            {errors.sku ? (
                                <p className="text-sm text-danger" role="alert">
                                    {errors.sku}
                                </p>
                            ) : null}
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="name">Name</Label>
                            <Input
                                id="name"
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                            />
                            {errors.name ? (
                                <p className="text-sm text-danger" role="alert">
                                    {errors.name}
                                </p>
                            ) : null}
                        </div>

                        <div className="grid gap-2">
                            <Label>Category</Label>
                            <NativeSelect
                                value={data.category_id ?? ''}
                                onChange={(e) => setData('category_id', e.target.value)}
                            >
                                <option value="">Select category</option>
                                {categories.map((c) => (
                                    <option key={String(c.id)} value={String(c.id)}>
                                        {c.name}
                                    </option>
                                ))}
                            </NativeSelect>
                        </div>

                        <div className="grid gap-2">
                            <Label>Brand</Label>
                            <NativeSelect
                                value={data.brand_id ?? ''}
                                onChange={(e) => setData('brand_id', e.target.value)}
                            >
                                <option value="">Select brand</option>
                                {brands.map((b) => (
                                    <option key={String(b.id)} value={String(b.id)}>
                                        {b.name}
                                    </option>
                                ))}
                            </NativeSelect>
                        </div>

                        <div className="grid gap-2">
                            <Label>Unit</Label>
                            <NativeSelect
                                value={data.unit_id ?? ''}
                                onChange={(e) => setData('unit_id', e.target.value)}
                            >
                                <option value="">Select unit</option>
                                {units.map((u) => (
                                    <option key={String(u.id)} value={String(u.id)}>
                                        {u.name}
                                    </option>
                                ))}
                            </NativeSelect>
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="sell_price">Sell price</Label>
                            <Input
                                id="sell_price"
                                value={data.sell_price ?? ''}
                                onChange={(e) => setData('sell_price', e.target.value)}
                            />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="cost_price">Cost price</Label>
                            <Input
                                id="cost_price"
                                value={data.cost_price ?? ''}
                                onChange={(e) => setData('cost_price', e.target.value)}
                            />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="min_stock">Min stock</Label>
                            <Input
                                id="min_stock"
                                value={data.min_stock ?? ''}
                                onChange={(e) => setData('min_stock', e.target.value)}
                            />
                        </div>

                        <div className="flex items-center gap-3">
                            <Switch
                                checked={Boolean(data.track_stock)}
                                onCheckedChange={(checked: boolean) =>
                                    setData('track_stock', checked)
                                }
                            />
                            <Label>Track stock</Label>
                        </div>

                        <div className="flex items-center gap-3">
                            <Switch
                                checked={Boolean(data.is_active)}
                                onCheckedChange={(checked: boolean) =>
                                    setData('is_active', checked)
                                }
                            />
                            <Label>Active</Label>
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="description">Description</Label>
                            <Textarea
                                id="description"
                                value={data.description}
                                onChange={(e) => setData('description', e.target.value)}
                            />
                        </div>

                        <Button type="submit" disabled={processing}>
                            Save
                        </Button>
                    </form>
                </CardContent>
            </Card>
        </AdminLayout>
    );
}
