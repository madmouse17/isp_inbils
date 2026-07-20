import type { FormEvent } from 'react';
import { useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { PageHeader } from '@/Components/composite';
import {
    Button,
    Card,
    CardContent,
    Input,
    Select,
    Table,
    TBody,
    TD,
    TH,
    THead,
    TR,
    Modal,
} from '@/Components/ui';

interface StockRow {
    id: number;
    quantity: string;
    reserved_quantity: string;
    available: string;
    product?: { sku: string; name: string } | null;
    location?: { name: string; path?: string } | null;
}

interface OptRow {
    id: number;
    name: string;
}
interface LocRow {
    id: number;
    name: string;
    code: string;
}

interface IndexProps extends Record<string, unknown> {
    stocks: { data: StockRow[]; current_page: number; last_page: number };
    products: { data: OptRow[] };
    locations: { data: LocRow[] };
    filters: { location_id?: string; product_id?: string; low_stock?: string };
}

export default function Index({ stocks, products, locations, filters }: IndexProps) {
    const [actionModal, setActionModal] = useState<
        'receive' | 'issue' | 'transfer' | 'adjust' | null
    >(null);
    const [productId, setProductId] = useState(filters.product_id ?? '');
    const [locationId, setLocationId] = useState(filters.location_id ?? '');
    const { data, setData, post, processing, errors } = useForm({
        product_id: '',
        location_id: '',
        quantity: '',
        from_location_id: '',
        to_location_id: '',
        new_quantity: '',
        note: '',
    });

    const openAction = (
        action: 'receive' | 'issue' | 'transfer' | 'adjust',
        pId?: string,
        lId?: string,
    ) => {
        setData('product_id', pId ?? '');
        setData('location_id', lId ?? '');
        setActionModal(action);
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (!actionModal) return;
        const routeName = `admin.stocks.${actionModal}`;
        post(route(routeName), { onSuccess: () => setActionModal(null) });
    };

    const submitFilter = (e: FormEvent) => {
        e.preventDefault();
        router.get(
            route('admin.stocks.index'),
            { product_id: productId, location_id: locationId },
            { preserveState: true },
        );
    };

    return (
        <AdminLayout title="Stocks">
            <div className="space-y-6">
                <PageHeader
                    title="Stocks"
                    subtitle="Stock per location."
                    actions={
                        <>
                            <Button
                                type="button"
                                variant="secondary"
                                onClick={() => openAction('receive')}
                            >
                                Receive
                            </Button>
                            <Button
                                type="button"
                                variant="secondary"
                                onClick={() => openAction('issue')}
                            >
                                Issue
                            </Button>
                            <Button
                                type="button"
                                variant="secondary"
                                onClick={() => openAction('transfer')}
                            >
                                Transfer
                            </Button>
                            <Button
                                type="button"
                                variant="secondary"
                                onClick={() => openAction('adjust')}
                            >
                                Adjust
                            </Button>
                        </>
                    }
                />
                <Card>
                    <CardContent className="space-y-4 pt-6">
                        <form onSubmit={submitFilter} className="flex flex-wrap gap-2">
                            <Select
                                label="Product"
                                value={productId}
                                onChange={(e) => setProductId(e.target.value)}
                            >
                                <option value="">All</option>
                                {products.data.map((p) => (
                                    <option key={p.id} value={p.id}>
                                        {p.name}
                                    </option>
                                ))}
                            </Select>
                            <Select
                                label="Location"
                                value={locationId}
                                onChange={(e) => setLocationId(e.target.value)}
                            >
                                <option value="">All</option>
                                {locations.data.map((l) => (
                                    <option key={l.id} value={l.id}>
                                        {l.code} — {l.name}
                                    </option>
                                ))}
                            </Select>
                            <div className="self-end">
                                <Button type="submit" variant="secondary">
                                    Filter
                                </Button>
                            </div>
                        </form>
                        <Table>
                            <THead>
                                <TR>
                                    <TH>Product</TH>
                                    <TH>Location</TH>
                                    <TH>Path</TH>
                                    <TH>Quantity</TH>
                                    <TH>Reserved</TH>
                                    <TH>Available</TH>
                                </TR>
                            </THead>
                            <TBody>
                                {stocks.data.length === 0 ? (
                                    <TR>
                                        <TD
                                            colSpan={6}
                                            className="py-10 text-center text-muted-foreground"
                                        >
                                            No data found.
                                        </TD>
                                    </TR>
                                ) : (
                                    stocks.data.map((s) => (
                                        <TR key={s.id}>
                                            <TD>{s.product?.name ?? '-'}</TD>
                                            <TD>{s.location?.name ?? '-'}</TD>
                                            <TD className="text-sm text-muted-foreground">
                                                {s.location?.path ?? '-'}
                                            </TD>
                                            <TD>{s.quantity}</TD>
                                            <TD>{s.reserved_quantity}</TD>
                                            <TD className="font-medium">{s.available}</TD>
                                        </TR>
                                    ))
                                )}
                            </TBody>
                        </Table>
                    </CardContent>
                </Card>
                <Modal
                    open={actionModal !== null}
                    onClose={() => setActionModal(null)}
                    title={
                        actionModal
                            ? actionModal.charAt(0).toUpperCase() + actionModal.slice(1) + ' Stock'
                            : ''
                    }
                >
                    <form onSubmit={submit} className="space-y-4">
                        <Select
                            label="Product"
                            value={data.product_id}
                            onChange={(e) => setData('product_id', e.target.value)}
                            error={errors.product_id}
                            required
                        >
                            <option value="">Select...</option>
                            {products.data.map((p) => (
                                <option key={p.id} value={p.id}>
                                    {p.name}
                                </option>
                            ))}
                        </Select>
                        {actionModal === 'transfer' ? (
                            <div className="grid gap-4 md:grid-cols-2">
                                <Select
                                    label="From Location"
                                    value={data.from_location_id}
                                    onChange={(e) => setData('from_location_id', e.target.value)}
                                    error={errors.from_location_id}
                                    required
                                >
                                    <option value="">Select...</option>
                                    {locations.data.map((l) => (
                                        <option key={l.id} value={l.id}>
                                            {l.code} — {l.name}
                                        </option>
                                    ))}
                                </Select>
                                <Select
                                    label="To Location"
                                    value={data.to_location_id}
                                    onChange={(e) => setData('to_location_id', e.target.value)}
                                    error={errors.to_location_id}
                                    required
                                >
                                    <option value="">Select...</option>
                                    {locations.data.map((l) => (
                                        <option key={l.id} value={l.id}>
                                            {l.code} — {l.name}
                                        </option>
                                    ))}
                                </Select>
                            </div>
                        ) : (
                            <Select
                                label="Location"
                                value={data.location_id}
                                onChange={(e) => setData('location_id', e.target.value)}
                                error={errors.location_id}
                                required
                            >
                                <option value="">Select...</option>
                                {locations.data.map((l) => (
                                    <option key={l.id} value={l.id}>
                                        {l.code} — {l.name}
                                    </option>
                                ))}
                            </Select>
                        )}
                        {actionModal === 'adjust' ? (
                            <Input
                                label="New Quantity"
                                type="number"
                                step="0.01"
                                value={data.new_quantity}
                                onChange={(e) => setData('new_quantity', e.target.value)}
                                error={errors.new_quantity}
                                required
                            />
                        ) : (
                            <Input
                                label="Quantity"
                                type="number"
                                step="0.01"
                                value={data.quantity}
                                onChange={(e) => setData('quantity', e.target.value)}
                                error={errors.quantity}
                                required
                            />
                        )}
                        <Input
                            label="Note"
                            value={data.note}
                            onChange={(e) => setData('note', e.target.value)}
                            error={errors.note}
                        />
                        <div className="flex justify-end gap-2">
                            <Button
                                type="button"
                                variant="secondary"
                                onClick={() => setActionModal(null)}
                            >
                                Cancel
                            </Button>
                            <Button type="submit" loading={processing}>
                                {actionModal === 'adjust'
                                    ? 'Adjust'
                                    : actionModal === 'transfer'
                                      ? 'Transfer'
                                      : 'Submit'}
                            </Button>
                        </div>
                    </form>
                </Modal>
            </div>
        </AdminLayout>
    );
}
