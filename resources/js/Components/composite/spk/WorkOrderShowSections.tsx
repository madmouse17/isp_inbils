import type { FormEvent } from 'react';
import {
    Badge,
    Button,
    Card,
    CardContent,
    CardHeader,
    CardTitle,
    FileUpload,
    Input,
    Modal,
    SearchSelect,
    Table,
    TBody,
    TD,
    TH,
    THead,
    TR,
} from '@/Components/ui';

export interface WorkOrderData {
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
        product?: {
            sku: string;
            name: string;
            unit?: { name: string; symbol: string } | null;
        } | null;
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

export interface WorkOrderTechnicianOption {
    id: number;
    user_id: number;
    employee_number: string;
    phone?: string | null;
    name: string;
    user?: { name: string; email: string } | null;
    organization?: { name: string; code: string } | null;
}

export interface WorkOrderProductOption {
    id: number;
    sku: string;
    name: string;
    type: string;
    unit?: { name: string; symbol: string } | null;
    category?: { name: string } | null;
}

export interface WorkOrderOption {
    value: string;
    label: string;
    description?: string;
}

export function WorkOrderDetailsCard({ workOrder }: { workOrder: WorkOrderData }) {
    return (
        <Card>
            <CardHeader>
                <CardTitle>Details</CardTitle>
            </CardHeader>
            <CardContent className="grid gap-4 text-sm md:grid-cols-2">
                <p>
                    <span className="text-surface-500 dark:text-surface-400">Type: </span>
                    <Badge variant="neutral">{workOrder.type}</Badge>
                </p>
                <p>
                    <span className="text-surface-500 dark:text-surface-400">Priority: </span>
                    <Badge
                        variant={
                            workOrder.priority === 'urgent'
                                ? 'danger'
                                : workOrder.priority === 'high'
                                  ? 'brand'
                                  : 'neutral'
                        }
                    >
                        {workOrder.priority}
                    </Badge>
                </p>
                <p>
                    <span className="text-surface-500 dark:text-surface-400">Customer: </span>
                    {workOrder.customer?.name ?? '-'}
                </p>
                <p>
                    <span className="text-surface-500 dark:text-surface-400">Subscription: </span>
                    {workOrder.subscription?.code ?? '-'}
                </p>
                <p>
                    <span className="text-surface-500 dark:text-surface-400">Location: </span>
                    {workOrder.location?.name ?? '-'}
                </p>
                <p>
                    <span className="text-surface-500 dark:text-surface-400">Technician: </span>
                    {workOrder.assignee?.name ?? '-'}
                </p>
                <p>
                    <span className="text-surface-500 dark:text-surface-400">Scheduled: </span>
                    {workOrder.scheduled_date ?? '-'}
                </p>
                <p>
                    <span className="text-surface-500 dark:text-surface-400">Started: </span>
                    {workOrder.started_at ?? '-'}
                </p>
                <p>
                    <span className="text-surface-500 dark:text-surface-400">Completed: </span>
                    {workOrder.completed_at ?? '-'}
                </p>
                {workOrder.description && (
                    <p className="md:col-span-2">
                        <span className="text-surface-500 dark:text-surface-400">
                            Description:{' '}
                        </span>
                        {workOrder.description}
                    </p>
                )}
                {workOrder.result && (
                    <p className="md:col-span-2">
                        <span className="text-surface-500 dark:text-surface-400">Result: </span>
                        {workOrder.result}
                    </p>
                )}
                {workOrder.rejection_reason && (
                    <p className="md:col-span-2">
                        <span className="text-surface-500 dark:text-surface-400">Rejection: </span>
                        {workOrder.rejection_reason}
                    </p>
                )}
            </CardContent>
        </Card>
    );
}

export function WorkOrderLifecycleActionsCard({
    status,
    onAction,
}: {
    status: string;
    onAction: (
        action: 'generate' | 'assign' | 'start' | 'submit' | 'approve' | 'reject' | 'cancel',
    ) => void;
}) {
    return (
        <Card>
            <CardHeader>
                <CardTitle>Lifecycle Actions</CardTitle>
            </CardHeader>
            <CardContent>
                <div className="flex flex-wrap gap-2">
                    {status === 'draft' && (
                        <Button onClick={() => onAction('generate')}>Generate</Button>
                    )}
                    {(status === 'draft' || status === 'generated' || status === 'rejected') && (
                        <Button onClick={() => onAction('assign')}>Assign</Button>
                    )}
                    {status === 'assigned' && (
                        <Button onClick={() => onAction('start')}>Start</Button>
                    )}
                    {status === 'in_progress' && (
                        <Button onClick={() => onAction('submit')}>Submit for Review</Button>
                    )}
                    {status === 'waiting_review' && (
                        <Button onClick={() => onAction('approve')}>Approve</Button>
                    )}
                    {status === 'waiting_review' && (
                        <Button variant="danger" onClick={() => onAction('reject')}>
                            Reject
                        </Button>
                    )}
                    {!['completed', 'cancelled'].includes(status) && (
                        <Button variant="outline" onClick={() => onAction('cancel')}>
                            Cancel
                        </Button>
                    )}
                </div>
            </CardContent>
        </Card>
    );
}

export function WorkOrderItemsCard({ items }: { items?: WorkOrderData['items'] }) {
    return (
        <Card>
            <CardHeader className="flex-row items-center justify-between space-y-0">
                <CardTitle>Items</CardTitle>
            </CardHeader>
            <CardContent>
                <Table>
                    <THead>
                        <TR>
                            <TH>Product</TH>
                            <TH>Unit</TH>
                            <TH>Reserved</TH>
                            <TH>Used</TH>
                            <TH>Note</TH>
                        </TR>
                    </THead>
                    <TBody>
                        {(items ?? []).length === 0 ? (
                            <TR>
                                <TD colSpan={5} className="text-center text-surface-500">
                                    No items.
                                </TD>
                            </TR>
                        ) : (
                            (items ?? []).map((item) => (
                                <TR key={item.id}>
                                    <TD>
                                        {item.product
                                            ? `${item.product.sku} - ${item.product.name}`
                                            : `#${item.product_id}`}
                                    </TD>
                                    <TD>
                                        {item.product?.unit?.symbol ??
                                            item.product?.unit?.name ??
                                            '-'}
                                    </TD>
                                    <TD>{item.quantity_reserved}</TD>
                                    <TD>{item.quantity_used}</TD>
                                    <TD>{item.note ?? '-'}</TD>
                                </TR>
                            ))
                        )}
                    </TBody>
                </Table>
            </CardContent>
        </Card>
    );
}

export function WorkOrderEvidenceCard({
    evidence,
    evidenceFile,
    setEvidenceFile,
    evidenceCaption,
    setEvidenceCaption,
    onUpload,
}: {
    evidence?: WorkOrderData['evidence'];
    evidenceFile: File | null;
    setEvidenceFile: (file: File | null) => void;
    evidenceCaption: string;
    setEvidenceCaption: (value: string) => void;
    onUpload: (event: FormEvent) => void;
}) {
    return (
        <Card>
            <CardHeader>
                <CardTitle>Evidence</CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">
                <form
                    onSubmit={onUpload}
                    className="grid gap-3 lg:grid-cols-[1fr_1fr_auto] lg:items-start"
                >
                    <FileUpload
                        label="Evidence file"
                        value={evidenceFile}
                        onChange={setEvidenceFile}
                        acceptedFileTypes={[
                            'image/jpeg',
                            'image/png',
                            'application/pdf',
                            'application/msword',
                            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                        ]}
                        required
                    />
                    <Input
                        label="Caption"
                        value={evidenceCaption}
                        onChange={(event) => setEvidenceCaption(event.target.value)}
                        placeholder="Optional caption"
                    />
                    <div className="self-end">
                        <Button type="submit" disabled={!evidenceFile}>
                            Upload
                        </Button>
                    </div>
                </form>
                <div className="grid gap-2 sm:grid-cols-2 md:grid-cols-3">
                    {(evidence ?? []).map((item) => (
                        <div
                            key={item.id}
                            className="rounded-lg border border-surface-200 p-3 dark:border-surface-800"
                        >
                            <p className="text-sm font-medium">
                                {item.type === 'photo' ? 'Photo' : 'Document'}
                            </p>
                            <p className="text-xs text-surface-500">
                                {item.caption ?? item.file_path}
                            </p>
                            <p className="text-xs text-surface-400">{item.uploaded_at}</p>
                        </div>
                    ))}
                </div>
            </CardContent>
        </Card>
    );
}

export function WorkOrderAssignmentHistoryCard({
    assignments,
}: {
    assignments?: WorkOrderData['assignments'];
}) {
    return (
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
                        {(assignments ?? []).length === 0 ? (
                            <TR>
                                <TD colSpan={3} className="text-center text-surface-500">
                                    No assignments.
                                </TD>
                            </TR>
                        ) : (
                            (assignments ?? []).map((assignment) => (
                                <TR key={assignment.id}>
                                    <TD>{assignment.technician?.name ?? '-'}</TD>
                                    <TD className="text-sm">{assignment.assigned_at}</TD>
                                    <TD className="text-sm">{assignment.unassigned_at ?? '—'}</TD>
                                </TR>
                            ))
                        )}
                    </TBody>
                </Table>
            </CardContent>
        </Card>
    );
}

function modalTitle(value: string) {
    return value.replace(/([A-Z])/g, ' $1').replace(/^./, (str) => str.toUpperCase());
}

export function WorkOrderActionModal({
    action,
    open,
    onClose,
    data,
    setData,
    errors,
    processing,
    onSubmit,
    technicianOptions,
    productOptions,
    selectedUnit,
}: {
    action:
        | 'generate'
        | 'assign'
        | 'start'
        | 'submit'
        | 'approve'
        | 'reject'
        | 'cancel'
        | 'addItem'
        | null;
    open: boolean;
    onClose: () => void;
    data: {
        technician_id: string;
        reason: string;
        product_id: string;
        quantity_reserved: string;
        quantity_used: string;
        note: string;
    };
    setData: (field: string, value: string) => void;
    errors: Record<string, string | undefined>;
    processing: boolean;
    onSubmit: (event: FormEvent) => void;
    technicianOptions: WorkOrderOption[];
    productOptions: WorkOrderOption[];
    selectedUnit: string | null;
}) {
    if (!open || !action) return null;

    return (
        <Modal open={open} onClose={onClose} title={modalTitle(action)}>
            <form onSubmit={onSubmit} className="space-y-4">
                {action === 'assign' && (
                    <SearchSelect
                        label="Technician"
                        value={data.technician_id}
                        onChange={(value) => setData('technician_id', value)}
                        options={technicianOptions}
                        placeholder="Search technician employee"
                        emptyText="No technician employees found."
                        required
                    />
                )}
                {['reject', 'cancel'].includes(action) && (
                    <Input
                        label="Reason"
                        value={data.reason}
                        onChange={(event) => setData('reason', event.target.value)}
                        required
                    />
                )}
                {action === 'addItem' && (
                    <>
                        <SearchSelect
                            label="Product"
                            value={data.product_id}
                            onChange={(value) => setData('product_id', value)}
                            options={productOptions}
                            placeholder="Search product"
                            emptyText="No active products found."
                            error={errors.product_id}
                            required
                        />
                        <Input
                            label={
                                selectedUnit
                                    ? `Quantity Reserved - ${selectedUnit}`
                                    : 'Quantity Reserved'
                            }
                            type="number"
                            step="0.01"
                            value={data.quantity_reserved}
                            onChange={(event) => setData('quantity_reserved', event.target.value)}
                            error={errors.quantity_reserved}
                        />
                        <Input
                            label={
                                selectedUnit ? `Quantity Used - ${selectedUnit}` : 'Quantity Used'
                            }
                            type="number"
                            step="0.01"
                            value={data.quantity_used}
                            onChange={(event) => setData('quantity_used', event.target.value)}
                            error={errors.quantity_used}
                        />
                        <Input
                            label="Note"
                            value={data.note}
                            onChange={(event) => setData('note', event.target.value)}
                            error={errors.note}
                        />
                    </>
                )}
                <div className="flex justify-end gap-2">
                    <Button type="button" variant="secondary" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button type="submit" loading={processing}>
                        Confirm
                    </Button>
                </div>
            </form>
        </Modal>
    );
}
