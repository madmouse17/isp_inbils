import { useMemo, useRef, useState } from 'react';
import { Link, useForm } from '@inertiajs/react';
import { ArrowLeft, Plus, Trash2, CheckCircle2, AlertCircle } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import { PageHeader } from '@/Components/composite/PageHeader';
import { RemoteSearchSelect, type RemoteOption } from '@/Components/composite/RemoteSearchSelect';
import { Button } from '@/Components/ui/Button';
import { DatePicker } from '@/Components/ui/DatePicker';
import { IconButton } from '@/Components/ui/IconButton';
import { Input } from '@/Components/ui/Input';
import { SearchSelect } from '@/Components/ui/SearchSelect';
import { Textarea } from '@/Components/ui/Textarea';
import { NativeSelect } from '@/Components/ui';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/Components/ui/Card';
import { INVENTORY_REQUEST_SOURCE, optionsOf } from '@/lib/statuses';

interface WarehouseOption {
    id: string;
    name: string;
    code?: string | null;
}

/** A form row. `uid` keeps a row's picker state when an earlier row is removed; it is not sent. */
interface ItemRow {
    uid: number;
    product_id: string;
    quantity_requested: string;
    note: string;
}

interface Props {
    warehouses: WarehouseOption[];
    /** Answers of the server-side search; only present after the first search. */
    productOptions?: RemoteOption[];
    workOrderOptions?: RemoteOption[];
    ticketOptions?: RemoteOption[];
}

const SOURCE_TYPE_OPTIONS = [
    { value: '', label: 'Tanpa rujukan' },
    ...optionsOf(INVENTORY_REQUEST_SOURCE),
];

export default function InventoryRequestsCreate({
    warehouses = [],
    productOptions,
    workOrderOptions,
    ticketOptions,
}: Props) {
    const nextUid = useRef(2);
    const [mode, setMode] = useState<'draft' | 'submit'>('draft');
    const { data, setData, post, processing, errors, transform } = useForm<{
        warehouse_location_id: string;
        source_type: string;
        source_id: string;
        needed_at: string;
        reason: string;
        items: ItemRow[];
    }>({
        warehouse_location_id: warehouses[0]?.id ? String(warehouses[0].id) : '',
        source_type: '',
        source_id: '',
        needed_at: '',
        reason: '',
        items: [{ uid: 1, product_id: '', quantity_requested: '1', note: '' }],
    });
    const errorMap: Record<string, string | undefined> = errors;

    const warehouseChoices = useMemo(
        () =>
            warehouses.map((w) => ({
                value: String(w.id),
                label: w.code ? `${w.code} - ${w.name}` : w.name,
            })),
        [warehouses],
    );

    const addItem = () => {
        setData('items', [
            ...data.items,
            { uid: nextUid.current++, product_id: '', quantity_requested: '1', note: '' },
        ]);
    };

    const removeItem = (index: number) => {
        if (data.items.length <= 1) return;
        setData(
            'items',
            data.items.filter((_, i) => i !== index),
        );
    };

    const updateItem = (index: number, field: keyof Omit<ItemRow, 'uid'>, value: string) => {
        setData(
            'items',
            data.items.map((item, i) => (i === index ? { ...item, [field]: value } : item)),
        );
    };

    const handleSubmit = (submitImmediately: boolean) => {
        setMode(submitImmediately ? 'submit' : 'draft');
        transform((prev) => ({
            ...prev,
            submit: submitImmediately,
            items: prev.items.map((item) => ({
                product_id: item.product_id,
                quantity_requested: item.quantity_requested,
                note: item.note,
            })),
        }));
        post(route('admin.inventory.requests.store'));
    };

    return (
        <AdminLayout title="Buat Permintaan Barang">
            <div className="mx-auto space-y-6 max-w-4xl">
                <PageHeader
                    title="Buat Permintaan Barang"
                    description="Ajukan permohonan barang atau alat kerja kepada gudang."
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
                    <div
                        role="alert"
                        className="rounded-lg border border-destructive/50 bg-destructive/10 p-4 text-sm text-destructive-text"
                    >
                        <div className="mb-1 flex items-center gap-2 font-medium">
                            <AlertCircle className="h-4 w-4" />
                            <span>Terdapat kesalahan pengisian formulir:</span>
                        </div>
                        <ul className="space-y-0.5 list-inside list-disc text-xs">
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
                                Tentukan gudang tujuan, tanggal barang dibutuhkan, dan alasan
                                pengambilan.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                                <SearchSelect
                                    id="warehouse_location_id"
                                    label="Gudang Tujuan"
                                    placeholder="Pilih gudang"
                                    options={warehouseChoices}
                                    value={data.warehouse_location_id}
                                    onChange={(value) => setData('warehouse_location_id', value)}
                                    error={errors.warehouse_location_id}
                                />
                                <DatePicker
                                    id="needed_at"
                                    label="Tanggal Dibutuhkan"
                                    value={data.needed_at}
                                    onChange={(value) => setData('needed_at', value)}
                                    error={errors.needed_at}
                                />
                            </div>

                            <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                                <NativeSelect
                                    id="source_type"
                                    label="Rujukan (opsional)"
                                    value={data.source_type}
                                    onChange={(e) => {
                                        setData((prev) => ({
                                            ...prev,
                                            source_type: e.target.value,
                                            source_id: '',
                                        }));
                                    }}
                                    options={SOURCE_TYPE_OPTIONS}
                                    error={errors.source_type}
                                />
                                {data.source_type === 'work_order' && (
                                    <RemoteSearchSelect
                                        key="work_order"
                                        id="source_id"
                                        field="workOrder"
                                        label="SPK"
                                        placeholder="Cari kode atau judul SPK"
                                        required
                                        options={workOrderOptions}
                                        value={data.source_id}
                                        onChange={(value) => setData('source_id', value)}
                                        error={errors.source_id}
                                    />
                                )}
                                {data.source_type === 'ticket' && (
                                    <RemoteSearchSelect
                                        key="ticket"
                                        id="source_id"
                                        field="ticket"
                                        label="Tiket"
                                        placeholder="Cari kode atau judul tiket"
                                        required
                                        options={ticketOptions}
                                        value={data.source_id}
                                        onChange={(value) => setData('source_id', value)}
                                        error={errors.source_id}
                                    />
                                )}
                            </div>

                            <Textarea
                                id="reason"
                                label="Alasan Kebutuhan Barang"
                                rows={3}
                                placeholder="Jelaskan untuk apa barang dibutuhkan, misalnya pemasangan pelanggan baru."
                                value={data.reason}
                                onChange={(e) => setData('reason', e.target.value)}
                                required
                                error={errors.reason}
                            />
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between">
                            <div>
                                <CardTitle>Daftar Barang yang Diminta</CardTitle>
                                <CardDescription>
                                    Pilih barang habis pakai dan jumlah yang diminta.
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
                            {data.items.map((item, idx) => (
                                <div
                                    key={item.uid}
                                    className="grid grid-cols-1 items-start gap-3 rounded-lg border border-border p-3 sm:grid-cols-[minmax(0,2fr)_8rem_minmax(0,1.5fr)_auto]"
                                >
                                    <RemoteSearchSelect
                                        id={`item-product-${item.uid}`}
                                        field="product"
                                        label={`Barang ${idx + 1}`}
                                        placeholder="Cari nama atau SKU"
                                        required
                                        options={productOptions}
                                        value={item.product_id}
                                        onChange={(value) => updateItem(idx, 'product_id', value)}
                                        error={errorMap[`items.${idx}.product_id`]}
                                    />
                                    <Input
                                        id={`item-quantity-${item.uid}`}
                                        type="number"
                                        step="any"
                                        min="0.01"
                                        label="Jumlah"
                                        required
                                        value={item.quantity_requested}
                                        onChange={(e) =>
                                            updateItem(idx, 'quantity_requested', e.target.value)
                                        }
                                        error={errorMap[`items.${idx}.quantity_requested`]}
                                    />
                                    <Input
                                        id={`item-note-${item.uid}`}
                                        type="text"
                                        label="Catatan"
                                        placeholder="Misalnya warna atau ukuran"
                                        value={item.note}
                                        onChange={(e) => updateItem(idx, 'note', e.target.value)}
                                        error={errorMap[`items.${idx}.note`]}
                                    />
                                    <div className="sm:pt-6">
                                        <IconButton
                                            type="button"
                                            label={`Hapus barang ${idx + 1}`}
                                            onClick={() => removeItem(idx)}
                                            disabled={data.items.length <= 1}
                                            variant="destructive-outline"
                                        >
                                            <Trash2 className="h-4 w-4" />
                                        </IconButton>
                                    </div>
                                </div>
                            ))}
                        </CardContent>
                    </Card>

                    <div className="flex flex-col items-center justify-end gap-3 border-t border-border pt-4 sm:flex-row">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => handleSubmit(false)}
                            disabled={processing}
                            loading={processing && mode === 'draft'}
                        >
                            Simpan sebagai Draf
                        </Button>
                        <Button
                            type="button"
                            variant="primary"
                            onClick={() => handleSubmit(true)}
                            disabled={processing}
                            loading={processing && mode === 'submit'}
                            className="gap-2"
                        >
                            <CheckCircle2 className="h-4 w-4" />
                            Ajukan ke Antrean Gudang
                        </Button>
                    </div>
                </form>
            </div>
        </AdminLayout>
    );
}
