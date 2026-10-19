import type { FormEvent } from 'react';
import { useEffect } from 'react';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import {
    ConflictBanner,
    PageHeader,
    SequenceCodeField,
    type CodeSequence,
} from '@/Components/composite';
import {
    Button,
    Card,
    CardContent,
    CardHeader,
    CardTitle,
    Input,
    SearchSelect,
    Switch,
    Textarea,
} from '@/Components/ui';
import type { PageProps } from '@/types';
import type { Category, Product } from '@/types/inventory';

interface EditProps extends Record<string, unknown> {
    product: { data: Product & { updated_at: string } };
    categories: { data: Category[] };
    codeSequence: CodeSequence;
}

export default function Edit({ product, categories, codeSequence }: EditProps) {
    const p = product.data;
    const { data, setData, processing, errors } = useForm({
        sku: p.sku,
        name: p.name,
        category_id: String(p.category_id ?? ''),
        description: p.description ?? '',
        sell_price: p.sell_price ?? '',
        cost_price: p.cost_price ?? '',
        min_stock: String(p.min_stock ?? ''),
        track_stock: p.track_stock,
        is_active: p.is_active,
        expected_updated_at: p.updated_at,
    });
    const conflict = usePage<PageProps>().props.errors.conflict;
    // A conflict reply carries the newer row: keeping the old version makes the retry conflict again until the page is reloaded.
    useEffect(() => {
        if (!conflict) setData('expected_updated_at', p.updated_at);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [p.updated_at]);

    const categoryChoices = categories.data.map((c) => ({
        value: String(c.id),
        label: `${c.code} - ${c.name}`,
        description: c.unit ? `Satuan: ${c.unit.name} (${c.unit.symbol})` : undefined,
    }));
    const selectedCategory = categories.data.find(
        (category) => String(category.id) === String(data.category_id),
    );
    const unitLabel = selectedCategory?.unit
        ? `${selectedCategory.unit.name} (${selectedCategory.unit.symbol})`
        : p.unit
          ? `${p.unit.name} (${p.unit.symbol})`
          : 'Pilih kategori dulu';

    const submit = (e: FormEvent) => {
        e.preventDefault();
        // A locked SKU never changes after creation, so it is not sent.
        const { sku: _sku, ...rest } = data;
        router.put(route('admin.products.update', p.id), codeSequence.manual ? data : rest);
    };

    return (
        <AdminLayout title="Ubah Barang">
            <div className="space-y-6">
                <PageHeader
                    title="Ubah Barang"
                    subtitle="Perbarui data induk barang."
                    actions={
                        <Button
                            asChild
                            variant="outline"
                            size="sm"
                            leftIcon={<ArrowLeft className="size-4" />}
                        >
                            <Link href={route('admin.products.show', p.id)}>Kembali</Link>
                        </Button>
                    }
                />
                <ConflictBanner />

                <form onSubmit={submit} className="space-y-6">
                    <Card>
                        <CardHeader>
                            <CardTitle>Data Barang</CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-4 md:grid-cols-2">
                            <SequenceCodeField
                                label="SKU"
                                value={data.sku}
                                onChange={(next) => setData('sku', next)}
                                error={errors.sku}
                                sequence={codeSequence}
                                mode="edit"
                            />
                            <Input
                                label="Nama"
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                error={errors.name}
                                required
                            />
                            <SearchSelect
                                id="product-category"
                                label="Kategori"
                                placeholder="Cari kategori"
                                emptyText="Tidak ada kategori yang cocok."
                                options={categoryChoices}
                                selected={
                                    p.category
                                        ? {
                                              value: String(p.category.id),
                                              label: `${p.category.code} - ${p.category.name}`,
                                          }
                                        : null
                                }
                                value={data.category_id}
                                onChange={(value) => setData('category_id', value)}
                                error={errors.category_id}
                                required
                            />
                            <Input
                                label="Satuan"
                                value={unitLabel}
                                readOnly
                                disabled
                                hint="Satuan mengikuti kategori yang dipilih."
                            />
                            <Input
                                label="Harga Jual"
                                type="number"
                                step="0.01"
                                value={data.sell_price}
                                onChange={(e) => setData('sell_price', e.target.value)}
                                error={errors.sell_price}
                            />
                            <Input
                                label="Harga Pokok"
                                type="number"
                                step="0.01"
                                value={data.cost_price}
                                onChange={(e) => setData('cost_price', e.target.value)}
                                error={errors.cost_price}
                            />
                            <Input
                                label="Stok Minimum"
                                type="number"
                                step="0.01"
                                value={data.min_stock}
                                onChange={(e) => setData('min_stock', e.target.value)}
                                error={errors.min_stock}
                            />
                            <div className="flex items-center gap-6">
                                <Switch
                                    label="Lacak stok"
                                    checked={data.track_stock}
                                    onCheckedChange={(checked) => setData('track_stock', checked)}
                                />
                                <Switch
                                    label="Aktif"
                                    checked={data.is_active}
                                    onCheckedChange={(checked) => setData('is_active', checked)}
                                />
                            </div>
                            <div className="md:col-span-2">
                                <Textarea
                                    label="Deskripsi"
                                    value={data.description}
                                    onChange={(e) => setData('description', e.target.value)}
                                    error={errors.description}
                                    rows={3}
                                />
                            </div>
                        </CardContent>
                    </Card>
                    <div className="flex justify-end gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => router.get(route('admin.products.index'))}
                        >
                            Batal
                        </Button>
                        <Button type="submit" loading={processing}>
                            Simpan
                        </Button>
                    </div>
                </form>
            </div>
        </AdminLayout>
    );
}
