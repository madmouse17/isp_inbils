import { Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import { PageHeader } from '@/Components/composite';
import {
    Badge,
    Button,
    Card,
    CardContent,
    CardHeader,
    CardTitle,
    EmptyCell,
    EnumBadge,
    Table,
    TBody,
    TD,
    TH,
    THead,
    TR,
} from '@/Components/ui';
import { useCompany } from '@/hooks/useCompany';
import { formatDateTime, formatMoney, formatNumber } from '@/lib/format';
import { ACTIVE_STATE, STOCK_MOVEMENT_TYPE } from '@/lib/statuses';
import type { Product } from '@/types/inventory';

interface ShowProps extends Record<string, unknown> {
    product: { data: Product };
}

export default function Show({ product }: ShowProps) {
    const company = useCompany();
    const p = product.data;
    const stocks = p.stocks ?? [];
    const movements = p.movements ?? [];

    return (
        <AdminLayout title={`Barang ${p.sku}`}>
            <div className="space-y-6">
                <PageHeader
                    title={p.name}
                    subtitle={p.sku}
                    actions={
                        <>
                            <EnumBadge map={ACTIVE_STATE} value={p.is_active} />
                            <Button
                                asChild
                                variant="outline"
                                size="sm"
                                leftIcon={<ArrowLeft className="size-4" />}
                            >
                                <Link href={route('admin.products.index')}>Kembali</Link>
                            </Button>
                            <Button asChild variant="secondary">
                                <Link href={route('admin.products.edit', p.id)}>Ubah</Link>
                            </Button>
                        </>
                    }
                />

                <Card>
                    <CardHeader>
                        <CardTitle>Rincian Barang</CardTitle>
                    </CardHeader>
                    <CardContent className="grid gap-4 text-sm md:grid-cols-2">
                        <p>
                            <span className="text-muted-foreground">Kategori: </span>
                            {p.category?.name ?? <EmptyCell />}
                        </p>
                        <p>
                            <span className="text-muted-foreground">Satuan: </span>
                            {p.unit ? `${p.unit.name} (${p.unit.symbol})` : <EmptyCell />}
                        </p>
                        <p>
                            <span className="text-muted-foreground">Harga Jual: </span>
                            {p.sell_price === null ? (
                                <EmptyCell />
                            ) : (
                                formatMoney(p.sell_price, company)
                            )}
                        </p>
                        <p>
                            <span className="text-muted-foreground">Harga Pokok: </span>
                            {p.cost_price === null ? (
                                <EmptyCell />
                            ) : (
                                formatMoney(p.cost_price, company)
                            )}
                        </p>
                        <p>
                            <span className="text-muted-foreground">Stok Minimum: </span>
                            {formatNumber(p.min_stock)}
                        </p>
                        <p>
                            <span className="text-muted-foreground">Lacak Stok: </span>
                            {p.track_stock ? <Badge variant="brand">Ya</Badge> : <EmptyCell />}
                        </p>
                        {p.description && (
                            <p className="md:col-span-2">
                                <span className="text-muted-foreground">Deskripsi: </span>
                                {p.description}
                            </p>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Stok per Lokasi</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <Table>
                            <THead>
                                <TR>
                                    <TH>Lokasi</TH>
                                    <TH>Jalur</TH>
                                    <TH>Jumlah</TH>
                                    <TH>Dicadangkan</TH>
                                    <TH>Tersedia</TH>
                                </TR>
                            </THead>
                            <TBody>
                                {stocks.length === 0 ? (
                                    <TR>
                                        <TD
                                            colSpan={5}
                                            className="py-10 text-center text-muted-foreground"
                                        >
                                            Belum ada stok barang ini di lokasi mana pun. Catat
                                            penerimaannya di halaman Stok.
                                        </TD>
                                    </TR>
                                ) : (
                                    stocks.map((s) => (
                                        <TR key={s.id}>
                                            <TD>{s.location?.name ?? <EmptyCell />}</TD>
                                            <TD className="text-sm text-muted-foreground">
                                                {s.location?.path ?? <EmptyCell />}
                                            </TD>
                                            <TD className="tabular-nums">
                                                {formatNumber(s.quantity)}
                                            </TD>
                                            <TD className="tabular-nums">
                                                {formatNumber(s.reserved_quantity)}
                                            </TD>
                                            <TD className="tabular-nums">
                                                {formatNumber(s.available)}
                                            </TD>
                                        </TR>
                                    ))
                                )}
                            </TBody>
                        </Table>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Mutasi Terbaru</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <Table>
                            <THead>
                                <TR>
                                    <TH>Jenis</TH>
                                    <TH>Jumlah</TH>
                                    <TH>Saldo</TH>
                                    <TH>Catatan</TH>
                                    <TH>Waktu</TH>
                                </TR>
                            </THead>
                            <TBody>
                                {movements.length === 0 ? (
                                    <TR>
                                        <TD
                                            colSpan={5}
                                            className="py-10 text-center text-muted-foreground"
                                        >
                                            Belum ada mutasi stok untuk barang ini.
                                        </TD>
                                    </TR>
                                ) : (
                                    movements.map((m) => (
                                        <TR key={m.id}>
                                            <TD>
                                                <EnumBadge
                                                    map={STOCK_MOVEMENT_TYPE}
                                                    value={m.movement_type}
                                                />
                                            </TD>
                                            <TD className="tabular-nums">
                                                {formatNumber(m.quantity)}
                                            </TD>
                                            <TD className="tabular-nums">
                                                {formatNumber(m.balance_after)}
                                            </TD>
                                            <TD>{m.note || <EmptyCell />}</TD>
                                            <TD className="text-sm text-muted-foreground">
                                                {formatDateTime(m.created_at, company)}
                                            </TD>
                                        </TR>
                                    ))
                                )}
                            </TBody>
                        </Table>
                        <div className="mt-3 flex justify-end">
                            <Link
                                href={route('admin.stock-movements.index', { product_id: p.id })}
                                className="text-sm font-medium text-primary-text hover:underline"
                            >
                                Lihat semua mutasi
                            </Link>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </AdminLayout>
    );
}
