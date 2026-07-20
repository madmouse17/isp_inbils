import type { FormEvent } from 'react';
import { useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import {
    Badge,
    Button,
    Card,
    CardContent,
    CardHeader,
    CardTitle,
    Input,
    Modal,
    Table,
    TBody,
    TD,
    TH,
    THead,
    TR,
} from '@/Components/ui';
import { PageHeader } from '@/Components/composite';

interface WoData {
    id: number;
    code: string;
    type: string;
    title: string;
    description?: string | null;
    status: string;
    priority: string;
    source: string;
    scheduled_date?: string | null;
    started_at?: string | null;
    completed_at?: string | null;
    result?: string | null;
    rejection_reason?: string | null;
    customer?: { name: string; code: string } | null;
    subscription?: { code: string } | null;
    location?: { name: string; path?: string } | null;
    assignee?: { name: string } | null;
    items?: {
        id: number;
        product_id: number;
        quantity_reserved: string;
        quantity_used: string;
        note?: string | null;
        product?: { sku: string; name: string } | null;
    }[];
    assignments?: {
        id: number;
        assigned_at: string;
        unassigned_at?: string | null;
        technician?: { name: string } | null;
    }[];
    evidence?: {
        id: number;
        type: string;
        file_path: string;
        caption?: string | null;
        uploaded_at: string;
    }[];
}

interface ShowProps extends Record<string, unknown> {
    workOrder: { data: WoData };
}

export default function Show({ workOrder }: ShowProps) {
    const w = workOrder.data;
    const [actionModal, setActionModal] = useState<
        | 'generate'
        | 'assign'
        | 'start'
        | 'submit'
        | 'approve'
        | 'reject'
        | 'cancel'
        | 'addItem'
        | null
    >(null);
    const { data, setData, post, processing } = useForm({
        technician_id: '',
        reason: '',
        product_id: '',
        quantity_reserved: '',
        note: '',
    });

    const doAction = (e: FormEvent) => {
        e.preventDefault();
        if (!actionModal) return;
        post(route(`admin.spk.${actionModal}`, w.id), { onSuccess: () => setActionModal(null) });
    };

    const uploadEvidence = (e: FormEvent) => {
        e.preventDefault();
        const formData = new FormData(e.target as HTMLFormElement);
        router.post(route('admin.spk.evidence.store', w.id), formData, {
            onSuccess: () => (e.target as HTMLFormElement).reset(),
        });
    };

    return (
        <AdminLayout title={w.title}>
            <div className="space-y-6">
                <PageHeader
                    title={w.title}
                    subtitle={`${w.code} · ${w.status}`}
                    actions={
                        <Button
                            type="button"
                            variant="secondary"
                            onClick={() => router.get(route('admin.spk.index'))}
                        >
                            Back
                        </Button>
                    }
                />

                <div className="grid gap-6 xl:grid-cols-[2fr_1fr]">
                    <Card>
                        <CardHeader>
                            <CardTitle>Details</CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-4 text-sm md:grid-cols-2">
                            <p>
                                <span className="text-surface-500 dark:text-surface-400">
                                    Type:{' '}
                                </span>
                                <Badge variant="neutral">{w.type}</Badge>
                            </p>
                            <p>
                                <span className="text-surface-500 dark:text-surface-400">
                                    Priority:{' '}
                                </span>
                                <Badge
                                    variant={
                                        w.priority === 'urgent'
                                            ? 'danger'
                                            : w.priority === 'high'
                                              ? 'brand'
                                              : 'neutral'
                                    }
                                >
                                    {w.priority}
                                </Badge>
                            </p>
                            <p>
                                <span className="text-surface-500 dark:text-surface-400">
                                    Customer:{' '}
                                </span>
                                {w.customer?.name ?? '-'}
                            </p>
                            <p>
                                <span className="text-surface-500 dark:text-surface-400">
                                    Subscription:{' '}
                                </span>
                                {w.subscription?.code ?? '-'}
                            </p>
                            <p>
                                <span className="text-surface-500 dark:text-surface-400">
                                    Location:{' '}
                                </span>
                                {w.location?.name ?? '-'}
                            </p>
                            <p>
                                <span className="text-surface-500 dark:text-surface-400">
                                    Technician:{' '}
                                </span>
                                {w.assignee?.name ?? '-'}
                            </p>
                            <p>
                                <span className="text-surface-500 dark:text-surface-400">
                                    Scheduled:{' '}
                                </span>
                                {w.scheduled_date ?? '-'}
                            </p>
                            <p>
                                <span className="text-surface-500 dark:text-surface-400">
                                    Started:{' '}
                                </span>
                                {w.started_at ?? '-'}
                            </p>
                            <p>
                                <span className="text-surface-500 dark:text-surface-400">
                                    Completed:{' '}
                                </span>
                                {w.completed_at ?? '-'}
                            </p>
                            {w.description && (
                                <p className="md:col-span-2">
                                    <span className="text-surface-500 dark:text-surface-400">
                                        Description:{' '}
                                    </span>
                                    {w.description}
                                </p>
                            )}
                            {w.result && (
                                <p className="md:col-span-2">
                                    <span className="text-surface-500 dark:text-surface-400">
                                        Result:{' '}
                                    </span>
                                    {w.result}
                                </p>
                            )}
                            {w.rejection_reason && (
                                <p className="md:col-span-2">
                                    <span className="text-surface-500 dark:text-surface-400">
                                        Rejection:{' '}
                                    </span>
                                    {w.rejection_reason}
                                </p>
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Lifecycle Actions</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="flex flex-wrap gap-2">
                                {w.status === 'draft' && (
                                    <Button
                                        type="button"
                                        onClick={() => setActionModal('generate')}
                                    >
                                        Generate
                                    </Button>
                                )}
                                {(w.status === 'draft' ||
                                    w.status === 'generated' ||
                                    w.status === 'rejected') && (
                                    <Button type="button" onClick={() => setActionModal('assign')}>
                                        Assign
                                    </Button>
                                )}
                                {w.status === 'assigned' && (
                                    <Button type="button" onClick={() => setActionModal('start')}>
                                        Start
                                    </Button>
                                )}
                                {w.status === 'in_progress' && (
                                    <Button type="button" onClick={() => setActionModal('submit')}>
                                        Submit for Review
                                    </Button>
                                )}
                                {w.status === 'waiting_review' && (
                                    <Button type="button" onClick={() => setActionModal('approve')}>
                                        Approve
                                    </Button>
                                )}
                                {w.status === 'waiting_review' && (
                                    <Button
                                        type="button"
                                        variant="danger"
                                        onClick={() => setActionModal('reject')}
                                    >
                                        Reject
                                    </Button>
                                )}
                                {!['completed', 'cancelled'].includes(w.status) && (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() => setActionModal('cancel')}
                                    >
                                        Cancel
                                    </Button>
                                )}
                            </div>
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Items</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <Table>
                            <THead>
                                <TR>
                                    <TH>Product</TH>
                                    <TH>Reserved</TH>
                                    <TH>Used</TH>
                                    <TH>Note</TH>
                                </TR>
                            </THead>
                            <TBody>
                                {(w.items ?? []).length === 0 ? (
                                    <TR>
                                        <TD colSpan={4} className="text-center text-surface-500">
                                            No items.
                                        </TD>
                                    </TR>
                                ) : (
                                    (w.items ?? []).map((i) => (
                                        <TR key={i.id}>
                                            <TD>{i.product?.name ?? `#${i.product_id}`}</TD>
                                            <TD>{i.quantity_reserved}</TD>
                                            <TD>{i.quantity_used}</TD>
                                            <TD>{i.note ?? '-'}</TD>
                                        </TR>
                                    ))
                                )}
                            </TBody>
                        </Table>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Evidence</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <form onSubmit={uploadEvidence} className="flex gap-2">
                            <input
                                type="file"
                                name="file"
                                accept="image/*,application/pdf"
                                required
                                className="text-sm"
                            />
                            <Input label="Caption" name="caption" placeholder="Optional caption" />
                            <div className="self-end">
                                <Button type="submit">Upload</Button>
                            </div>
                        </form>
                        <div className="grid gap-2 sm:grid-cols-2 md:grid-cols-3">
                            {(w.evidence ?? []).map((ev) => (
                                <div
                                    key={ev.id}
                                    className="rounded-lg border border-surface-200 p-3 dark:border-surface-800"
                                >
                                    <p className="text-sm font-medium">
                                        {ev.type === 'photo' ? 'Photo' : 'Document'}
                                    </p>
                                    <p className="text-xs text-surface-500">
                                        {ev.caption ?? ev.file_path}
                                    </p>
                                    <p className="text-xs text-surface-400">{ev.uploaded_at}</p>
                                </div>
                            ))}
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Assignment History</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <Table>
                            <THead>
                                <TR>
                                    <TH>Technician</TH>
                                    <TH>Assigned At</TH>
                                    <TH>Unassigned At</TH>
                                </TR>
                            </THead>
                            <TBody>
                                {(w.assignments ?? []).length === 0 ? (
                                    <TR>
                                        <TD colSpan={3} className="text-center text-surface-500">
                                            No assignments.
                                        </TD>
                                    </TR>
                                ) : (
                                    (w.assignments ?? []).map((a) => (
                                        <TR key={a.id}>
                                            <TD>{a.technician?.name ?? '-'}</TD>
                                            <TD className="text-sm">{a.assigned_at}</TD>
                                            <TD className="text-sm">{a.unassigned_at ?? '—'}</TD>
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
                            ? actionModal.charAt(0).toUpperCase() + actionModal.slice(1)
                            : ''
                    }
                >
                    <form onSubmit={doAction} className="space-y-4">
                        {actionModal === 'assign' && (
                            <Input
                                label="Technician ID"
                                value={data.technician_id}
                                onChange={(e) => setData('technician_id', e.target.value)}
                                required
                            />
                        )}
                        {['reject', 'cancel'].includes(actionModal ?? '') && (
                            <Input
                                label="Reason"
                                value={data.reason}
                                onChange={(e) => setData('reason', e.target.value)}
                                required
                            />
                        )}
                        {actionModal === 'addItem' && (
                            <>
                                <Input
                                    label="Product ID"
                                    value={data.product_id}
                                    onChange={(e) => setData('product_id', e.target.value)}
                                    required
                                />
                                <Input
                                    label="Quantity Reserved"
                                    type="number"
                                    step="0.01"
                                    value={data.quantity_reserved}
                                    onChange={(e) => setData('quantity_reserved', e.target.value)}
                                />
                                <Input
                                    label="Note"
                                    value={data.note}
                                    onChange={(e) => setData('note', e.target.value)}
                                />
                            </>
                        )}
                        <div className="flex justify-end gap-2">
                            <Button
                                type="button"
                                variant="secondary"
                                onClick={() => setActionModal(null)}
                            >
                                Cancel
                            </Button>
                            <Button type="submit" loading={processing}>
                                Confirm
                            </Button>
                        </div>
                    </form>
                </Modal>
            </div>
        </AdminLayout>
    );
}
