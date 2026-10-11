import React from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, Plus, Trash2, CheckCircle2, AlertCircle } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { PageHeader } from '@/Components/composite/PageHeader';
import { Button } from '@/Components/ui/Button';
import { Input } from '@/Components/ui/Input';
import { Label } from '@/Components/ui/Label';
import { Textarea } from '@/Components/ui/Textarea';
import { NativeSelect } from '@/Components/ui';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/Components/ui/Card';

interface ProductOption {
    id: number;
    name: string;
    code?: string | null;
    type?: string;
    unit?: {
        id: number;
        name: string;
        symbol?: string | null;
    } | null;
}

interface WarehouseOption {
    id: number;
    name: string;
    code?: string | null;
    type?: string;
}

interface ItemRow {
    product_id: string | number;
    quantity_requested: string | number;
    note: string;
}

interface Props {
    warehouses: WarehouseOption[];
    products: ProductOption[];
}

export default function InventoryRequestsCreate({ warehouses = [], products = [] }: Props) {
    const { data, setData, post, processing, errors, transform } = useForm<{
        warehouse_location_id: string;
        source_type: string;
        source_id: string;
        needed_at: string;
        reason: string;
        submit: boolean;
        items: ItemRow[];
    }>({
        warehouse_location_id: warehouses[0]?.id ? String(warehouses[0].id) : '',
        source_type: '',
        source_id: '',
        needed_at: '',
        reason: '',
        submit: false,
        items: [
            {
                product_id: products[0]?.id ? String(products[0].id) : '',
                quantity_requested: 1,
                note: '',
            },
        ],
    });

    const addItem = () => {
        setData('items', [
            ...data.items,
            {
                product_id: products[0]?.id ? String(products[0].id) : '',
                quantity_requested: 1,
                note: '',
            },
        ]);
    };

    const removeItem = (index: number) => {
        if (data.items.length <= 1) return;
        const newItems = [...data.items];
        newItems.splice(index, 1);
        setData('items', newItems);
    };

    const updateItem = (index: number, field: keyof ItemRow, value: string | number) => {
        const newItems = [...data.items];
        newItems[index] = { ...newItems[index], [field]: value };
        setData('items', newItems);
    };

    const handleSubmit = (submitImmediately: boolean) => {
        transform((prev) => ({
            ...prev,
            submit: submitImmediately,
        }));
        post(route('admin.inventory.requests.store'));
    };

    return (
        <AppLayout>
            <Head title="Buat Permintaan Barang Baru" />
            <div className="mx-auto max-w-4xl space-y-6 p-4 md:p-6">
                <PageHeader
                    title="Buat Permintaan Barang"
                    description="Ajukan permohonan material atau alat kerja kepada bagian gudang."
                    actions={
                        <Button
                            asChild
                            variant="outline"
                            size="sm"
                            leftIcon={<ArrowLeft className="size-4" />}
                        >
                            <Link href={route('admin.inventory.requests.index')}>Kembali</Link>
                        </Button>
                    }
                />

                {Object.keys(errors).length > 0 && (
                    <div className="rounded-lg border border-destructive/50 bg-destructive/10 p-4 text-sm text-destructive">
                        <div className="mb-1 flex items-center gap-2 font-medium">
                            <AlertCircle className="h-4 w-4" />
                            <span>Terdapat kesalahan pengisian formulir:</span>
                        </div>
                        <ul className="list-inside list-disc space-y-0.5 text-xs">
                            {Object.entries(errors).map(([key, msg]) => (
                                <li key={key}>{msg}</li>
                            ))}
                        </ul>
                    </div>
                )}

                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                    }}
                    className="space-y-6"
                >
                    <Card>
                        <CardHeader>
                            <CardTitle>Informasi Pengajuan</CardTitle>
                            <CardDescription>
                                Tentukan lokasi gudang tujuan, jadwal kebutuhan, dan alasan
                                pengambilan.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                                <div className="space-y-2">
                                    <Label htmlFor="warehouse_location_id">Gudang Tujuan</Label>
                                    <NativeSelect
                                        id="warehouse_location_id"
                                        value={data.warehouse_location_id}
                                        onChange={(e) =>
                                            setData('warehouse_location_id', e.target.value)
                                        }
                                        className="h-9 text-sm"
                                    >
                                        <option value="">Pilih Gudang...</option>
                                        {warehouses.map((w) => (
                                            <option key={w.id} value={w.id}>
                                                {w.name} {w.code ? `(${w.code})` : ''}
                                            </option>
                                        ))}
                                    </NativeSelect>
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="needed_at">Tanggal Dibutuhkan</Label>
                                    <Input
                                        id="needed_at"
                                        type="date"
                                        value={data.needed_at}
                                        onChange={(e) => setData('needed_at', e.target.value)}
                                        className="h-9"
                                    />
                                </div>
                            </div>

                            <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                                <div className="space-y-2">
                                    <Label htmlFor="source_type">
                                        Kategori Referensi (Opsional)
                                    </Label>
                                    <NativeSelect
                                        id="source_type"
                                        value={data.source_type}
                                        onChange={(e) => setData('source_type', e.target.value)}
                                        className="h-9 text-sm"
                                    >
                                        <option value="">Tidak ada referensi</option>
                                        <option value="work_order">SPK / Work Order</option>
                                        <option value="ticket">Tiket Layanan</option>
                                    </NativeSelect>
                                </div>

                                {data.source_type && (
                                    <div className="space-y-2">
                                        <Label htmlFor="source_id">
                                            ID {data.source_type === 'work_order' ? 'SPK' : 'Tiket'}
                                        </Label>
                                        <Input
                                            id="source_id"
                                            type="number"
                                            placeholder="Masukkan ID referensi..."
                                            value={data.source_id}
                                            onChange={(e) => setData('source_id', e.target.value)}
                                            className="h-9"
                                        />
                                    </div>
                                )}
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="reason">Alasan Kebutuhan Barang *</Label>
                                <Textarea
                                    id="reason"
                                    rows={3}
                                    placeholder="Contoh: Kebutuhan penarikan kabel dropcore pelanggan baru di Perum Grihi Asri"
                                    value={data.reason}
                                    onChange={(e) => setData('reason', e.target.value)}
                                    required
                                />
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between">
                            <div>
                                <CardTitle>Daftar Barang yang Diminta</CardTitle>
                                <CardDescription>
                                    Pilih jenis barang (consumable/material) dan jumlah kuantitas
                                    yang diajukan.
                                </CardDescription>
                            </div>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={addItem}
                                className="gap-1.5"
                            >
                                <Plus className="h-4 w-4" />
                                Tambah Barang
                            </Button>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            {data.items.map((item, idx) => {
                                const selectedProduct = products.find(
                                    (p) => String(p.id) === String(item.product_id),
                                );
                                const unitSymbol =
                                    selectedProduct?.unit?.symbol ||
                                    selectedProduct?.unit?.name ||
                                    'unit';

                                return (
                                    <div
                                        key={idx}
                                        className="flex flex-col items-start gap-3 rounded-lg border border-border bg-card/50 p-3 sm:flex-row sm:items-center"
                                    >
                                        <div className="w-full flex-1 sm:w-auto">
                                            <Label className="mb-1 block text-xs sm:hidden">
                                                Barang
                                            </Label>
                                            <NativeSelect
                                                value={item.product_id}
                                                onChange={(e) =>
                                                    updateItem(idx, 'product_id', e.target.value)
                                                }
                                                className="h-9 text-sm"
                                            >
                                                {products.map((p) => (
                                                    <option key={p.id} value={p.id}>
                                                        {p.name} {p.code ? `[${p.code}]` : ''}
                                                    </option>
                                                ))}
                                            </NativeSelect>
                                        </div>

                                        <div className="w-full sm:w-32">
                                            <Label className="mb-1 block text-xs sm:hidden">
                                                Qty ({unitSymbol})
                                            </Label>
                                            <div className="relative">
                                                <Input
                                                    type="number"
                                                    step="any"
                                                    min="0.01"
                                                    placeholder="Jumlah"
                                                    value={item.quantity_requested}
                                                    onChange={(e) =>
                                                        updateItem(
                                                            idx,
                                                            'quantity_requested',
                                                            e.target.value,
                                                        )
                                                    }
                                                    className="h-9 pr-10"
                                                    required
                                                />
                                                <span className="pointer-events-none absolute right-2.5 top-2 text-xs text-muted-foreground">
                                                    {unitSymbol}
                                                </span>
                                            </div>
                                        </div>

                                        <div className="w-full flex-1 sm:w-auto">
                                            <Label className="mb-1 block text-xs sm:hidden">
                                                Catatan (opsional)
                                            </Label>
                                            <Input
                                                type="text"
                                                placeholder="Catatan item (cth: warna biru)"
                                                value={item.note}
                                                onChange={(e) =>
                                                    updateItem(idx, 'note', e.target.value)
                                                }
                                                className="h-9 text-sm"
                                            />
                                        </div>

                                        <div className="self-end sm:self-center">
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="icon"
                                                onClick={() => removeItem(idx)}
                                                disabled={data.items.length <= 1}
                                                className="text-destructive hover:bg-destructive/10"
                                                title="Hapus baris ini"
                                            >
                                                <Trash2 className="h-4 w-4" />
                                            </Button>
                                        </div>
                                    </div>
                                );
                            })}
                        </CardContent>
                    </Card>

                    <div className="flex flex-col items-center justify-end gap-3 border-t border-border pt-4 sm:flex-row">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => handleSubmit(false)}
                            disabled={processing}
                            loading={processing && !data.submit}
                        >
                            Simpan sebagai Draf
                        </Button>
                        <Button
                            type="button"
                            variant="success"
                            onClick={() => handleSubmit(true)}
                            disabled={processing}
                            loading={processing && data.submit}
                            className="gap-2"
                        >
                            <CheckCircle2 className="h-4 w-4" />
                            Ajukan ke Antrean Gudang
                        </Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
