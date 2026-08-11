import { useState } from 'react';
import { Link } from '@inertiajs/react';
import {
    Badge,
    Table,
    Tab,
    TabList,
    TabPanel,
    Tabs,
    TBody,
    TD,
    TH,
    THead,
    TR,
} from '@/Components/ui';
import { StatusBadge } from './StatusBadge';
import { formatDate, formatMoney } from '@/lib/format';
import type { CustomerAddress, CustomerContact, ServiceSubscription } from '@/types/models';

interface InvoiceHistory {
    id: number;
    number: string;
    status: string;
    issue_date: string;
    due_date: string;
    total: string | number;
    paid_amount: string | number;
}

interface TicketHistory {
    id: number;
    code: string;
    title: string;
    priority: string;
    status: string;
    created_at: string;
}

interface WorkOrderHistory {
    id: number;
    code: string;
    title: string;
    type: string;
    priority: string;
    status: string;
    scheduled_date: string | null;
}

export interface CustomerHistory {
    invoices: InvoiceHistory[];
    tickets: TicketHistory[];
    work_orders: WorkOrderHistory[];
}

export interface CustomerHistoryAccess {
    billing: boolean;
    tickets: boolean;
    spk: boolean;
}

interface CustomerRelatedTablesProps {
    addresses: CustomerAddress[];
    contacts: CustomerContact[];
    subscriptions: ServiceSubscription[];
    history?: CustomerHistory;
    historyAccess?: CustomerHistoryAccess;
}

export function CustomerRelatedTables({
    addresses,
    contacts,
    subscriptions,
    history = { invoices: [], tickets: [], work_orders: [] },
    historyAccess = { billing: false, tickets: false, spk: false },
}: CustomerRelatedTablesProps) {
    const [tab, setTab] = useState('addresses');

    return (
        <Tabs value={tab} onValueChange={setTab}>
            <TabList>
                <Tab value="addresses">Addresses ({addresses.length})</Tab>
                <Tab value="contacts">Contacts ({contacts.length})</Tab>
                <Tab value="subscriptions">Subscriptions ({subscriptions.length})</Tab>
                {historyAccess.billing ? <Tab value="billing">Billing ({history.invoices.length})</Tab> : null}
                {historyAccess.spk ? <Tab value="spk">SPK ({history.work_orders.length})</Tab> : null}
                {historyAccess.tickets ? <Tab value="tickets">Tickets ({history.tickets.length})</Tab> : null}
            </TabList>
            <TabPanel value="addresses">
                <div className="space-y-3">
                    <Table>
                        <THead>
                            <TR>
                                <TH>Label</TH>
                                <TH>Address</TH>
                                <TH>Region</TH>
                                <TH>Coordinates</TH>
                                <TH>Installation</TH>
                                <TH>Primary</TH>
                            </TR>
                        </THead>
                        <TBody>
                            {addresses.length === 0 ? (
                                <TR>
                                    <TD
                                        colSpan={6}
                                        className="py-8 text-center text-muted-foreground"
                                    >
                                        No addresses.
                                    </TD>
                                </TR>
                            ) : (
                                addresses.map((address) => (
                                    <TR key={address.id}>
                                        <TD>{address.label}</TD>
                                        <TD>{address.address}</TD>
                                        <TD>
                                            {[address.village_name, address.district_name, address.city_name, address.province_name]
                                                .filter(Boolean)
                                                .join(', ') || address.city || '-'}
                                        </TD>
                                        <TD>
                                            {address.lat && address.lng ? `${address.lat}, ${address.lng}` : '-'}
                                        </TD>
                                        <TD>
                                            {address.is_installation_point ? (
                                                <Badge variant="success">Yes</Badge>
                                            ) : (
                                                '-'
                                            )}
                                        </TD>
                                        <TD>
                                            {address.is_primary ? (
                                                <Badge variant="brand">Yes</Badge>
                                            ) : (
                                                '-'
                                            )}
                                        </TD>
                                    </TR>
                                ))
                            )}
                        </TBody>
                    </Table>
                </div>
            </TabPanel>
            <TabPanel value="contacts">
                <div className="space-y-3">
                    <Table>
                        <THead>
                            <TR>
                                <TH>Name</TH>
                                <TH>Position</TH>
                                <TH>Phone</TH>
                                <TH>Email</TH>
                                <TH>Primary</TH>
                            </TR>
                        </THead>
                        <TBody>
                            {contacts.length === 0 ? (
                                <TR>
                                    <TD
                                        colSpan={5}
                                        className="py-8 text-center text-muted-foreground"
                                    >
                                        No contacts.
                                    </TD>
                                </TR>
                            ) : (
                                contacts.map((contact) => (
                                    <TR key={contact.id}>
                                        <TD>{contact.name}</TD>
                                        <TD>{contact.position ?? '-'}</TD>
                                        <TD>{contact.phone ?? '-'}</TD>
                                        <TD>{contact.email ?? '-'}</TD>
                                        <TD>
                                            {contact.is_primary ? (
                                                <Badge variant="brand">Yes</Badge>
                                            ) : (
                                                '-'
                                            )}
                                        </TD>
                                    </TR>
                                ))
                            )}
                        </TBody>
                    </Table>
                </div>
            </TabPanel>
            <TabPanel value="subscriptions">
                <div className="space-y-3">
                    <Table>
                        <THead>
                            <TR>
                                <TH>Code</TH>
                                <TH>Package</TH>
                                <TH>Status</TH>
                                <TH>MRC</TH>
                                <TH>Billing Day</TH>
                            </TR>
                        </THead>
                        <TBody>
                            {subscriptions.length === 0 ? (
                                <TR>
                                    <TD
                                        colSpan={5}
                                        className="py-8 text-center text-muted-foreground"
                                    >
                                        No subscriptions.
                                    </TD>
                                </TR>
                            ) : (
                                subscriptions.map((subscription) => (
                                    <TR key={subscription.id}>
                                        <TD className="font-mono text-sm">{subscription.code}</TD>
                                        <TD>
                                            {subscription.package?.name ??
                                                `#${subscription.service_package_id}`}
                                        </TD>
                                        <TD>
                                            <StatusBadge
                                                variant={subscriptionStatusVariant(
                                                    subscription.status,
                                                )}
                                            >
                                                {subscription.status}
                                            </StatusBadge>
                                        </TD>
                                        <TD>{subscription.mrc_amount}</TD>
                                        <TD>{subscription.billing_day}</TD>
                                    </TR>
                                ))
                            )}
                        </TBody>
                    </Table>
                </div>
            </TabPanel>
            {historyAccess.billing ? (
                <TabPanel value="billing">
                    <Table>
                        <THead>
                            <TR>
                                <TH>Invoice</TH>
                                <TH>Issue / Due</TH>
                                <TH>Billed</TH>
                                <TH>Paid</TH>
                                <TH>Outstanding</TH>
                                <TH>Status</TH>
                                <TH>Action</TH>
                            </TR>
                        </THead>
                        <TBody>
                            {history.invoices.length === 0 ? (
                                <TR><TD colSpan={7} className="py-8 text-center text-muted-foreground">No billing history.</TD></TR>
                            ) : history.invoices.map((invoice) => (
                                <TR key={invoice.id}>
                                    <TD className="font-mono text-sm">{invoice.number}</TD>
                                    <TD>{formatDate(invoice.issue_date)} / {formatDate(invoice.due_date)}</TD>
                                    <TD>{formatMoney(invoice.total)}</TD>
                                    <TD>{formatMoney(invoice.paid_amount)}</TD>
                                    <TD>{formatMoney(Number(invoice.total) - Number(invoice.paid_amount))}</TD>
                                    <TD><StatusBadge variant={historyStatusVariant(invoice.status)}>{invoice.status}</StatusBadge></TD>
                                    <TD><Link href={route('admin.invoices.show', invoice.id)} className="font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400">Show</Link></TD>
                                </TR>
                            ))}
                        </TBody>
                    </Table>
                </TabPanel>
            ) : null}
            {historyAccess.spk ? (
                <TabPanel value="spk">
                    <Table>
                        <THead><TR><TH>Code</TH><TH>Title</TH><TH>Type</TH><TH>Priority</TH><TH>Status</TH><TH>Scheduled</TH><TH>Action</TH></TR></THead>
                        <TBody>
                            {history.work_orders.length === 0 ? (
                                <TR><TD colSpan={7} className="py-8 text-center text-muted-foreground">No SPK history.</TD></TR>
                            ) : history.work_orders.map((workOrder) => (
                                <TR key={workOrder.id}>
                                    <TD className="font-mono text-sm">{workOrder.code}</TD><TD>{workOrder.title}</TD><TD>{workOrder.type}</TD><TD>{workOrder.priority}</TD>
                                    <TD><StatusBadge variant={historyStatusVariant(workOrder.status)}>{workOrder.status}</StatusBadge></TD><TD>{formatDate(workOrder.scheduled_date)}</TD>
                                    <TD><Link href={route('admin.spk.show', workOrder.id)} className="font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400">Show</Link></TD>
                                </TR>
                            ))}
                        </TBody>
                    </Table>
                </TabPanel>
            ) : null}
            {historyAccess.tickets ? (
                <TabPanel value="tickets">
                    <Table>
                        <THead><TR><TH>Code</TH><TH>Title</TH><TH>Priority</TH><TH>Status</TH><TH>Created</TH><TH>Action</TH></TR></THead>
                        <TBody>
                            {history.tickets.length === 0 ? (
                                <TR><TD colSpan={6} className="py-8 text-center text-muted-foreground">No ticket history.</TD></TR>
                            ) : history.tickets.map((ticket) => (
                                <TR key={ticket.id}>
                                    <TD className="font-mono text-sm">{ticket.code}</TD><TD>{ticket.title}</TD><TD>{ticket.priority}</TD>
                                    <TD><StatusBadge variant={historyStatusVariant(ticket.status)}>{ticket.status}</StatusBadge></TD><TD>{formatDate(ticket.created_at)}</TD>
                                    <TD><Link href={route('admin.tickets.show', ticket.id)} className="font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400">Show</Link></TD>
                                </TR>
                            ))}
                        </TBody>
                    </Table>
                </TabPanel>
            ) : null}
        </Tabs>
    );
}

function historyStatusVariant(status: string) {
    if (['paid', 'completed', 'closed', 'resolved', 'active'].includes(status)) return 'success';
    if (['overdue', 'cancelled', 'rejected', 'terminated'].includes(status)) return 'danger';
    if (['sent', 'generated', 'assigned', 'in_progress', 'open'].includes(status)) return 'warning';

    return 'muted';
}

function subscriptionStatusVariant(status: ServiceSubscription['status']) {
    if (status === 'active') {
        return 'success';
    }

    if (status === 'suspended') {
        return 'warning';
    }

    if (status === 'terminated') {
        return 'danger';
    }

    return 'muted';
}
