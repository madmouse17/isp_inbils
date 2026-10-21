import type { FormEvent } from 'react';
import { useEffect } from 'react';
import { Link, useForm, usePage } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import {
    Button,
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
    DatePicker,
    Table,
    TBody,
    TD,
    TH,
    THead,
    TR,
    Textarea,
} from '@/Components/ui';
import { ConflictBanner, MoneyInput, PageHeader } from '@/Components/composite';
import { useCompany } from '@/hooks/useCompany';
import { formatDate, formatDecimal, formatRupiah } from '@/lib/format';
import type { PageProps } from '@/types';
import type { Invoice } from '@/types/billing';

interface EditProps extends Record<string, unknown> {
    invoice: { data: Invoice };
    /** `OptimisticLock::version`: sent back, so a save over a newer one is refused. */
    version: string;
}

/** The draft page: what a draft still lets staff change without touching its items (F7-D26). */
export default function Edit({ invoice, version }: EditProps) {
    const inv = invoice.data;
    const company = useCompany();
    const items = inv.items ?? [];
    // The amount can be empty while the person types a new one; it is sent as zero then.
    const { data, setData, put, processing, errors, transform } = useForm<{
        due_date: string;
        discount_amount: number | null;
        notes: string;
        expected_updated_at: string;
    }>({
        due_date: inv.due_date.slice(0, 10),
        discount_amount: Number(inv.discount_amount),
        notes: inv.notes ?? '',
        expected_updated_at: version,
    });
    const conflict = usePage<PageProps>().props.errors.conflict;

    // A cleared amount is no discount: the column holds zero, never null.
    transform((form) => ({ ...form, discount_amount: form.discount_amount ?? 0 }));

    // A conflict reply carries the newer row: keeping the old version makes the retry conflict again until the page is reloaded.
    useEffect(() => {
        if (!conflict) setData('expected_updated_at', version);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [version]);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        put(route('admin.invoices.update', inv.id));
    };

    return (
        <AdminLayout title={`Ubah tagihan ${inv.number}`}>
            <div className="space-y-6">
                <PageHeader
                    title={`Ubah tagihan ${inv.number}`}
                    subtitle="Ubah jatuh tempo, diskon, dan catatan draf ini. Item diubah dari halaman tagihan."
                    actions={
                        <Button
                            asChild
                            variant="outline"
                            size="sm"
                            leftIcon={<ArrowLeft className="size-4" />}
                        >
                            <Link href={route('admin.invoices.show', inv.id)}>Kembali</Link>
                        </Button>
                    }
                />
                <ConflictBanner />
                <Card>
                    <CardHeader>
                        <CardTitle>Draf saat ini</CardTitle>
                        <CardDescription>
                            Pelanggan, langganan, tanggal terbit, dan item tidak bisa diubah di
                            sini.{' '}
                            <Link
                                href={route('admin.invoices.show', inv.id)}
                                className="text-primary-text underline-offset-2 hover:underline"
                            >
                                Kelola item di halaman tagihan
                            </Link>
                            .
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <dl className="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                            <div>
                                <dt className="text-muted-foreground">Pelanggan</dt>
                                <dd>{inv.customer?.name ?? '-'}</dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">Langganan</dt>
                                <dd>{inv.subscription?.code ?? '-'}</dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">Tanggal terbit</dt>
                                <dd>{formatDate(inv.issue_date, company)}</dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">Total saat ini</dt>
                                <dd className="tabular-nums">{formatRupiah(inv.total, company)}</dd>
                            </div>
                        </dl>
                        <Table>
                            <THead>
                                <TR>
                                    <TH>Deskripsi</TH>
                                    <TH>Jml</TH>
                                    <TH className="text-right">Total baris</TH>
                                </TR>
                            </THead>
                            <TBody>
                                {items.length === 0 ? (
                                    <TR>
                                        <TD
                                            colSpan={3}
                                            className="text-center text-muted-foreground"
                                        >
                                            Belum ada item. Tambahkan dari halaman tagihan sebelum
                                            mengirim.
                                        </TD>
                                    </TR>
                                ) : (
                                    items.map((item) => (
                                        <TR key={item.id}>
                                            <TD>{item.description}</TD>
                                            <TD>{formatDecimal(item.quantity, 2)}</TD>
                                            <TD className="text-right tabular-nums">
                                                {formatRupiah(item.line_total, company)}
                                            </TD>
                                        </TR>
                                    ))
                                )}
                            </TBody>
                        </Table>
                    </CardContent>
                </Card>
                <form onSubmit={submit} className="space-y-6">
                    <Card>
                        <CardHeader>
                            <CardTitle>Yang bisa diubah</CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-4 md:grid-cols-2">
                            <DatePicker
                                id="due_date"
                                label="Jatuh tempo"
                                hint="Tidak boleh sebelum tanggal terbit."
                                value={data.due_date}
                                onChange={(value) => setData('due_date', value)}
                                error={errors.due_date}
                            />
                            <MoneyInput
                                id="discount_amount"
                                label="Diskon"
                                hint="Dikurangkan dari subtotal ditambah pajak. Total dihitung ulang saat disimpan."
                                value={data.discount_amount}
                                onChange={(value) => setData('discount_amount', value)}
                                error={errors.discount_amount}
                            />
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
                    <div className="flex justify-end gap-2">
                        <Button asChild variant="outline">
                            <Link href={route('admin.invoices.show', inv.id)}>Batal</Link>
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
