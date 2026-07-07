import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Badge, Button, Card, CardContent, CardHeader, CardTitle, Table, TBody, TD, TH, THead, TR, Tabs, TabList, Tab, TabPanel } from '@/Components/ui';
import { PageHeader, StatusBadge } from '@/Components/composite';
import type { Customer, CustomerAddress, CustomerContact, ServiceSubscription } from '@/types/models';

interface ShowProps extends Record<string, unknown> {
    customer: { data: Customer };
}

export default function Show({ customer }: ShowProps) {
    const c = customer.data;
    const addresses: CustomerAddress[] = c.addresses ?? [];
    const contacts: CustomerContact[] = c.contacts ?? [];
    const subscriptions: ServiceSubscription[] = c.subscriptions ?? [];
    const [tab, setTab] = useState('addresses');

    return (
        <AdminLayout title={c.name}>
            <div className="space-y-6">
                <PageHeader
                    title={c.name}
                    subtitle={`${c.code} · ${c.type}`}
                    actions={(
                        <>
                            <Button type="button" variant="outline" onClick={() => router.get(route('admin.customers.edit', c.id))}>Edit</Button>
                            <Button type="button" variant="secondary" onClick={() => router.get(route('admin.customers.index'))}>Back</Button>
                        </>
                    )}
                />

                <div className="grid gap-6 xl:grid-cols-[2fr_1fr]">
                    <Card>
                        <CardHeader>
                            <CardTitle>Details</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3 text-sm">
                            <p><span className="text-muted-foreground">Email: </span>{c.email ?? '-'}</p>
                            <p><span className="text-muted-foreground">Phone: </span>{c.phone ?? '-'}</p>
                            <p><span className="text-muted-foreground">Tax ID: </span>{c.tax_id ?? '-'}</p>
                            <p><span className="text-muted-foreground">Contact Person: </span>{c.contact_person ?? '-'}</p>
                            {c.notes && <p><span className="text-muted-foreground">Notes: </span>{c.notes}</p>}
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle>Status</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3 text-sm">
                            <p><span className="text-muted-foreground">Type: </span><Badge variant={c.type === 'Company' ? 'brand' : 'neutral'}>{c.type}</Badge></p>
                            <p><span className="text-muted-foreground">Status: </span><StatusBadge variant={c.is_active ? 'success' : 'danger'}>{c.is_active ? 'Active' : 'Inactive'}</StatusBadge></p>
                        </CardContent>
                    </Card>
                </div>

                <Tabs value={tab} onValueChange={setTab}>
                    <TabList>
                        <Tab value="addresses">Addresses ({addresses.length})</Tab>
                        <Tab value="contacts">Contacts ({contacts.length})</Tab>
                        <Tab value="subscriptions">Subscriptions ({subscriptions.length})</Tab>
                    </TabList>
                    <TabPanel value="addresses">
                        <div className="space-y-3">
                            <div className="flex justify-end">
                                <Link href={route('admin.customers.addresses.index', c.id)} className="text-sm font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400">Manage Addresses →</Link>
                            </div>
                            <Table>
                                <THead><TR><TH>Label</TH><TH>Address</TH><TH>City</TH><TH>Installation</TH><TH>Primary</TH></TR></THead>
                                <TBody>
                                    {addresses.length === 0 ? <TR><TD colSpan={5} className="text-center text-muted-foreground">No addresses.</TD></TR> :
                                    addresses.map((a) => (
                                        <TR key={a.id}>
                                            <TD>{a.label}</TD>
                                            <TD>{a.address}</TD>
                                            <TD>{a.city ?? '-'}</TD>
                                            <TD>{a.is_installation_point ? <Badge variant="success">Yes</Badge> : '-'}</TD>
                                            <TD>{a.is_primary ? <Badge variant="brand">Yes</Badge> : '-'}</TD>
                                        </TR>
                                    ))}
                                </TBody>
                            </Table>
                        </div>
                    </TabPanel>
                    <TabPanel value="contacts">
                        <div className="space-y-3">
                            <div className="flex justify-end">
                                <Link href={route('admin.customers.contacts.index', c.id)} className="text-sm font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400">Manage Contacts →</Link>
                            </div>
                            <Table>
                                <THead><TR><TH>Name</TH><TH>Position</TH><TH>Phone</TH><TH>Email</TH><TH>Primary</TH></TR></THead>
                                <TBody>
                                    {contacts.length === 0 ? <TR><TD colSpan={5} className="text-center text-muted-foreground">No contacts.</TD></TR> :
                                    contacts.map((ct) => (
                                        <TR key={ct.id}>
                                            <TD>{ct.name}</TD>
                                            <TD>{ct.position ?? '-'}</TD>
                                            <TD>{ct.phone ?? '-'}</TD>
                                            <TD>{ct.email ?? '-'}</TD>
                                            <TD>{ct.is_primary ? <Badge variant="brand">Yes</Badge> : '-'}</TD>
                                        </TR>
                                    ))}
                                </TBody>
                            </Table>
                        </div>
                    </TabPanel>
                    <TabPanel value="subscriptions">
                        <div className="space-y-3">
                            <div className="flex justify-end">
                                <Link href={route('admin.customers.subscriptions.index', c.id)} className="text-sm font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400">Manage Subscriptions →</Link>
                            </div>
                            <Table>
                                <THead><TR><TH>Code</TH><TH>Package</TH><TH>Status</TH><TH>MRC</TH><TH>Billing Day</TH></TR></THead>
                                <TBody>
                                    {subscriptions.length === 0 ? <TR><TD colSpan={5} className="text-center text-muted-foreground">No subscriptions.</TD></TR> :
                                    subscriptions.map((s) => (
                                        <TR key={s.id}>
                                            <TD className="font-mono text-sm">{s.code}</TD>
                                            <TD>{s.package?.name ?? `#${s.service_package_id}`}</TD>
                                            <TD><StatusBadge variant={s.status === 'active' ? 'success' : s.status === 'suspended' ? 'warning' : s.status === 'terminated' ? 'danger' : 'muted'}>{s.status}</StatusBadge></TD>
                                            <TD>{s.mrc_amount}</TD>
                                            <TD>{s.billing_day}</TD>
                                        </TR>
                                    ))}
                                </TBody>
                            </Table>
                        </div>
                    </TabPanel>
                </Tabs>
            </div>
        </AdminLayout>
    );
}
