import type { FormEvent } from 'react';
import { useRef, useState } from 'react';
import { Link, router, useForm } from '@inertiajs/react';
import { ArrowLeft, Plus, Trash2 } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import {
    Button,
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
    DatePicker,
    Input,
    Textarea,
} from '@/Components/ui';
import { MoneyInput, PageHeader, RemoteSearchSelect } from '@/Components/composite';
import type { RemoteOption } from '@/Components/composite';
import { useCompany } from '@/hooks/useCompany';
import { formatDecimal, formatRupiah } from '@/lib/format';
import { addDays, todayDay } from '@/lib/suggest';

interface CreateProps extends Record<string, unknown> {
    /** Days from the issue date to the due date, from the company setting. */
    due_days: number;
    /** The company's PPN in percent: every line of the invoice takes it. */
    tax_rate: number;
    customerOptions?: RemoteOption[];
    subscriptionOptions?: RemoteOption[];
}

/** One line as typed. A row with no description and no price is left out of the request. */
interface ItemRow {
    key: number;
    description: string;
    quantity: string;
    unit_price: number | null;
}

const MAX_ITEMS = 20;

const blankRow = (key: number): ItemRow => ({
    key,
    description: '',
    quantity: '1',
    unit_price: null,
});

const isStarted = (row: ItemRow) => row.description.trim() !== '' || row.unit_price !== null;

/** Whole rupiah, half up: the way the server builds every line and tax (K1). */
const lineTotal = (row: ItemRow) => {
    const quantity = Number(row.quantity);

    return Math.round((Number.isFinite(quantity) ? quantity : 0) * (row.unit_price ?? 0));
};

/** The last day of the month of `day` (`Y-m-d`); null when `day` is not one. */
function endOfMonth(day: string): string | null {
    const [year, month] = day.split('-').map(Number);

    return year && month ? todayDay(new Date(year, month, 0)) : null;
}

export default function Create({
    due_days: dueDays,
    tax_rate: taxRate,
    customerOptions,
    subscriptionOptions,
}: CreateProps) {
    const company = useCompany();
    const nextKey = useRef(1);
    // The rows the last submit sent, by their place in the form: the server names an error by its place in the request.
    const [sentRows, setSentRows] = useState<number[]>([]);
    const { data, setData, post, processing, errors, transform } = useForm({
        customer_id: '',
        subscription_id: '',
        issue_date: '',
        // The company's payment term after the issue date; it follows the issue date until the person sets it.
        due_date: addDays(todayDay(), dueDays) ?? '',
        notes: '',
        items: [blankRow(0)],
    });
    const [dueTouched, setDueTouched] = useState(false);

    transform((form) => ({
        ...form,
        items: form.items.filter(isStarted).map((row) => ({
            description: row.description.trim(),
            quantity: row.quantity,
            unit_price: row.unit_price,
        })),
    }));

    const rowError = (index: number, field: 'description' | 'quantity' | 'unit_price') =>
        errors[`items.${sentRows.indexOf(index)}.${field}` as const];

    const changeRow = (index: number, patch: Partial<ItemRow>) =>
        setData(
            'items',
            data.items.map((row, at) => (at === index ? { ...row, ...patch } : row)),
        );

    const subtotal = data.items.reduce((sum, row) => sum + lineTotal(row), 0);
    const tax = data.items.reduce(
        (sum, row) => sum + Math.round((lineTotal(row) * taxRate) / 100),
        0,
    );
    const hasItems = data.items.some(isStarted);

    const pickIssueDate = (value: string) => {
        setData((previous) => ({
            ...previous,
            issue_date: value,
            due_date: dueTouched
                ? previous.due_date
                : (addDays(value || todayDay(), dueDays) ?? previous.due_date),
        }));
    };

    const pickDueDate = (value: string) => {
        setDueTouched(true);
        setData('due_date', value);
    };

    // The quick picks count from the issue date, or from today while none is set.
    const quickDue: Array<{ label: string; day: () => string | null }> = [
        { label: '7 hari', day: () => addDays(data.issue_date || todayDay(), 7) },
        { label: '14 hari', day: () => addDays(data.issue_date || todayDay(), 14) },
        { label: 'Akhir bulan', day: () => endOfMonth(data.issue_date || todayDay()) },
    ];

    const pickCustomer = (value: string) => {
        setData((previous) => ({ ...previous, customer_id: value, subscription_id: '' }));
        // The server offers only this customer's subscriptions from now on; a customer with exactly one has it chosen.
        router.get(
            route('admin.invoices.create'),
            { customer_id: value },
            {
                only: ['subscriptionOptions'],
                preserveState: true,
                preserveScroll: true,
                replace: true,
                onSuccess: (page) => {
                    // The partial reload answers with this page's own props: the list the server built for this customer.
                    const answered = page.props as Partial<CreateProps>;
                    if (value && answered.subscriptionOptions?.length === 1) {
                        setData('subscription_id', answered.subscriptionOptions[0].value);
                    }
                },
            },
        );
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        setSentRows(data.items.flatMap((row, index) => (isStarted(row) ? [index] : [])));
        post(route('admin.invoices.store'));
    };

    return (
        <AdminLayout title="Buat tagihan">
            <div className="space-y-6">
                <PageHeader
                    title="Buat tagihan"
                    subtitle="Pilih pelanggan, atur tanggal, isi item, lalu simpan sebagai draf. Pajak mengikuti PPN perusahaan."
                    actions={
                        <Button
                            asChild
                            variant="outline"
                            size="sm"
                            leftIcon={<ArrowLeft className="size-4" />}
                        >
                            <Link href={route('admin.invoices.index')}>Kembali</Link>
                        </Button>
                    }
                />
                <form onSubmit={submit} className="space-y-6">
                    <Card>
                        <CardHeader>
                            <CardTitle>Data tagihan</CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-4 md:grid-cols-2">
                            <RemoteSearchSelect
                                id="customer_id"
                                field="customer"
                                label="Pelanggan"
                                placeholder="Cari nama atau kode pelanggan"
                                options={customerOptions}
                                value={data.customer_id}
                                onChange={pickCustomer}
                                error={errors.customer_id}
                                required
                            />
                            <RemoteSearchSelect
                                id="subscription_id"
                                field="subscription"
                                label="Langganan (opsional)"
                                placeholder="Cari kode langganan"
                                options={subscriptionOptions}
                                value={data.subscription_id}
                                onChange={(value) => setData('subscription_id', value)}
                                error={errors.subscription_id}
                            />
                            <DatePicker
                                id="issue_date"
                                label="Tanggal terbit"
                                hint="Kosongkan untuk memakai hari ini."
                                value={data.issue_date}
                                onChange={pickIssueDate}
                                error={errors.issue_date}
                            />
                            <div className="space-y-2">
                                <DatePicker
                                    id="due_date"
                                    label="Jatuh tempo"
                                    hint={`Otomatis ${dueDays} hari setelah tanggal terbit. Ubah jika perlu.`}
                                    value={data.due_date}
                                    onChange={pickDueDate}
                                    error={errors.due_date}
                                />
                                <div
                                    role="group"
                                    aria-label="Pilihan cepat"
                                    className="flex flex-wrap items-center gap-2"
                                >
                                    <span className="text-sm text-muted-foreground">
                                        Pilihan cepat:
                                    </span>
                                    {quickDue.map(({ label, day }) => (
                                        <Button
                                            key={label}
                                            type="button"
                                            size="sm"
                                            variant="outline"
                                            onClick={() => {
                                                const picked = day();
                                                if (picked) pickDueDate(picked);
                                            }}
                                        >
                                            {label}
                                        </Button>
                                    ))}
                                </div>
                            </div>
                            <Textarea
                                className="md:col-span-2"
                                label="Catatan"
                                value={data.notes}
                                onChange={(e) => setData('notes', e.target.value)}
                                error={errors.notes}
                                rows={3}
                            />
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle>Item</CardTitle>
                            <CardDescription>
                                Setiap baris dikenai PPN {formatDecimal(taxRate, 2)}% sesuai
                                pengaturan perusahaan, dihitung per baris dan dibulatkan ke rupiah.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            {errors.items && (
                                <p role="alert" className="text-sm text-destructive-text">
                                    {errors.items}
                                </p>
                            )}
                            {data.items.map((row, index) => (
                                <div
                                    key={row.key}
                                    role="group"
                                    aria-label={`Item ${index + 1}`}
                                    className="grid gap-3 rounded-lg border p-3 sm:grid-cols-2 lg:grid-cols-[minmax(0,1fr)_7rem_11rem_9rem_auto] lg:items-start"
                                >
                                    <div className="sm:col-span-2 lg:col-span-1">
                                        <Input
                                            label="Deskripsi"
                                            value={row.description}
                                            onChange={(e) =>
                                                changeRow(index, { description: e.target.value })
                                            }
                                            error={rowError(index, 'description')}
                                            required={row.unit_price !== null}
                                            maxLength={500}
                                        />
                                    </div>
                                    <Input
                                        label="Kuantitas"
                                        type="number"
                                        inputMode="decimal"
                                        min="0.01"
                                        step="0.01"
                                        value={row.quantity}
                                        onChange={(e) =>
                                            changeRow(index, { quantity: e.target.value })
                                        }
                                        error={rowError(index, 'quantity')}
                                        required={isStarted(row)}
                                    />
                                    <MoneyInput
                                        label="Harga satuan"
                                        value={row.unit_price}
                                        onChange={(value) =>
                                            changeRow(index, { unit_price: value })
                                        }
                                        error={rowError(index, 'unit_price')}
                                        required={row.description.trim() !== ''}
                                    />
                                    <div className="space-y-1.5">
                                        <p className="text-sm leading-none font-medium">
                                            Total baris
                                        </p>
                                        <p className="flex min-h-11 items-center justify-end text-sm tabular-nums sm:min-h-9">
                                            {formatRupiah(lineTotal(row), company)}
                                        </p>
                                    </div>
                                    <div className="sm:col-span-2 lg:col-span-1 lg:pt-6">
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="destructive-outline"
                                            leftIcon={
                                                <Trash2 className="size-4" aria-hidden="true" />
                                            }
                                            onClick={() =>
                                                setData(
                                                    'items',
                                                    data.items.filter((_, at) => at !== index),
                                                )
                                            }
                                        >
                                            Hapus
                                            <span className="sr-only"> item {index + 1}</span>
                                        </Button>
                                    </div>
                                </div>
                            ))}
                            {!hasItems && (
                                <p className="text-sm text-muted-foreground">
                                    Belum ada item terisi. Tagihan tetap bisa disimpan sebagai draf
                                    kosong, tetapi baru bisa dikirim setelah punya item.
                                </p>
                            )}
                            <div className="flex flex-wrap items-start justify-between gap-4">
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    disabled={data.items.length >= MAX_ITEMS}
                                    leftIcon={<Plus className="size-4" aria-hidden="true" />}
                                    onClick={() =>
                                        setData('items', [
                                            ...data.items,
                                            blankRow(nextKey.current++),
                                        ])
                                    }
                                >
                                    Tambah item
                                </Button>
                                {hasItems && (
                                    <dl className="grid min-w-56 grid-cols-[1fr_auto] gap-x-6 gap-y-1 text-sm tabular-nums">
                                        <dt className="text-muted-foreground">Subtotal</dt>
                                        <dd className="text-right">
                                            {formatRupiah(subtotal, company)}
                                        </dd>
                                        <dt className="text-muted-foreground">
                                            PPN {formatDecimal(taxRate, 2)}%
                                        </dt>
                                        <dd className="text-right">{formatRupiah(tax, company)}</dd>
                                        <dt className="font-semibold">Total</dt>
                                        <dd className="text-right font-semibold">
                                            {formatRupiah(subtotal + tax, company)}
                                        </dd>
                                    </dl>
                                )}
                            </div>
                        </CardContent>
                    </Card>
                    <div className="flex justify-end gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => router.get(route('admin.invoices.index'))}
                        >
                            Batal
                        </Button>
                        <Button type="submit" variant="primary" loading={processing}>
                            Simpan
                        </Button>
                    </div>
                </form>
            </div>
        </AdminLayout>
    );
}
