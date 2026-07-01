import { FormEvent, useState } from 'react';
import { Link, router, useForm } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Badge, Button, Card, CardContent, Input, Modal, Select, Table, TBody, TD, TH, THead, TR } from '@/Components/ui';
import { StatusBadge } from '@/Components/composite';
import type { ServiceSubscription } from '@/types/models';

interface SubIndexProps {
    customer: { id: number; code: string; name: string };
    subscriptions: { data: ServiceSubscription[] };
    packages: { data: { id: number; code: string; name: string; price_mrc: string }[] };
    addresses: { data: { id: number; label: string; address: string }[] };
}

export default function Index({ customer, subscriptions, packages, addresses }: SubIndexProps) {
    const [modalOpen, setModalOpen] = useState(false);
    const list = subscriptions.data;
    const { data, setData, post, processing, errors } = useForm({
        service_package_id: '',
        installation_address_id: '',
        billing_day: '1',
        mrc_amount: '',
        otc_installation_fee: '0',
        contract_months: '',
        notes: '',
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        post(route('admin.customers.subscriptions.store', customer.id), { onSuccess: () => setModalOpen(false) });
    };

    return (
        <AdminLayout title="Subscriptions">
            <div className="space-y-6">
                <div className="flex items-center justify-between gap-4">
                    <div>
                        <h2 className="text-2xl font-bold text-surface-900 dark:text-surface-100">Subscriptions</h2>
                        <p className="mt-1 text-sm text-surface-500 dark:text-surface-400">{customer.code} — {customer.name}</p>
                    </div>
                    <div className="flex gap-2">
                        <Button type="button" variant="secondary" onClick={() => router.get(route('admin.customers.show', customer.id))}>Back</Button>
                        <Button type="button" onClick={() => setModalOpen(true)}>Create Subscription</Button>
                    </div>
                </div>

                <Card>
                    <CardContent>
                        <Table>
                            <THead><TR><TH>Code</TH><TH>Package</TH><TH>Status</TH><TH>MRC</TH><TH>Billing Day</TH><TH>Actions</TH></TR></THead>
                            <TBody>
                                {list.length === 0 ? <TR><TD colSpan={6} className="text-center text-surface-500">No subscriptions.</TD></TR> :
                                list.map((s) => (
                                    <TR key={s.id}>
                                        <TD className="font-mono text-sm">{s.code}</TD>
                                        <TD>{s.package?.name ?? `#${s.service_package_id}`}</TD>
                                        <TD><StatusBadge variant={s.status === 'active' ? 'success' : s.status === 'suspended' ? 'warning' : s.status === 'terminated' ? 'danger' : 'muted'}>{s.status}</StatusBadge></TD>
                                        <TD>{s.mrc_amount}</TD>
                                        <TD>{s.billing_day}</TD>
                                        <TD>
                                            <Link href={route('admin.subscriptions.show', s.id)} className="text-sm font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400">Show</Link>
                                        </TD>
                                    </TR>
                                ))}
                            </TBody>
                        </Table>
                    </CardContent>
                </Card>

                <Modal open={modalOpen} onClose={() => setModalOpen(false)} title="Create Subscription">
                    <form onSubmit={submit} className="space-y-4">
                        <Select label="Service Package" value={data.service_package_id} onChange={(e) => setData('service_package_id', e.target.value)} error={errors.service_package_id} required>
                            <option value="">Select package...</option>
                            {packages.data.map((p) => <option key={p.id} value={p.id}>{p.name} (MRC: {p.price_mrc})</option>)}
                        </Select>
                        <Select label="Installation Address" value={data.installation_address_id} onChange={(e) => setData('installation_address_id', e.target.value)} error={errors.installation_address_id} required>
                            <option value="">Select address...</option>
                            {addresses.data.map((a) => <option key={a.id} value={a.id}>{a.label} — {a.address}</option>)}
                        </Select>
                        <div className="grid gap-4 md:grid-cols-2">
                            <Input label="Billing Day (1-28)" type="number" min={1} max={28} value={data.billing_day} onChange={(e) => setData('billing_day', e.target.value)} error={errors.billing_day} required />
                            <Input label="MRC Amount" value={data.mrc_amount} onChange={(e) => setData('mrc_amount', e.target.value)} error={errors.mrc_amount} placeholder="Auto from package" />
                            <Input label="OTC Installation Fee" value={data.otc_installation_fee} onChange={(e) => setData('otc_installation_fee', e.target.value)} error={errors.otc_installation_fee} />
                            <Input label="Contract Months" type="number" value={data.contract_months} onChange={(e) => setData('contract_months', e.target.value)} error={errors.contract_months} />
                        </div>
                        <div className="flex justify-end gap-2">
                            <Button type="button" variant="secondary" onClick={() => setModalOpen(false)}>Cancel</Button>
                            <Button type="submit" loading={processing}>Create</Button>
                        </div>
                    </form>
                </Modal>
            </div>
        </AdminLayout>
    );
}
