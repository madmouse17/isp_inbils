import type { FormEvent } from 'react';
import { useState } from 'react';
import { router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { LoadingState, PageHeader } from '@/Components/composite';
import { Button, Card, CardContent, Input, Table, TBody, TD, TH, THead, TR } from '@/Components/ui';
import { useVisitPending } from '@/hooks/useVisitPending';
import { formatNumber } from '@/lib/format';

interface StockResult {
    location_name: string;
    location_path: string;
    quantity: string;
    reserved: string;
    available: string;
}

interface FindResult {
    id: string;
    sku: string;
    name: string;
    stocks: StockResult[];
}

interface FindProps extends Record<string, unknown> {
    results: FindResult[];
    search: string;
}

export default function Find({ results, search }: FindProps) {
    const [query, setQuery] = useState(search);
    const { pending, callbacks } = useVisitPending();

    const submit = (e: FormEvent) => {
        e.preventDefault();
        router.get(
            route('admin.inventory.find'),
            { search: query },
            { preserveState: true, ...callbacks },
        );
    };

    return (
        <AdminLayout title="Cari Barang">
            <div className="space-y-6">
                <PageHeader
                    title="Cari Barang"
                    subtitle="Cari barang habis pakai berdasarkan nama atau SKU, lalu lihat jumlahnya di setiap lokasi."
                />
                <Card>
                    <CardContent className="space-y-4 pt-6">
                        <form onSubmit={submit} className="flex gap-2">
                            <Input
                                label="Nama atau SKU"
                                value={query}
                                onChange={(e) => setQuery(e.target.value)}
                                placeholder="Contoh: kabel"
                            />
                            <div className="self-end">
                                <Button type="submit" loading={pending}>
                                    Cari
                                </Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>
                <Card>
                    <CardContent className="space-y-4 pt-6">
                        {pending ? (
                            <LoadingState variant="rows" />
                        ) : (
                            <Table>
                                <THead>
                                    <TR>
                                        <TH>Barang</TH>
                                        <TH>SKU</TH>
                                        <TH>Lokasi</TH>
                                        <TH>Jalur</TH>
                                        <TH>Tersedia</TH>
                                        <TH>Jumlah</TH>
                                        <TH>Dicadangkan</TH>
                                    </TR>
                                </THead>
                                <TBody>
                                    {results.length === 0 ? (
                                        <TR>
                                            <TD
                                                colSpan={7}
                                                className="py-10 text-center text-muted-foreground"
                                            >
                                                {search
                                                    ? `Tidak ada barang habis pakai yang cocok dengan "${search}". Coba potongan nama atau SKU yang lain.`
                                                    : 'Ketik nama atau SKU di kolom atas, lalu klik Cari.'}
                                            </TD>
                                        </TR>
                                    ) : (
                                        results.flatMap((r) =>
                                            r.stocks.length === 0
                                                ? [
                                                      <TR key={`${r.id}-empty`}>
                                                          <TD>{r.name}</TD>
                                                          <TD className="font-mono text-sm">
                                                              {r.sku}
                                                          </TD>
                                                          <TD
                                                              colSpan={5}
                                                              className="text-muted-foreground"
                                                          >
                                                              Belum ada stok di lokasi mana pun.
                                                          </TD>
                                                      </TR>,
                                                  ]
                                                : r.stocks.map((s, i) => (
                                                      <TR key={`${r.id}-${i}`}>
                                                          <TD>{r.name}</TD>
                                                          <TD className="font-mono text-sm">
                                                              {r.sku}
                                                          </TD>
                                                          <TD>{s.location_name}</TD>
                                                          <TD className="text-sm text-muted-foreground">
                                                              {s.location_path}
                                                          </TD>
                                                          <TD className="font-medium tabular-nums">
                                                              {formatNumber(s.available)}
                                                          </TD>
                                                          <TD className="tabular-nums">
                                                              {formatNumber(s.quantity)}
                                                          </TD>
                                                          <TD className="tabular-nums">
                                                              {formatNumber(s.reserved)}
                                                          </TD>
                                                      </TR>
                                                  )),
                                        )
                                    )}
                                </TBody>
                            </Table>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AdminLayout>
    );
}
