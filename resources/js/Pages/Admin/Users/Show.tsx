import { useMemo, useState } from 'react';
import { router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import {
    DataTable,
    DataTableActions,
    type DataTableColumn,
    PageHeader,
    RoleBadge,
    StatusBadge,
} from '@/Components/composite';
import {
    Badge,
    Button,
    Card,
    CardContent,
    CardHeader,
    CardTitle,
    Tab,
    TabList,
    TabPanel,
    Tabs,
} from '@/Components/ui';
import { formatDate, formatDateTime, formatMoney } from '@/lib/format';

interface ActivityItem {
    id: number;
    description: string;
    created_at: string;
}

interface UserData {
    id: number;
    name: string;
    email: string;
    company_id: number | null;
    is_active: boolean;
    roles: string[];
    created_at?: string;
    last_login_at?: string | null;
    activity_log?: ActivityItem[];
}

interface InvoiceHistory {
    id: number;
    number: string;
    status: string;
    issue_date: string | null;
    due_date: string | null;
    total: string | number;
    paid_amount: string | number;
}

interface TicketHistory {
    id: number;
    code: string;
    title: string;
    priority: string;
    status: string;
    resolved_at: string | null;
    created_at: string | null;
}

interface WorkOrderHistory {
    id: number;
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
        linked_customer: { id: number; code: string; name: string } | null;
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

export default function Show({ user, history, historyAccess }: ShowProps) {
    const firstHistoryTab = historyAccess.billing
        ? 'billing'
        : historyAccess.tickets
          ? 'tickets'
          : 'spk';
    const [tab, setTab] = useState(firstHistoryTab);

    const invoiceColumns: DataTableColumn<InvoiceHistory>[] = useMemo(
        () => [
            { key: 'number', header: 'Number' },
            {
                key: 'status',
                header: 'Status',
                cell: (invoice) => (
                    <StatusBadge variant={statusVariant(invoice.status)}>
                        {invoice.status}
                    </StatusBadge>
                ),
            },
            {
                key: 'issue_date',
                header: 'Issue Date',
                cell: (invoice) => formatDate(invoice.issue_date),
            },
            {
                key: 'due_date',
                header: 'Due Date',
                cell: (invoice) => formatDate(invoice.due_date),
            },
            {
                key: 'total',
                header: 'Total',
                cell: (invoice) => formatMoney(invoice.total),
            },
            {
                key: 'paid_amount',
                header: 'Paid',
                cell: (invoice) => formatMoney(invoice.paid_amount),
            },
            {
                key: 'actions',
                header: 'Actions',
                cell: (invoice) => (
                    <DataTableActions showHref={route('admin.invoices.show', invoice.id)} />
                ),
            },
        ],
        [],
    );

    const ticketColumns: DataTableColumn<TicketHistory>[] = useMemo(
        () => [
            { key: 'code', header: 'Code' },
            { key: 'title', header: 'Title' },
            {
                key: 'priority',
                header: 'Priority',
                cell: (ticket) => (
                    <StatusBadge variant={priorityVariant(ticket.priority)}>
                        {ticket.priority}
                    </StatusBadge>
                ),
            },
            {
                key: 'status',
                header: 'Status',
                cell: (ticket) => (
                    <StatusBadge variant={statusVariant(ticket.status)}>
                        {ticket.status}
                    </StatusBadge>
                ),
            },
            {
                key: 'created_at',
                header: 'Created',
                cell: (ticket) => formatDateTime(ticket.created_at),
            },
            {
                key: 'resolved_at',
                header: 'Resolved',
                cell: (ticket) => formatDateTime(ticket.resolved_at),
            },
            {
                key: 'actions',
                header: 'Actions',
                cell: (ticket) => (
                    <DataTableActions showHref={route('admin.tickets.show', ticket.id)} />
                ),
            },
        ],
        [],
    );

    const workOrderColumns: DataTableColumn<WorkOrderHistory>[] = useMemo(
        () => [
            { key: 'code', header: 'Code' },
            { key: 'title', header: 'Title' },
            { key: 'type', header: 'Type' },
            {
                key: 'priority',
                header: 'Priority',
                cell: (workOrder) => (
                    <StatusBadge variant={priorityVariant(workOrder.priority)}>
                        {workOrder.priority}
                    </StatusBadge>
                ),
            },
            {
                key: 'status',
                header: 'Status',
                cell: (workOrder) => (
                    <StatusBadge variant={statusVariant(workOrder.status)}>
                        {workOrder.status}
                    </StatusBadge>
                ),
            },
            {
                key: 'scheduled_date',
                header: 'Scheduled',
                cell: (workOrder) => formatDate(workOrder.scheduled_date),
            },
            {
                key: 'completed_at',
                header: 'Completed',
                cell: (workOrder) => formatDateTime(workOrder.completed_at),
            },
            {
                key: 'actions',
                header: 'Actions',
                cell: (workOrder) => (
                    <DataTableActions showHref={route('admin.spk.show', workOrder.id)} />
                ),
            },
        ],
        [],
    );

    const hasHistoryAccess =
        historyAccess.billing || historyAccess.tickets || historyAccess.spk;

    return (
        <AdminLayout title="User Detail">
            <div className="space-y-6">
                <PageHeader
                    title={user.data.name}
                    subtitle={user.data.email}
                    actions={
                        <Button
                            type="button"
                            variant="ghost"
                            className="bg-warning text-white hover:bg-warning/90 hover:text-white"
                            onClick={() => router.get(route('admin.users.edit', user.data.id))}
                        >
                            Edit
                        </Button>
                    }
                />

                <div className="grid gap-6 xl:grid-cols-[2fr_1fr]">
                    <Card>
                        <CardHeader>
                            <CardTitle>User Info</CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-4 text-sm md:grid-cols-2">
                            <p className="text-surface-700 dark:text-surface-300">
                                Company ID: {user.data.company_id ?? '-'}
                            </p>
                            <p>
                                <Badge variant={user.data.is_active ? 'success' : 'danger'}>
                                    {user.data.is_active ? 'Active' : 'Inactive'}
                                </Badge>
                            </p>
                            <p className="text-surface-700 dark:text-surface-300">
                                Created: {formatDateTime(user.data.created_at)}
                            </p>
                            <p className="text-surface-700 dark:text-surface-300">
                                Last login: {formatDateTime(user.data.last_login_at)}
                            </p>
                            {history.linked_customer ? (
                                <p className="text-surface-700 dark:text-surface-300 md:col-span-2">
                                    Customer: {history.linked_customer.code} —{' '}
                                    {history.linked_customer.name}
                                </p>
                            ) : null}
                            <div className="space-y-2 md:col-span-2">
                                <p className="font-medium text-surface-900 dark:text-surface-100">
                                    Roles
                                </p>
                                <div className="flex flex-wrap gap-2">
                                    {user.data.roles.map((role) => (
                                        <RoleBadge key={role} role={role} />
                                    ))}
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle>Activity Log</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-2 text-sm text-surface-600 dark:text-surface-400">
                            {(user.data.activity_log ?? []).length === 0 ? (
                                <p>No activity available.</p>
                            ) : null}
                            {(user.data.activity_log ?? []).map((item) => (
                                <p key={item.id}>
                                    {formatDateTime(item.created_at)} — {item.description}
                                </p>
                            ))}
                        </CardContent>
                    </Card>
                </div>

                {hasHistoryAccess ? (
                    <Card>
                        <CardContent className="pt-6">
                            <Tabs value={tab} onValueChange={setTab}>
                                <TabList className="overflow-x-auto">
                                    {historyAccess.billing ? (
                                        <Tab value="billing">
                                            Billing History ({history.invoices.length})
                                        </Tab>
                                    ) : null}
                                    {historyAccess.tickets ? (
                                        <Tab value="tickets">
                                            Ticket History ({history.tickets.length})
                                        </Tab>
                                    ) : null}
                                    {historyAccess.spk ? (
                                        <Tab value="spk">
                                            SPK History ({history.work_orders.length})
                                        </Tab>
                                    ) : null}
                                </TabList>
                                {historyAccess.billing ? (
                                    <TabPanel value="billing">
                                        <DataTable
                                            columns={invoiceColumns}
                                            rows={history.invoices}
                                            emptyTitle="No billing history"
                                            emptyDescription="No invoices are related to this user."
                                        />
                                    </TabPanel>
                                ) : null}
                                {historyAccess.tickets ? (
                                    <TabPanel value="tickets">
                                        <DataTable
                                            columns={ticketColumns}
                                            rows={history.tickets}
                                            emptyTitle="No ticket history"
                                            emptyDescription="No tickets are related to this user."
                                        />
                                    </TabPanel>
                                ) : null}
                                {historyAccess.spk ? (
                                    <TabPanel value="spk">
                                        <DataTable
                                            columns={workOrderColumns}
                                            rows={history.work_orders}
                                            emptyTitle="No SPK history"
                                            emptyDescription="No SPK records are related to this user."
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

function statusVariant(status: string): 'success' | 'warning' | 'danger' | 'neutral' {
    if (['paid', 'completed', 'resolved', 'closed'].includes(status)) return 'success';
    if (['overdue', 'cancelled', 'rejected', 'void'].includes(status)) return 'danger';
    if (['partial', 'in_progress', 'assigned', 'pending'].includes(status)) return 'warning';
    return 'neutral';
}

function priorityVariant(priority: string): 'danger' | 'warning' | 'neutral' {
    if (priority === 'urgent' || priority === 'high') return 'danger';
    if (priority === 'medium') return 'warning';
    return 'neutral';
}
