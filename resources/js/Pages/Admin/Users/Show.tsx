import type { ReactNode } from 'react';
import { useMemo, useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import {
    DataTable,
    DataTableActions,
    type DataTableColumn,
    PageHeader,
    RoleBadge,
} from '@/Components/composite';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
    AlertDialogTrigger,
    Button,
    buttonVariants,
    Card,
    CardContent,
    CardHeader,
    CardTitle,
    EnumBadge,
    Tab,
    TabList,
    TabPanel,
    Tabs,
} from '@/Components/ui';
import { useCan } from '@/hooks/useCan';
import { useCompany } from '@/hooks/useCompany';
import { formatDate, formatDateTime, formatMoney } from '@/lib/format';
import {
    ACTIVE_STATE,
    INVOICE_STATUS,
    PRIORITY,
    TICKET_STATUS,
    TWO_FACTOR_STATE,
    WORK_ORDER_STATUS,
    WORK_ORDER_TYPE,
} from '@/lib/statuses';

interface UserData {
    id: string;
    name: string;
    email: string;
    is_active: boolean;
    roles: string[];
    two_factor_enabled?: boolean;
    can?: { delete?: boolean; reset_two_factor?: boolean; assign_roles?: boolean };
    created_at?: string;
    last_login_at?: string | null;
}

interface InvoiceHistory {
    id: string;
    number: string;
    status: string;
    issue_date: string | null;
    due_date: string | null;
    total: string | number;
    paid_amount: string | number;
}

interface TicketHistory {
    id: string;
    code: string;
    title: string;
    priority: string;
    status: string;
    resolved_at: string | null;
    created_at: string | null;
}

interface WorkOrderHistory {
    id: string;
    code: string;
    title: string;
    type: string;
    priority: string;
    status: string;
    scheduled_date: string | null;
    completed_at: string | null;
}

interface ShowProps {
    user: { data: UserData };
    history: {
        linked_customer: { id: string; code: string; name: string } | null;
        invoices: InvoiceHistory[];
        tickets: TicketHistory[];
        work_orders: WorkOrderHistory[];
    };
    historyAccess: {
        billing: boolean;
        tickets: boolean;
        spk: boolean;
    };
}

function DetailRow({ label, children }: { label: string; children: ReactNode }) {
    return (
        <>
            <dt className="text-muted-foreground">{label}</dt>
            <dd className="min-w-0 break-words">{children}</dd>
        </>
    );
}

export default function Show({ user, history, historyAccess }: ShowProps) {
    const can = useCan();
    const company = useCompany();
    const firstHistoryTab = historyAccess.billing
        ? 'billing'
        : historyAccess.tickets
          ? 'tickets'
          : 'spk';
    const [tab, setTab] = useState(firstHistoryTab);

    const invoiceColumns: DataTableColumn<InvoiceHistory>[] = useMemo(
        () => [
            {
                key: 'number',
                header: 'Nomor',
                cell: (invoice) => <span className="font-mono text-sm">{invoice.number}</span>,
            },
            {
                key: 'status',
                header: 'Status',
                cell: (invoice) => <EnumBadge map={INVOICE_STATUS} value={invoice.status} />,
            },
            {
                key: 'issue_date',
                header: 'Tanggal terbit',
                cell: (invoice) => formatDate(invoice.issue_date, company),
            },
            {
                key: 'due_date',
                header: 'Jatuh tempo',
                cell: (invoice) => formatDate(invoice.due_date, company),
            },
            {
                key: 'total',
                header: 'Total',
                cell: (invoice) => formatMoney(invoice.total, company),
            },
            {
                key: 'paid_amount',
                header: 'Dibayar',
                cell: (invoice) => formatMoney(invoice.paid_amount, company),
            },
            {
                key: 'actions',
                header: 'Aksi',
                cell: (invoice) => (
                    <DataTableActions showHref={route('admin.invoices.show', invoice.id)} />
                ),
            },
        ],
        [company],
    );

    const ticketColumns: DataTableColumn<TicketHistory>[] = useMemo(
        () => [
            {
                key: 'code',
                header: 'Kode',
                cell: (ticket) => <span className="font-mono text-sm">{ticket.code}</span>,
            },
            { key: 'title', header: 'Judul' },
            {
                key: 'priority',
                header: 'Prioritas',
                cell: (ticket) => <EnumBadge map={PRIORITY} value={ticket.priority} />,
            },
            {
                key: 'status',
                header: 'Status',
                cell: (ticket) => <EnumBadge map={TICKET_STATUS} value={ticket.status} />,
            },
            {
                key: 'created_at',
                header: 'Dibuat',
                cell: (ticket) => formatDateTime(ticket.created_at, company),
            },
            {
                key: 'resolved_at',
                header: 'Diselesaikan',
                cell: (ticket) => formatDateTime(ticket.resolved_at, company),
            },
            {
                key: 'actions',
                header: 'Aksi',
                cell: (ticket) => (
                    <DataTableActions showHref={route('admin.tickets.show', ticket.id)} />
                ),
            },
        ],
        [company],
    );

    const workOrderColumns: DataTableColumn<WorkOrderHistory>[] = useMemo(
        () => [
            {
                key: 'code',
                header: 'Kode',
                cell: (workOrder) => <span className="font-mono text-sm">{workOrder.code}</span>,
            },
            { key: 'title', header: 'Judul' },
            {
                key: 'type',
                header: 'Jenis',
                cell: (workOrder) => <EnumBadge map={WORK_ORDER_TYPE} value={workOrder.type} />,
            },
            {
                key: 'priority',
                header: 'Prioritas',
                cell: (workOrder) => <EnumBadge map={PRIORITY} value={workOrder.priority} />,
            },
            {
                key: 'status',
                header: 'Status',
                cell: (workOrder) => <EnumBadge map={WORK_ORDER_STATUS} value={workOrder.status} />,
            },
            {
                key: 'scheduled_date',
                header: 'Jadwal',
                cell: (workOrder) => formatDate(workOrder.scheduled_date, company),
            },
            {
                key: 'completed_at',
                header: 'Selesai',
                cell: (workOrder) => formatDateTime(workOrder.completed_at, company),
            },
            {
                key: 'actions',
                header: 'Aksi',
                cell: (workOrder) => (
                    <DataTableActions showHref={route('admin.spk.show', workOrder.id)} />
                ),
            },
        ],
        [company],
    );

    const hasHistoryAccess = historyAccess.billing || historyAccess.tickets || historyAccess.spk;

    return (
        <AdminLayout title={`Pengguna ${user.data.name}`}>
            <div className="space-y-6">
                <PageHeader
                    title={user.data.name}
                    subtitle={user.data.email}
                    actions={
                        <>
                            <Button
                                asChild
                                variant="outline"
                                size="sm"
                                leftIcon={<ArrowLeft className="size-4" />}
                            >
                                <Link href={route('admin.users.index')}>Kembali</Link>
                            </Button>
                            {can('report.audit.view') ? (
                                <Button asChild variant="outline" size="sm">
                                    <Link
                                        href={route('admin.reports.audit-log', {
                                            user_id: user.data.id,
                                        })}
                                    >
                                        Log audit
                                    </Link>
                                </Button>
                            ) : null}
                            <Button asChild variant="primary" size="sm">
                                <Link href={route('admin.users.edit', user.data.id)}>Ubah</Link>
                            </Button>
                        </>
                    }
                />

                <div className="grid gap-6 xl:grid-cols-[2fr_1fr]">
                    <Card>
                        <CardHeader>
                            <CardTitle>Data pengguna</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <dl className="grid grid-cols-[max-content_1fr] items-center gap-x-6 gap-y-3 text-sm">
                                <DetailRow label="Status">
                                    <EnumBadge map={ACTIVE_STATE} value={user.data.is_active} />
                                </DetailRow>
                                <DetailRow label="Dibuat">
                                    {formatDateTime(user.data.created_at, company)}
                                </DetailRow>
                                <DetailRow label="Terakhir masuk">
                                    {formatDateTime(user.data.last_login_at, company)}
                                </DetailRow>
                                {history.linked_customer ? (
                                    <DetailRow label="Pelanggan">
                                        {history.linked_customer.code} -{' '}
                                        {history.linked_customer.name}
                                    </DetailRow>
                                ) : null}
                                <DetailRow label="Peran di perusahaan ini">
                                    <div className="space-y-1">
                                        {user.data.roles.length === 0 ? (
                                            <span className="text-muted-foreground">
                                                Belum ada peran di perusahaan ini
                                            </span>
                                        ) : (
                                            <div className="flex flex-wrap gap-2">
                                                {user.data.roles.map((role) => (
                                                    <RoleBadge key={role} role={role} />
                                                ))}
                                            </div>
                                        )}
                                        {history.linked_customer ? (
                                            <p className="text-xs text-muted-foreground">
                                                Peran pelanggan dikelola dari data pelanggan.
                                            </p>
                                        ) : null}
                                    </div>
                                </DetailRow>
                            </dl>
                        </CardContent>
                    </Card>
                    <Card role="region" aria-labelledby="user-two-factor">
                        <CardHeader>
                            <CardTitle id="user-two-factor">Autentikasi dua langkah</CardTitle>
                        </CardHeader>
                        <CardContent className="flex flex-wrap items-center gap-3">
                            <EnumBadge
                                map={TWO_FACTOR_STATE}
                                value={user.data.two_factor_enabled ? 'enabled' : 'off'}
                            />
                            {user.data.can?.reset_two_factor ? (
                                <AlertDialog>
                                    <AlertDialogTrigger asChild>
                                        <Button type="button" variant="outline" size="sm">
                                            Atur ulang 2FA
                                        </Button>
                                    </AlertDialogTrigger>
                                    <AlertDialogContent>
                                        <AlertDialogHeader>
                                            <AlertDialogTitle>
                                                Atur ulang autentikasi dua langkah untuk{' '}
                                                {user.data.name}?
                                            </AlertDialogTitle>
                                            <AlertDialogDescription>
                                                Pengguna bisa masuk hanya dengan kata sandi sampai
                                                ia mengaturnya lagi, dan kode pemulihannya tidak
                                                berlaku. Lakukan ini hanya setelah memastikan bahwa
                                                yang meminta memang orangnya.
                                            </AlertDialogDescription>
                                        </AlertDialogHeader>
                                        <AlertDialogFooter>
                                            <AlertDialogCancel>Batal</AlertDialogCancel>
                                            <AlertDialogAction
                                                className={buttonVariants({
                                                    variant: 'destructive',
                                                })}
                                                onClick={() =>
                                                    router.post(
                                                        route(
                                                            'admin.users.two-factor.reset',
                                                            user.data.id,
                                                        ),
                                                        {},
                                                        { preserveScroll: true },
                                                    )
                                                }
                                            >
                                                Atur ulang
                                            </AlertDialogAction>
                                        </AlertDialogFooter>
                                    </AlertDialogContent>
                                </AlertDialog>
                            ) : null}
                        </CardContent>
                    </Card>
                </div>

                {hasHistoryAccess ? (
                    <Card>
                        <CardContent className="pt-6">
                            <Tabs value={tab} onValueChange={setTab}>
                                <TabList className="overflow-x-auto" aria-label="Riwayat pengguna">
                                    {historyAccess.billing ? (
                                        <Tab value="billing">
                                            Riwayat tagihan ({history.invoices.length})
                                        </Tab>
                                    ) : null}
                                    {historyAccess.tickets ? (
                                        <Tab value="tickets">
                                            Riwayat tiket ({history.tickets.length})
                                        </Tab>
                                    ) : null}
                                    {historyAccess.spk ? (
                                        <Tab value="spk">
                                            Riwayat SPK ({history.work_orders.length})
                                        </Tab>
                                    ) : null}
                                </TabList>
                                {historyAccess.billing ? (
                                    <TabPanel value="billing">
                                        <DataTable
                                            columns={invoiceColumns}
                                            rows={history.invoices}
                                            emptyTitle="Belum ada riwayat tagihan"
                                            emptyDescription="Tagihan yang dibuat pengguna ini, atau milik pelanggan yang terhubung dengannya, muncul di sini."
                                        />
                                    </TabPanel>
                                ) : null}
                                {historyAccess.tickets ? (
                                    <TabPanel value="tickets">
                                        <DataTable
                                            columns={ticketColumns}
                                            rows={history.tickets}
                                            emptyTitle="Belum ada riwayat tiket"
                                            emptyDescription="Tiket yang dibuat atau ditangani pengguna ini, atau milik pelanggan yang terhubung dengannya, muncul di sini."
                                        />
                                    </TabPanel>
                                ) : null}
                                {historyAccess.spk ? (
                                    <TabPanel value="spk">
                                        <DataTable
                                            columns={workOrderColumns}
                                            rows={history.work_orders}
                                            emptyTitle="Belum ada riwayat SPK"
                                            emptyDescription="SPK yang dibuat atau ditangani pengguna ini, atau milik pelanggan yang terhubung dengannya, muncul di sini."
                                        />
                                    </TabPanel>
                                ) : null}
                            </Tabs>
                        </CardContent>
                    </Card>
                ) : null}
            </div>
        </AdminLayout>
    );
}
