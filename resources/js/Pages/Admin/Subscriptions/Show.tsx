import { FormEvent, useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Button, Card, CardContent, CardHeader, CardTitle, Input, Modal, Textarea } from '@/Components/ui';
import { StatusBadge } from '@/Components/composite';
import type { ServiceSubscription } from '@/types/models';

interface ShowProps extends Record<string, unknown> {
    subscription: { data: ServiceSubscription };
}

export default function Show({ subscription }: ShowProps) {
    const s = subscription.data;
    const [actionModal, setActionModal] = useState<'suspend' | 'terminate' | null>(null);
    const { data, setData, post, processing } = useForm({ reason: '', release_ont: false });

    const doAction = (e: FormEvent) => {
        e.preventDefault();
        if (actionModal === 'suspend') {
            post(route('admin.subscriptions.suspend', s.id), { onSuccess: () => setActionModal(null) });
        } else if (actionModal === 'terminate') {
            post(route('admin.subscriptions.terminate', s.id), { onSuccess: () => setActionModal(null) });
        }
    };

    return (
        <AdminLayout title={`Subscription ${s.code}`}>
            <div className="space-y-6">
                <div className="flex items-center justify-between gap-4">
                    <div>
                        <h2 className="text-2xl font-bold text-surface-900 dark:text-surface-100">{s.code}</h2>
                        <p className="mt-1 text-sm text-surface-500 dark:text-surface-400">Status: <StatusBadge variant={s.status === 'active' ? 'success' : s.status === 'suspended' ? 'warning' : s.status === 'terminated' ? 'danger' : 'muted'}>{s.status}</StatusBadge></p>
                    </div>
                    <Button type="button" variant="secondary" onClick={() => router.get(route('admin.customers.subscriptions.index', s.customer_id))}>Back</Button>
                </div>

                <Card>
                    <CardHeader><CardTitle>Subscription Details</CardTitle></CardHeader>
                    <CardContent className="grid gap-4 text-sm md:grid-cols-2">
                        <p><span className="text-surface-500 dark:text-surface-400">Package: </span>{s.package?.name ?? `#${s.service_package_id}`}</p>
                        <p><span className="text-surface-500 dark:text-surface-400">Customer: </span>{s.customer?.name ?? `#${s.customer_id}`}</p>
                        <p><span className="text-surface-500 dark:text-surface-400">Installation Address: </span>{s.installation_address?.label ?? `#${s.installation_address_id}`}</p>
                        <p><span className="text-surface-500 dark:text-surface-400">Serving POP: </span>{s.serving_pop?.name ?? '-'}</p>
                        <p><span className="text-surface-500 dark:text-surface-400">MRC: </span>{s.mrc_amount}</p>
                        <p><span className="text-surface-500 dark:text-surface-400">OTC: </span>{s.otc_installation_fee}</p>
                        <p><span className="text-surface-500 dark:text-surface-400">Billing Day: </span>{s.billing_day}</p>
                        <p><span className="text-surface-500 dark:text-surface-400">Contract Months: </span>{s.contract_months ?? '-'}</p>
                        <p><span className="text-surface-500 dark:text-surface-400">Activation Date: </span>{s.activation_date ?? '-'}</p>
                        <p><span className="text-surface-500 dark:text-surface-400">Expiration Date: </span>{s.expiration_date ?? '-'}</p>
                        <p><span className="text-surface-500 dark:text-surface-400">Next Invoice: </span>{s.next_invoice_date ?? '-'}</p>
                        <p><span className="text-surface-500 dark:text-surface-400">Terminated At: </span>{s.terminated_at ?? '-'}</p>
                        {s.terminated_reason && <p className="md:col-span-2"><span className="text-surface-500 dark:text-surface-400">Terminated Reason: </span>{s.terminated_reason}</p>}
                        {s.notes && <p className="md:col-span-2"><span className="text-surface-500 dark:text-surface-400">Notes: </span>{s.notes}</p>}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader><CardTitle>Lifecycle Actions</CardTitle></CardHeader>
                    <CardContent>
                        <div className="flex flex-wrap gap-2">
                            {s.status === 'pending' && <Button type="button" onClick={() => post(route('admin.subscriptions.activate', s.id))}>Activate</Button>}
                            {s.status === 'active' && <Button type="button" variant="outline" onClick={() => setActionModal('suspend')}>Suspend</Button>}
                            {s.status === 'suspended' && <Button type="button" onClick={() => post(route('admin.subscriptions.reactivate', s.id))}>Reactivate</Button>}
                            {(s.status === 'active' || s.status === 'suspended') && <Button type="button" variant="danger" onClick={() => setActionModal('terminate')}>Terminate</Button>}
                        </div>
                    </CardContent>
                </Card>

                <Modal open={actionModal !== null} onClose={() => setActionModal(null)} title={actionModal === 'suspend' ? 'Suspend Subscription' : 'Terminate Subscription'}>
                    <form onSubmit={doAction} className="space-y-4">
                        <Input label="Reason" value={data.reason} onChange={(e) => setData('reason', e.target.value)} required />
                        {actionModal === 'terminate' && (
                            <div className="text-sm text-surface-500">ONT release will be available in Phase 3.</div>
                        )}
                        <div className="flex justify-end gap-2">
                            <Button type="button" variant="secondary" onClick={() => setActionModal(null)}>Cancel</Button>
                            <Button type="submit" variant="danger" loading={processing}>{actionModal === 'suspend' ? 'Suspend' : 'Terminate'}</Button>
                        </div>
                    </form>
                </Modal>
            </div>
        </AdminLayout>
    );
}
