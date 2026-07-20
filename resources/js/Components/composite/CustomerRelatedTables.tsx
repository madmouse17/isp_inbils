import { useState } from 'react';
import { Link } from '@inertiajs/react';
import { Badge, Table, Tab, TabList, TabPanel, Tabs, TBody, TD, TH, THead, TR } from '@/Components/ui';
import { StatusBadge } from './StatusBadge';
import type { CustomerAddress, CustomerContact, ServiceSubscription } from '@/types/models';

interface CustomerRelatedTablesProps {
    customerId: number;
    addresses: CustomerAddress[];
    contacts: CustomerContact[];
    subscriptions: ServiceSubscription[];
}

export function CustomerRelatedTables({
    customerId,
    addresses,
    contacts,
    subscriptions,
}: CustomerRelatedTablesProps) {
    const [tab, setTab] = useState('addresses');

    return (
        <Tabs value={tab} onValueChange={setTab}>
            <TabList>
                <Tab value="addresses">Addresses ({addresses.length})</Tab>
                <Tab value="contacts">Contacts ({contacts.length})</Tab>
                <Tab value="subscriptions">Subscriptions ({subscriptions.length})</Tab>
            </TabList>
            <TabPanel value="addresses">
                <div className="space-y-3">
                    <div className="flex justify-end">
                        <Link
                            href={route('admin.customers.addresses.index', customerId)}
                            className="text-sm font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400"
                        >
                            Manage Addresses
                        </Link>
                    </div>
                    <Table>
                        <THead>
                            <TR>
                                <TH>Label</TH>
                                <TH>Address</TH>
                                <TH>City</TH>
                                <TH>Installation</TH>
                                <TH>Primary</TH>
                            </TR>
                        </THead>
                        <TBody>
                            {addresses.length === 0 ? (
                                <TR>
                                    <TD colSpan={5} className="py-8 text-center text-muted-foreground">
                                        No addresses.
                                    </TD>
                                </TR>
                            ) : (
                                addresses.map((address) => (
                                    <TR key={address.id}>
                                        <TD>{address.label}</TD>
                                        <TD>{address.address}</TD>
                                        <TD>{address.city ?? '-'}</TD>
                                        <TD>
                                            {address.is_installation_point ? (
                                                <Badge variant="success">Yes</Badge>
                                            ) : (
                                                '-'
                                            )}
                                        </TD>
                                        <TD>
                                            {address.is_primary ? <Badge variant="brand">Yes</Badge> : '-'}
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
                    <div className="flex justify-end">
                        <Link
                            href={route('admin.customers.contacts.index', customerId)}
                            className="text-sm font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400"
                        >
                            Manage Contacts
                        </Link>
                    </div>
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
                                    <TD colSpan={5} className="py-8 text-center text-muted-foreground">
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
                                        <TD>{contact.is_primary ? <Badge variant="brand">Yes</Badge> : '-'}</TD>
                                    </TR>
                                ))
                            )}
                        </TBody>
                    </Table>
                </div>
            </TabPanel>
            <TabPanel value="subscriptions">
                <div className="space-y-3">
                    <div className="flex justify-end">
                        <Link
                            href={route('admin.customers.subscriptions.index', customerId)}
                            className="text-sm font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400"
                        >
                            Manage Subscriptions
                        </Link>
                    </div>
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
                                    <TD colSpan={5} className="py-8 text-center text-muted-foreground">
                                        No subscriptions.
                                    </TD>
                                </TR>
                            ) : (
                                subscriptions.map((subscription) => (
                                    <TR key={subscription.id}>
                                        <TD className="font-mono text-sm">{subscription.code}</TD>
                                        <TD>
                                            {subscription.package?.name ?? `#${subscription.service_package_id}`}
                                        </TD>
                                        <TD>
                                            <StatusBadge variant={subscriptionStatusVariant(subscription.status)}>
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
        </Tabs>
    );
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
