import type { FormEvent } from 'react';
import { useState } from 'react';
import { router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { PageHeader } from '@/Components/composite';
import { Button, Card, CardContent, Input, Table, TBody, TD, TH, THead, TR } from '@/Components/ui';

interface StockResult {
    location_name: string;
    location_path: string;
    quantity: string;
    reserved: string;
    available: string;
}

interface FindResult {
    id: number;
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

    const submit = (e: FormEvent) => {
        e.preventDefault();
        router.get(route('admin.inventory.find'), { search: query }, { preserveState: true });
    };

    return (
        <AdminLayout title="Item Finder">
            <div className="space-y-6">
                <PageHeader
                    title="Item Finder"
                    subtitle="Search consumable items by name or SKU."
                />
                <Card>
                    <CardContent className="space-y-4 pt-6">
                        <form onSubmit={submit} className="flex gap-2">
                            <Input
                                label="Search"
                                value={query}
                                onChange={(e) => setQuery(e.target.value)}
                                placeholder="Name or SKU"
                            />
                            <div className="self-end">
                                <Button type="submit">Search</Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>
                <Card>
                    <CardContent className="space-y-4 pt-6">
                        <Table>
                            <THead>
                                <TR>
                                    <TH>Product</TH>
                                    <TH>SKU</TH>
                                    <TH>Location</TH>
                                    <TH>Path</TH>
                                    <TH>Available</TH>
                                    <TH>Total</TH>
                                    <TH>Reserved</TH>
                                </TR>
                            </THead>
                            <TBody>
                                {results.length === 0 ? (
                                    <TR>
                                        <TD
                                            colSpan={7}
                                            className="py-10 text-center text-muted-foreground"
                                        >
                                            No data found.
                                        </TD>
                                    </TR>
                                ) : (
                                    results.flatMap((r) =>
                                        r.stocks.length === 0
                                            ? [
                                                  <TR key={`${r.id}-empty`}>
                                                      <TD>{r.name}</TD>
                                                      <TD className="font-mono text-sm">{r.sku}</TD>
                                                      <TD
                                                          colSpan={5}
                                                          className="text-muted-foreground"
                                                      >
                                                          No stock records.
                                                      </TD>
                                                  </TR>,
                                              ]
                                            : r.stocks.map((s, i) => (
                                                  <TR key={`${r.id}-${i}`}>
                                                      <TD>{r.name}</TD>
                                                      <TD className="font-mono text-sm">{r.sku}</TD>
                                                      <TD>{s.location_name}</TD>
                                                      <TD className="text-sm text-muted-foreground">
                                                          {s.location_path}
                                                      </TD>
                                                      <TD className="font-medium">{s.available}</TD>
                                                      <TD>{s.quantity}</TD>
                                                      <TD>{s.reserved}</TD>
                                                  </TR>
                                              )),
                                    )
                                )}
                            </TBody>
                        </Table>
                    </CardContent>
                </Card>
            </div>
        </AdminLayout>
    );
}
