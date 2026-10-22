import { useState } from 'react';
import type { ReactNode } from 'react';
import { Link } from '@inertiajs/react';
import {
    EmptyCell,
    EnumBadge,
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
import { usePermission } from '@/hooks/usePermission';
import { formatDate, formatMoney } from '@/lib/format';
import {
    INVOICE_STATUS,
    PRIORITY,
    SUBSCRIPTION_STATUS,
    TICKET_STATUS,
    WORK_ORDER_STATUS,
    WORK_ORDER_TYPE,
} from '@/lib/statuses';
import type { CustomerAddress, CustomerContact, ServiceSubscription } from '@/types/models';

interface InvoiceHistory {
    id: string;
    number: string;
    status: string;
    issue_date: string;
    due_date: string;
    total: string | number;
    paid_amount: string | number;
}

interface TicketHistory {
    id: string;
    code: string;
    title: string;
    priority: string;
    status: string;
    created_at: string;
}

interface WorkOrderHistory {
    id: string;
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
    /** A tab the page owns (Dokumen on the customer page): its value, its label with any count, and its panel. */
    extraTab?: { value: string; label: string; content: ReactNode };
}

const DETAIL_LINK = 'font-medium text-primary-text hover:underline';

function EmptyRow({ colSpan, children }: { colSpan: number; children: ReactNode }) {
    return (
        <TR>
            <TD colSpan={colSpan} className="py-8 text-center text-muted-foreground">
                {children}
            </TD>
        </TR>
    );
}

export function CustomerRelatedTables({
    addresses,
    contacts,
    subscriptions,
    history = { invoices: [], tickets: [], work_orders: [] },
    historyAccess = { billing: false, tickets: false, spk: false },
    extraTab,
}: CustomerRelatedTablesProps) {
    const [tab, setTab] = useState('addresses');
    // The subscription pages ask for customer.subscription.view: without it the code stays a word, not a dead link.
    const canOpenSubscription = usePermission().can('customer.subscription.view');

    return (
        <Tabs value={tab} onValueChange={setTab}>
            <TabList aria-label="Data terkait pelanggan">
                <Tab value="addresses">Alamat ({addresses.length})</Tab>
                <Tab value="contacts">Kontak ({contacts.length})</Tab>
                <Tab value="subscriptions">Langganan ({subscriptions.length})</Tab>
                {historyAccess.billing ? (
                    <Tab value="billing">Tagihan ({history.invoices.length})</Tab>
                ) : null}
                {historyAccess.spk ? (
                    <Tab value="spk">SPK ({history.work_orders.length})</Tab>
                ) : null}
                {historyAccess.tickets ? (
                    <Tab value="tickets">Tiket ({history.tickets.length})</Tab>
                ) : null}
                {extraTab ? <Tab value={extraTab.value}>{extraTab.label}</Tab> : null}
            </TabList>
            <TabPanel value="addresses">
                <Table>
                    <THead>
                        <TR>
                            <TH>Label</TH>
                            <TH>Alamat</TH>
                            <TH>Wilayah</TH>
                            <TH>Koordinat</TH>
                            <TH>Pemasangan</TH>
                            <TH>Utama</TH>
                        </TR>
                    </THead>
                    <TBody>
                        {addresses.length === 0 ? (
                            <EmptyRow colSpan={6}>
                                Belum ada alamat. Tambahkan lewat halaman Ubah Pelanggan.
                            </EmptyRow>
                        ) : (
                            addresses.map((address) => (
                                <TR key={address.id}>
                                    <TD>{address.label}</TD>
                                    <TD>{address.address}</TD>
                                    <TD>
                                        {[
                                            address.village_name,
                                            address.district_name,
                                            address.city_name,
                                            address.province_name,
                                        ]
                                            .filter(Boolean)
                                            .join(', ') ||
                                            address.city || <EmptyCell />}
                                    </TD>
                                    <TD>
                                        {address.lat && address.lng ? (
                                            <span className="whitespace-nowrap">
                                                {`${address.lat}, ${address.lng}`}
                                            </span>
                                        ) : (
                                            <EmptyCell />
                                        )}
                                    </TD>
                                    <TD>{address.is_installation_point ? 'Ya' : <EmptyCell />}</TD>
                                    <TD>{address.is_primary ? 'Ya' : <EmptyCell />}</TD>
                                </TR>
                            ))
                        )}
                    </TBody>
                </Table>
            </TabPanel>
            <TabPanel value="contacts">
                <Table>
                    <THead>
                        <TR>
                            <TH>Nama</TH>
                            <TH>Jabatan</TH>
                            <TH>Telepon</TH>
                            <TH>Email</TH>
                            <TH>Utama</TH>
                        </TR>
                    </THead>
                    <TBody>
                        {contacts.length === 0 ? (
                            <EmptyRow colSpan={5}>
                                Belum ada kontak. Tambahkan lewat halaman Ubah Pelanggan.
                            </EmptyRow>
                        ) : (
                            contacts.map((contact) => (
                                <TR key={contact.id}>
                                    <TD>{contact.name}</TD>
                                    <TD>{contact.position || <EmptyCell />}</TD>
                                    <TD>{contact.phone || <EmptyCell />}</TD>
                                    <TD>{contact.email || <EmptyCell />}</TD>
                                    <TD>{contact.is_primary ? 'Ya' : <EmptyCell />}</TD>
                                </TR>
                            ))
                        )}
                    </TBody>
                </Table>
            </TabPanel>
            <TabPanel value="subscriptions">
                <Table>
                    <THead>
                        <TR>
                            <TH>Kode</TH>
                            <TH>Paket</TH>
                            <TH>Status</TH>
                            <TH>Biaya bulanan</TH>
                            <TH>Tanggal tagih</TH>
                            {canOpenSubscription ? <TH>Aksi</TH> : null}
                        </TR>
                    </THead>
                    <TBody>
                        {subscriptions.length === 0 ? (
                            <EmptyRow colSpan={canOpenSubscription ? 6 : 5}>
                                Belum ada langganan.
                            </EmptyRow>
                        ) : (
                            subscriptions.map((subscription) => (
                                <TR key={subscription.id}>
                                    <TD className="font-mono text-sm whitespace-nowrap">
                                        {canOpenSubscription ? (
                                            <Link
                                                href={route(
                                                    'admin.subscriptions.show',
                                                    subscription.id,
                                                )}
                                                className={DETAIL_LINK}
                                            >
                                                {subscription.code}
                                            </Link>
                                        ) : (
                                            subscription.code
                                        )}
                                    </TD>
                                    <TD>
                                        {subscription.package?.name ??
                                            `#${subscription.service_package_id}`}
                                    </TD>
                                    <TD>
                                        <EnumBadge
                                            map={SUBSCRIPTION_STATUS}
                                            value={subscription.status}
                                        />
                                    </TD>
                                    <TD className="whitespace-nowrap">
                                        {formatMoney(subscription.mrc_amount)}
                                    </TD>
                                    <TD>{subscription.billing_day}</TD>
                                    {canOpenSubscription ? (
                                        <TD>
                                            <Link
                                                href={route(
                                                    'admin.subscriptions.show',
                                                    subscription.id,
                                                )}
                                                className={DETAIL_LINK}
                                            >
                                                Kelola langganan
                                            </Link>
                                        </TD>
                                    ) : null}
                                </TR>
                            ))
                        )}
                    </TBody>
                </Table>
            </TabPanel>
            {historyAccess.billing ? (
                <TabPanel value="billing">
                    <Table>
                        <THead>
                            <TR>
                                <TH>Tagihan</TH>
                                <TH>Terbit / jatuh tempo</TH>
                                <TH>Ditagih</TH>
                                <TH>Dibayar</TH>
                                <TH>Sisa</TH>
                                <TH>Status</TH>
                                <TH>Aksi</TH>
                            </TR>
                        </THead>
                        <TBody>
                            {history.invoices.length === 0 ? (
                                <EmptyRow colSpan={7}>Belum ada riwayat tagihan.</EmptyRow>
                            ) : (
                                history.invoices.map((invoice) => (
                                    <TR key={invoice.id}>
                                        <TD className="font-mono text-sm whitespace-nowrap">
                                            {invoice.number}
                                        </TD>
                                        <TD className="whitespace-nowrap">
                                            {formatDate(invoice.issue_date)} /{' '}
                                            {formatDate(invoice.due_date)}
                                        </TD>
                                        <TD className="whitespace-nowrap">
                                            {formatMoney(invoice.total)}
                                        </TD>
                                        <TD className="whitespace-nowrap">
                                            {formatMoney(invoice.paid_amount)}
                                        </TD>
                                        <TD className="whitespace-nowrap">
                                            {formatMoney(
                                                Number(invoice.total) - Number(invoice.paid_amount),
                                            )}
                                        </TD>
                                        <TD>
                                            <EnumBadge
                                                map={INVOICE_STATUS}
                                                value={invoice.status}
                                            />
                                        </TD>
                                        <TD>
                                            <Link
                                                href={route('admin.invoices.show', invoice.id)}
                                                className={DETAIL_LINK}
                                            >
                                                Lihat
                                            </Link>
                                        </TD>
                                    </TR>
                                ))
                            )}
                        </TBody>
                    </Table>
                </TabPanel>
            ) : null}
            {historyAccess.spk ? (
                <TabPanel value="spk">
                    <Table>
                        <THead>
                            <TR>
                                <TH>Kode</TH>
                                <TH>Judul</TH>
                                <TH>Tipe</TH>
                                <TH>Prioritas</TH>
                                <TH>Status</TH>
                                <TH>Jadwal</TH>
                                <TH>Aksi</TH>
                            </TR>
                        </THead>
                        <TBody>
                            {history.work_orders.length === 0 ? (
                                <EmptyRow colSpan={7}>Belum ada riwayat SPK.</EmptyRow>
                            ) : (
                                history.work_orders.map((workOrder) => (
                                    <TR key={workOrder.id}>
                                        <TD className="font-mono text-sm whitespace-nowrap">
                                            {workOrder.code}
                                        </TD>
                                        <TD>{workOrder.title}</TD>
                                        <TD>
                                            <EnumBadge
                                                map={WORK_ORDER_TYPE}
                                                value={workOrder.type}
                                            />
                                        </TD>
                                        <TD>
                                            <EnumBadge map={PRIORITY} value={workOrder.priority} />
                                        </TD>
                                        <TD>
                                            <EnumBadge
                                                map={WORK_ORDER_STATUS}
                                                value={workOrder.status}
                                            />
                                        </TD>
                                        <TD className="whitespace-nowrap">
                                            {formatDate(workOrder.scheduled_date)}
                                        </TD>
                                        <TD>
                                            <Link
                                                href={route('admin.spk.show', workOrder.id)}
                                                className={DETAIL_LINK}
                                            >
                                                Lihat
                                            </Link>
                                        </TD>
                                    </TR>
                                ))
                            )}
                        </TBody>
                    </Table>
                </TabPanel>
            ) : null}
            {historyAccess.tickets ? (
                <TabPanel value="tickets">
                    <Table>
                        <THead>
                            <TR>
                                <TH>Kode</TH>
                                <TH>Judul</TH>
                                <TH>Prioritas</TH>
                                <TH>Status</TH>
                                <TH>Dibuat</TH>
                                <TH>Aksi</TH>
                            </TR>
                        </THead>
                        <TBody>
                            {history.tickets.length === 0 ? (
                                <EmptyRow colSpan={6}>Belum ada riwayat tiket.</EmptyRow>
                            ) : (
                                history.tickets.map((ticket) => (
                                    <TR key={ticket.id}>
                                        <TD className="font-mono text-sm whitespace-nowrap">
                                            {ticket.code}
                                        </TD>
                                        <TD>{ticket.title}</TD>
                                        <TD>
                                            <EnumBadge map={PRIORITY} value={ticket.priority} />
                                        </TD>
                                        <TD>
                                            <EnumBadge map={TICKET_STATUS} value={ticket.status} />
                                        </TD>
                                        <TD className="whitespace-nowrap">
                                            {formatDate(ticket.created_at)}
                                        </TD>
                                        <TD>
                                            <Link
                                                href={route('admin.tickets.show', ticket.id)}
                                                className={DETAIL_LINK}
                                            >
                                                Lihat
                                            </Link>
                                        </TD>
                                    </TR>
                                ))
                            )}
                        </TBody>
                    </Table>
                </TabPanel>
            ) : null}
            {extraTab ? <TabPanel value={extraTab.value}>{extraTab.content}</TabPanel> : null}
        </Tabs>
    );
}
