import type { FormEvent } from 'react';
import { useState } from 'react';
import { Link, router, useForm } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import {
    Alert,
    AlertDescription,
    AlertTitle,
    Badge,
    Button,
    Card,
    CardContent,
    CardHeader,
    CardTitle,
    FileUpload,
    Input,
    Modal,
    NativeSelect,
    Progress,
    Textarea,
    useToast,
} from '@/Components/ui';
import { MediaPreview, PageHeader, StatusBadge } from '@/Components/composite';

interface TData {
    id: number;
    code: string;
    title: string;
    description?: string | null;
    source: string;
    status: string;
    priority: string;
    assigned_to?: number | null;
    updated_at?: string | null;
    can?: { start: boolean; close: boolean };
    is_sla_breached: boolean;
    sla_deadline?: string | null;
    first_response_at?: string | null;
    resolved_at?: string | null;
    closed_at?: string | null;
    resolution_note?: string | null;
    check_in?: string | null;
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
        url: string;
        file_path: string;
        original_name?: string | null;
        mime_type?: string | null;
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
    const [uploading, setUploading] = useState(false);
    const [uploadPercentage, setUploadPercentage] = useState(0);
    const { data, setData, post, processing, reset, errors, clearErrors, transform } = useForm({
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
        // Optimistic-concurrency guard required by AssignTicketRequest.
        transform((payload) =>
            actionModal === 'assign'
                ? {
                      ...payload,
                      expected_assigned_to: t.assigned_to ?? null,
                      expected_updated_at: t.updated_at ?? null,
                  }
                : payload,
        );
        post(route(routeMap[actionModal], t.id), {
            onSuccess: closeModal,
        });
    };

    const closeModal = () => {
        setActionModal(null);
        clearErrors();
        reset();
    };

    const reloadTicket = () => router.reload({ only: ['ticket'], onSuccess: () => clearErrors() });

    const startTicket = () =>
        router.post(
            route('admin.tickets.start', t.id),
            {},
            {
                onError: (startErrors) =>
                    toast({
                        title: 'Failed to start ticket.',
                        description: Object.values(startErrors)[0],
                        variant: 'danger',
                    }),
            },
        );

    // Server-side keys (conflict, expected_*) are not part of the form's data shape.
    const serverErrors: Record<string, string | undefined> = errors;
    const staleError =
        serverErrors.conflict ??
        serverErrors.expected_assigned_to ??
        serverErrors.expected_updated_at;

    const uploadAttachment = (e: FormEvent) => {
        e.preventDefault();
        if (!attachmentFile || uploading) return;

        const formData = new FormData();
        formData.append('file', attachmentFile);

        setUploading(true);
        setUploadPercentage(0);
        router.post(route('admin.tickets.attachments.store', t.id), formData, {
            forceFormData: true,
            onProgress: (event) => setUploadPercentage(event?.percentage ?? 0),
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
            onFinish: () => setUploading(false),
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
                            asChild
                            variant="outline"
                            size="sm"
                            leftIcon={<ArrowLeft className="size-4" />}
                        >
                            <Link href={route('admin.tickets.index')}>Back</Link>
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
                                <span className="text-muted-foreground">Check-in: </span>
                                {t.check_in ?? 'no location'}
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
                            {t.can?.start && (
                                <Button type="button" onClick={startTicket}>
                                    Start
                                </Button>
                            )}
                            {(t.status === 'on_progress' || t.status === 'assigned') && (
                                <Button type="button" onClick={() => setActionModal('resolve')}>
                                    Resolve
                                </Button>
                            )}
                            {t.can?.close && (
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
                        <form
                            onSubmit={uploadAttachment}
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
                                <Button
                                    type="submit"
                                    disabled={!attachmentFile || uploading}
                                    loading={uploading}
                                >
                                    Upload
                                </Button>
                            </div>
                            {uploading && (
                                <Progress
                                    className="sm:col-span-2"
                                    label="Mengunggah lampiran"
                                    value={uploadPercentage}
                                />
                            )}
                        </form>
                        <div className="grid gap-2 sm:grid-cols-2">
                            {(t.attachments ?? []).map((a) => (
                                <div key={a.id} className="rounded-lg border border-border p-3">
                                    <p className="text-sm font-medium">
                                        {a.original_name ?? a.file_path}
                                    </p>
                                    <p className="text-xs text-muted-foreground">{a.created_at}</p>
                                    <MediaPreview media={a} />
                                </div>
                            ))}
                        </div>
                    </CardContent>
                </Card>

                <Modal
                    open={actionModal !== null}
                    onClose={closeModal}
                    title={
                        actionModal
                            ? actionModal
                                  .replace(/([A-Z])/g, ' $1')
                                  .replace(/^./, (str) => str.toUpperCase())
                            : ''
                    }
                >
                    <form onSubmit={doAction} className="space-y-4">
                        {staleError && (
                            <Alert variant="destructive">
                                <AlertTitle>Ticket changed</AlertTitle>
                                <AlertDescription className="space-y-2">
                                    <p>{staleError}</p>
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        onClick={reloadTicket}
                                    >
                                        Reload ticket
                                    </Button>
                                </AlertDescription>
                            </Alert>
                        )}
                        {actionModal === 'assign' && (
                            <Input
                                label="Handler ID"
                                value={data.handler_id}
                                onChange={(e) => setData('handler_id', e.target.value)}
                                error={errors.handler_id}
                                required
                            />
                        )}
                        {actionModal === 'resolve' && (
                            <Textarea
                                label="Resolution Note"
                                value={data.resolution_note}
                                onChange={(e) => setData('resolution_note', e.target.value)}
                                error={errors.resolution_note}
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
                                    error={errors.body}
                                    required
                                    rows={3}
                                />
                                <NativeSelect
                                    label="Visibility"
                                    value={data.is_internal ? 'internal' : 'public'}
                                    onChange={(e) =>
                                        setData('is_internal', e.target.value === 'internal')
                                    }
                                >
                                    <option value="public">Public</option>
                                    <option value="internal">Internal</option>
                                </NativeSelect>
                            </>
                        )}
                        <div className="flex justify-end gap-2">
                            <Button type="button" variant="secondary" onClick={closeModal}>
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
