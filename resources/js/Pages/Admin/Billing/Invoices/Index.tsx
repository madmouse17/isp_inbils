import type { FormEvent } from 'react';
import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import {
    Badge,
    Button,
    Card,
    CardContent,
    Input,
    Select,
    Pagination,
    Table,
    TBody,
    TD,
    TH,
    THead,
    TR,
} from '@/Components/ui';
import { PageHeader, StatusBadge } from '@/Components/composite';
import GenerateDialog from './GenerateDialog';

interface InvRow {
    id: number;
    number: string;
    type: string;
    source: string;
    status: string;
    total: string;
    paid_amount: string;
    issue_date: string;
    customer?: { name: string } | null;
}

interface CustRow {
    id: number;
    name: string;
}

interface IndexProps extends Record<string, unknown> {
    invoices: { data: InvRow[]; current_page: number; last_page: number };
    customers: { data: CustRow[] };
    filters: {
        type?: string;
        status?: string;
        source?: string;
        customer_id?: string;
        search?: string;
    };
    can: { create: boolean };
}

const statusVariant = (s: string): 'success' | 'warning' | 'danger' | 'muted' | 'info' =>
    s === 'paid'
        ? 'success'
        : s === 'partial'
          ? 'info'
          : s === 'overdue'
            ? 'danger'
            : s === 'cancelled'
              ? 'muted'
              : 'warning';

export default function Index({ invoices, customers, filters, can }: IndexProps) {
    const [search, setSearch] = useState(filters.search ?? '');
    const [type, setType] = useState(filters.type ?? '');
    const [status, setStatus] = useState(filters.status ?? '');
    const [customerId, setCustomerId] = useState(filters.customer_id ?? '');
    const [showGenerate, setShowGenerate] = useState(false);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        router.get(
            route('admin.invoices.index'),
            { search, type, status, customer_id: customerId },
            { preserveState: true },
        );
    };

    return (
        <AdminLayout title="Invoices">
            <div className="space-y-6">
                <PageHeader
                    title="Invoices"
                    subtitle="Manage billing invoices."
                    actions={
                        can.create && (
                            <>
                                <Button
                                    type="button"
                                    variant="secondary"
                                    onClick={() => setShowGenerate(true)}
                                >
                                    Generate Tagihan
                                </Button>
                                <Button
                                    type="button"
                                    onClick={() => router.get(route('admin.invoices.create'))}
                                >
                                    Create Invoice
                                </Button>
                            </>
                        )
                    }
                />
                <Card>
                    <CardContent className="space-y-4 pt-6">
                        <form onSubmit={submit} className="flex flex-wrap gap-2">
                            <Input
                                label="Search"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder="Invoice number"
                            />
                            <Select
                                label="Type"
                                value={type}
                                onChange={(e) => setType(e.target.value)}
                            >
                                <option value="">All</option>
                                <option value="one_time">One Time</option>
                                <option value="recurring">Recurring</option>
                            </Select>
                            <Select
                                label="Status"
                                value={status}
                                onChange={(e) => setStatus(e.target.value)}
                            >
                                <option value="">All</option>
                                <option value="draft">Draft</option>
                                <option value="sent">Sent</option>
                                <option value="partial">Partial</option>
                                <option value="paid">Paid</option>
                                <option value="overdue">Overdue</option>
                                <option value="cancelled">Cancelled</option>
                            </Select>
                            <Select
                                label="Customer"
                                value={customerId}
                                onChange={(e) => setCustomerId(e.target.value)}
                            >
                                <option value="">All</option>
                                {customers.data.map((c) => (
                                    <option key={c.id} value={c.id}>
                                        {c.name}
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
                                    <TH>Number</TH>
                                    <TH>Customer</TH>
                                    <TH>Type</TH>
                                    <TH>Status</TH>
                                    <TH>Total</TH>
                                    <TH>Paid</TH>
                                    <TH>Issue Date</TH>
                                    <TH>Actions</TH>
                                </TR>
                            </THead>
                            <TBody>
                                {invoices.data.length === 0 ? (
                                    <TR>
                                        <TD
                                            colSpan={8}
                                            className="py-10 text-center text-muted-foreground"
                                        >
                                            No data found.
                                        </TD>
                                    </TR>
                                ) : (
                                    invoices.data.map((inv) => (
                                        <TR key={inv.id}>
                                            <TD className="font-mono text-sm">{inv.number}</TD>
                                            <TD>{inv.customer?.name ?? '-'}</TD>
                                            <TD>
                                                <Badge variant="neutral">{inv.type}</Badge>
                                            </TD>
                                            <TD>
                                                <StatusBadge variant={statusVariant(inv.status)}>
                                                    {inv.status}
                                                </StatusBadge>
                                            </TD>
                                            <TD className="font-medium">{inv.total}</TD>
                                            <TD>{inv.paid_amount}</TD>
                                            <TD className="text-sm">{inv.issue_date}</TD>
                                            <TD>
                                                <Link
                                                    href={route('admin.invoices.show', inv.id)}
                                                    className="text-sm font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400"
                                                >
                                                    Show
                                                </Link>
                                            </TD>
                                        </TR>
                                    ))
                                )}
                            </TBody>
                        </Table>
                        <Pagination
                            currentPage={invoices.current_page}
                            lastPage={invoices.last_page}
                            onPageChange={(page) =>
                                router.get(route('admin.invoices.index'), {
                                    page,
                                    search,
                                    type,
                                    status,
                                    customer_id: customerId,
                                })
                            }
                        />
                    </CardContent>
                </Card>
                <GenerateDialog open={showGenerate} onClose={() => setShowGenerate(false)} />
            </div>
        </AdminLayout>
    );
}
