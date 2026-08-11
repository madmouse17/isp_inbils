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
    Textarea,
} from '@/Components/ui';
import { NativeSelect, StatusBadge } from '@/Components/composite';

export interface TicketData {
    id: number;
    code: string;
    title: string;
    description?: string | null;
    source: string;
    status: string;
    priority: string;
    is_sla_breached: boolean;
    sla_deadline?: string | null;
    first_response_at?: string | null;
    resolved_at?: string | null;
    closed_at?: string | null;
    resolution_note?: string | null;
    category?: { name: string; code: string } | null;
    customer?: { name: string; code: string } | null;
    subscription?: { code: string } | null;
    network_asset?: { code: string; name: string } | null;
    location?: { name: string; path?: string } | null;
    assignee?: { name: string } | null;
    comments?: {
        id: number;
        body: string;
        is_internal: boolean;
        created_at: string;
        author?: { name: string } | null;
    }[];
    attachments?: {
        id: number;
        file_path: string;
        original_name?: string | null;
        created_at: string;
    }[];
}

export function TicketDetailsCard({ ticket }: { ticket: TicketData }) {
    return (
        <Card>
            <CardHeader>
                <CardTitle>Details</CardTitle>
            </CardHeader>
            <CardContent className="space-y-3 text-sm">
                <p>
                    <span className="text-muted-foreground">Category: </span>
                    {ticket.category?.name ?? '-'}
                </p>
                <p>
                    <span className="text-muted-foreground">Source: </span>
                    {ticket.source}
                </p>
                <p>
                    <span className="text-muted-foreground">Customer: </span>
                    {ticket.customer?.name ?? '-'}
                </p>
                <p>
                    <span className="text-muted-foreground">Subscription: </span>
                    {ticket.subscription?.code ?? '-'}
                </p>
                <p>
                    <span className="text-muted-foreground">Asset: </span>
                    {ticket.network_asset?.name ?? '-'}
                </p>
                <p>
                    <span className="text-muted-foreground">Location: </span>
                    {ticket.location?.name ?? '-'}
                </p>
                <p>
                    <span className="text-muted-foreground">Handler: </span>
                    {ticket.assignee?.name ?? '-'}
                </p>
                {ticket.description && (
                    <p>
                        <span className="text-muted-foreground">Description: </span>
                        {ticket.description}
                    </p>
                )}
                {ticket.resolution_note && (
                    <p>
                        <span className="text-muted-foreground">Resolution: </span>
                        {ticket.resolution_note}
                    </p>
                )}
            </CardContent>
        </Card>
    );
}

export function TicketStatusCard({ ticket }: { ticket: TicketData }) {
    return (
        <Card>
            <CardHeader>
                <CardTitle>Status</CardTitle>
            </CardHeader>
            <CardContent className="space-y-3 text-sm">
                <p>
                    <span className="text-muted-foreground">Code: </span>
                    {ticket.code}
                </p>
                <p>
                    <span className="text-muted-foreground">Status: </span>
                    <StatusBadge variant={statusVariant(ticket.status)}>
                        {ticket.status}
                    </StatusBadge>
                </p>
                <p>
                    <span className="text-muted-foreground">Priority: </span>
                    <Badge
                        variant={
                            ticket.priority === 'urgent'
                                ? 'danger'
                                : ticket.priority === 'high'
                                  ? 'brand'
                                  : 'neutral'
                        }
                    >
                        {ticket.priority}
                    </Badge>
                </p>
                {ticket.is_sla_breached && (
                    <p>
                        <Badge variant="danger">SLA Breached</Badge>
                    </p>
                )}
                <p>
                    <span className="text-muted-foreground">SLA Deadline: </span>
                    {ticket.sla_deadline ?? '-'}
                </p>
                <p>
                    <span className="text-muted-foreground">First Response: </span>
                    {ticket.first_response_at ?? '-'}
                </p>
                <p>
                    <span className="text-muted-foreground">Resolved: </span>
                    {ticket.resolved_at ?? '-'}
                </p>
                <p>
                    <span className="text-muted-foreground">Closed: </span>
                    {ticket.closed_at ?? '-'}
                </p>
            </CardContent>
        </Card>
    );
}

export function TicketActionsCard({
    ticket,
    onAction,
}: {
    ticket: TicketData;
    onAction: (action: 'assign' | 'resolve' | 'close' | 'spawnSpk' | 'comment') => void;
}) {
    return (
        <Card>
            <CardHeader>
                <CardTitle>Actions</CardTitle>
            </CardHeader>
            <CardContent>
                <div className="flex flex-wrap gap-2">
                    {ticket.status === 'open' && (
                        <Button type="button" onClick={() => onAction('assign')}>
                            Assign
                        </Button>
                    )}
                    {(ticket.status === 'open' || ticket.status === 'assigned') && (
                        <Button type="button" onClick={() => onAction('resolve')}>
                            Resolve
                        </Button>
                    )}
                    {ticket.status === 'resolved' && (
                        <Button type="button" onClick={() => onAction('close')}>
                            Close
                        </Button>
                    )}
                    {(ticket.status === 'on_progress' || ticket.status === 'assigned') &&
                        !ticket.resolution_note && (
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => onAction('spawnSpk')}
                            >
                                Spawn SPK
                            </Button>
                        )}
                    <Button type="button" variant="secondary" onClick={() => onAction('comment')}>
                        Add Comment
                    </Button>
                </div>
            </CardContent>
        </Card>
    );
}

export function TicketCommentsCard({ comments }: { comments?: TicketData['comments'] }) {
    return (
        <Card>
            <CardHeader>
                <CardTitle>Comments</CardTitle>
            </CardHeader>
            <CardContent className="space-y-3">
                {(comments ?? []).length === 0 && (
                    <p className="text-sm text-muted-foreground">No comments.</p>
                )}
                {(comments ?? []).map((comment) => (
                    <div key={comment.id} className="rounded-lg border border-border p-3">
                        <div className="flex items-center justify-between">
                            <p className="text-sm font-medium">
                                {comment.author?.name ?? 'Unknown'}
                            </p>
                            {comment.is_internal && <Badge variant="brand">Internal</Badge>}
                        </div>
                        <p className="mt-1 text-sm text-muted-foreground">{comment.body}</p>
                        <p className="mt-1 text-xs text-muted-foreground">{comment.created_at}</p>
                    </div>
                ))}
            </CardContent>
        </Card>
    );
}

export function TicketAttachmentsCard({
    attachments,
    attachmentFile,
    setAttachmentFile,
    onUpload,
}: {
    attachments?: TicketData['attachments'];
    attachmentFile: File | null;
    setAttachmentFile: (file: File | null) => void;
    onUpload: (event: FormEvent) => void;
}) {
    return (
        <Card>
            <CardHeader>
                <CardTitle>Attachments</CardTitle>
            </CardHeader>
            <CardContent className="space-y-3">
                <form
                    onSubmit={onUpload}
                    className="grid gap-3 sm:grid-cols-[1fr_auto] sm:items-start"
                >
                    <FileUpload
                        label="Attachment"
                        value={attachmentFile}
                        onChange={setAttachmentFile}
                        acceptedFileTypes={[
                            'image/jpeg',
                            'image/png',
                            'application/pdf',
                            'text/plain',
                            'application/msword',
                            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                        ]}
                        required
                    />
                    <div className="self-end">
                        <Button type="submit" disabled={!attachmentFile}>
                            Upload
                        </Button>
                    </div>
                </form>
                <div className="grid gap-2 sm:grid-cols-2">
                    {(attachments ?? []).map((attachment) => (
                        <div key={attachment.id} className="rounded-lg border border-border p-3">
                            <p className="text-sm font-medium">
                                {attachment.original_name ?? attachment.file_path}
                            </p>
                            <p className="text-xs text-muted-foreground">{attachment.created_at}</p>
                        </div>
                    ))}
                </div>
            </CardContent>
        </Card>
    );
}

export function TicketActionModal({
    action,
    open,
    onClose,
    data,
    setData,
    processing,
    onSubmit,
}: {
    action: 'assign' | 'resolve' | 'close' | 'spawnSpk' | 'comment' | null;
    open: boolean;
    onClose: () => void;
    data: { handler_id: string; resolution_note: string; body: string; is_internal: boolean };
    setData: (field: string, value: string | boolean) => void;
    processing: boolean;
    onSubmit: (event: FormEvent) => void;
}) {
    if (!open || !action) return null;

    return (
        <Modal open={open} onClose={onClose} title={modalTitle(action)}>
            <form onSubmit={onSubmit} className="space-y-4">
                {action === 'assign' && (
                    <Input
                        label="Handler ID"
                        value={data.handler_id}
                        onChange={(event) => setData('handler_id', event.target.value)}
                        required
                    />
                )}
                {action === 'resolve' && (
                    <Textarea
                        label="Resolution Note"
                        value={data.resolution_note}
                        onChange={(event) => setData('resolution_note', event.target.value)}
                        required
                        rows={3}
                    />
                )}
                {action === 'comment' && (
                    <>
                        <Textarea
                            label="Comment"
                            value={data.body}
                            onChange={(event) => setData('body', event.target.value)}
                            required
                            rows={3}
                        />
                        <NativeSelect
                            label="Visibility"
                            value={data.is_internal ? 'internal' : 'public'}
                            onChange={(event) =>
                                setData('is_internal', event.target.value === 'internal')
                            }
                        >
                            <option value="public">Public</option>
                            <option value="internal">Internal</option>
                        </NativeSelect>
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

function modalTitle(value: string) {
    return value.replace(/([A-Z])/g, ' $1').replace(/^./, (str) => str.toUpperCase());
}

function statusVariant(status: string): 'success' | 'warning' | 'danger' | 'muted' | 'info' {
    return status === 'closed'
        ? 'muted'
        : status === 'resolved'
          ? 'success'
          : status === 'on_progress'
            ? 'info'
            : status === 'assigned'
              ? 'warning'
              : 'danger';
}
