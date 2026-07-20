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
    FileUpload,
    Input,
    Modal,
    Select,
    Textarea,
    useToast,
} from '@/Components/ui';
import { PageHeader, StatusBadge } from '@/Components/composite';

interface TData {
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

interface ShowProps extends Record<string, unknown> {
    ticket: { data: TData };
}

const statusVariant = (s: string): 'success' | 'warning' | 'danger' | 'muted' | 'info' =>
    s === 'closed'
        ? 'muted'
        : s === 'resolved'
          ? 'success'
          : s === 'on_progress'
            ? 'info'
            : s === 'assigned'
              ? 'warning'
              : 'danger';

export default function Show({ ticket }: ShowProps) {
    const t = ticket.data;
    const { toast } = useToast();
    const [actionModal, setActionModal] = useState<
        'assign' | 'resolve' | 'close' | 'spawnSpk' | 'comment' | null
    >(null);
    const [attachmentFile, setAttachmentFile] = useState<File | null>(null);
    const { data, setData, post, processing, reset } = useForm({
        handler_id: '',
        resolution_note: '',
        body: '',
        is_internal: false,
    });

    const doAction = (e: FormEvent) => {
        e.preventDefault();
        if (!actionModal) return;
        const routeMap: Record<string, string> = {
            assign: 'admin.tickets.assign',
            resolve: 'admin.tickets.resolve',
            close: 'admin.tickets.close',
            spawnSpk: 'admin.tickets.spawn-spk',
            comment: 'admin.tickets.comments.store',
        };
        post(route(routeMap[actionModal], t.id), {
            onSuccess: () => {
                setActionModal(null);
                reset();
            },
        });
    };

    const uploadAttachment = (e: FormEvent) => {
        e.preventDefault();
        if (!attachmentFile) return;

        const formData = new FormData();
        formData.append('file', attachmentFile);

        router.post(route('admin.tickets.attachments.store', t.id), formData, {
            forceFormData: true,
            onSuccess: () => {
                setAttachmentFile(null);
                toast({ title: 'Attachment uploaded.', variant: 'success' });
            },
            onError: () =>
                toast({
                    title: 'Failed to upload attachment.',
                    description: 'Check allowed file type and max 10 MB size.',
                    variant: 'danger',
                }),
        });
    };

    return (
        <AdminLayout title={t.title}>
            <div className="space-y-6">
                <PageHeader
                    title={t.title}
                    subtitle="Record details and related activity."
                    actions={
                        <Button
                            type="button"
                            variant="secondary"
                            onClick={() => router.get(route('admin.tickets.index'))}
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
                        <CardContent className="space-y-3 text-sm">
                            <p>
                                <span className="text-muted-foreground">Category: </span>
                                {t.category?.name ?? '-'}
                            </p>
                            <p>
                                <span className="text-muted-foreground">Source: </span>
                                {t.source}
                            </p>
                            <p>
                                <span className="text-muted-foreground">Customer: </span>
                                {t.customer?.name ?? '-'}
                            </p>
                            <p>
                                <span className="text-muted-foreground">Subscription: </span>
                                {t.subscription?.code ?? '-'}
                            </p>
                            <p>
                                <span className="text-muted-foreground">Asset: </span>
                                {t.network_asset?.name ?? '-'}
                            </p>
                            <p>
                                <span className="text-muted-foreground">Location: </span>
                                {t.location?.name ?? '-'}
                            </p>
                            <p>
                                <span className="text-muted-foreground">Handler: </span>
                                {t.assignee?.name ?? '-'}
                            </p>
                            {t.description && (
                                <p>
                                    <span className="text-muted-foreground">Description: </span>
                                    {t.description}
                                </p>
                            )}
                            {t.resolution_note && (
                                <p>
                                    <span className="text-muted-foreground">Resolution: </span>
                                    {t.resolution_note}
                                </p>
                            )}
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle>Status</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3 text-sm">
                            <p>
                                <span className="text-muted-foreground">Code: </span>
                                {t.code}
                            </p>
                            <p>
                                <span className="text-muted-foreground">Status: </span>
                                <StatusBadge variant={statusVariant(t.status)}>
                                    {t.status}
                                </StatusBadge>
                            </p>
                            <p>
                                <span className="text-muted-foreground">Priority: </span>
                                <Badge
                                    variant={
                                        t.priority === 'urgent'
                                            ? 'danger'
                                            : t.priority === 'high'
                                              ? 'brand'
                                              : 'neutral'
                                    }
                                >
                                    {t.priority}
                                </Badge>
                            </p>
                            {t.is_sla_breached && (
                                <p>
                                    <Badge variant="danger">SLA Breached</Badge>
                                </p>
                            )}
                            <p>
                                <span className="text-muted-foreground">SLA Deadline: </span>
                                {t.sla_deadline ?? '-'}
                            </p>
                            <p>
                                <span className="text-muted-foreground">First Response: </span>
                                {t.first_response_at ?? '-'}
                            </p>
                            <p>
                                <span className="text-muted-foreground">Resolved: </span>
                                {t.resolved_at ?? '-'}
                            </p>
                            <p>
                                <span className="text-muted-foreground">Closed: </span>
                                {t.closed_at ?? '-'}
                            </p>
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Actions</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="flex flex-wrap gap-2">
                            {t.status === 'open' && (
                                <Button type="button" onClick={() => setActionModal('assign')}>
                                    Assign
                                </Button>
                            )}
                            {(t.status === 'open' || t.status === 'assigned') && (
                                <Button
                                    type="button"
                                    onClick={() => router.post(route('admin.tickets.start', t.id))}
                                >
                                    Start
                                </Button>
                            )}
                            {(t.status === 'on_progress' || t.status === 'assigned') && (
                                <Button type="button" onClick={() => setActionModal('resolve')}>
                                    Resolve
                                </Button>
                            )}
                            {t.status === 'resolved' && (
                                <Button type="button" onClick={() => setActionModal('close')}>
                                    Close
                                </Button>
                            )}
                            {(t.status === 'on_progress' || t.status === 'assigned') &&
                                !t.resolution_note && (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() => setActionModal('spawnSpk')}
                                    >
                                        Spawn SPK
                                    </Button>
                                )}
                            <Button
                                type="button"
                                variant="secondary"
                                onClick={() => setActionModal('comment')}
                            >
                                Add Comment
                            </Button>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Comments</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        {(t.comments ?? []).length === 0 && (
                            <p className="text-sm text-muted-foreground">No comments.</p>
                        )}
                        {(t.comments ?? []).map((c) => (
                            <div key={c.id} className="rounded-lg border border-border p-3">
                                <div className="flex items-center justify-between">
                                    <p className="text-sm font-medium">
                                        {c.author?.name ?? 'Unknown'}
                                    </p>
                                    {c.is_internal && <Badge variant="brand">Internal</Badge>}
                                </div>
                                <p className="mt-1 text-sm text-muted-foreground">{c.body}</p>
                                <p className="mt-1 text-xs text-muted-foreground">{c.created_at}</p>
                            </div>
                        ))}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Attachments</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        <form onSubmit={uploadAttachment} className="grid gap-3 sm:grid-cols-[1fr_auto] sm:items-start">
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
                            {(t.attachments ?? []).map((a) => (
                                <div key={a.id} className="rounded-lg border border-border p-3">
                                    <p className="text-sm font-medium">
                                        {a.original_name ?? a.file_path}
                                    </p>
                                    <p className="text-xs text-muted-foreground">{a.created_at}</p>
                                </div>
                            ))}
                        </div>
                    </CardContent>
                </Card>

                <Modal
                    open={actionModal !== null}
                    onClose={() => setActionModal(null)}
                    title={
                        actionModal
                            ? actionModal
                                  .replace(/([A-Z])/g, ' $1')
                                  .replace(/^./, (str) => str.toUpperCase())
                            : ''
                    }
                >
                    <form onSubmit={doAction} className="space-y-4">
                        {actionModal === 'assign' && (
                            <Input
                                label="Handler ID"
                                value={data.handler_id}
                                onChange={(e) => setData('handler_id', e.target.value)}
                                required
                            />
                        )}
                        {actionModal === 'resolve' && (
                            <Textarea
                                label="Resolution Note"
                                value={data.resolution_note}
                                onChange={(e) => setData('resolution_note', e.target.value)}
                                required
                                rows={3}
                            />
                        )}
                        {actionModal === 'comment' && (
                            <>
                                <Textarea
                                    label="Comment"
                                    value={data.body}
                                    onChange={(e) => setData('body', e.target.value)}
                                    required
                                    rows={3}
                                />
                                <Select
                                    label="Visibility"
                                    value={data.is_internal ? 'internal' : 'public'}
                                    onChange={(e) =>
                                        setData('is_internal', e.target.value === 'internal')
                                    }
                                >
                                    <option value="public">Public</option>
                                    <option value="internal">Internal</option>
                                </Select>
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
