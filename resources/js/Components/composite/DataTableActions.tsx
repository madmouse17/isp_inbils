import { Link, router } from '@inertiajs/react';
import { MoreHorizontal } from 'lucide-react';
import { useRef, useState } from 'react';
import { Button, buttonVariants } from '@/Components/ui/Button';
import { IconButton } from '@/Components/ui/IconButton';
import { Spinner } from '@/Components/ui/Spinner';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/Components/ui/dropdown-menu';
import { ConfirmDialog } from '@/Components/composite/ConfirmDialog';
import { formatNumber } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { LifecycleFields } from '@/types/models';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/Components/ui/alert-dialog';

export interface LifecycleActionsProp {
    row: { id: string } & LifecycleFields;
    /** The row's display name, quoted in the dialog text. */
    name: string;
    /** Sentence-case noun, for example `Profil bandwidth`. */
    noun: string;
    /** `admin.bandwidth-profiles`: `.deactivate`, `.activate` and `.restore` (POST), `.destroy` (DELETE). */
    routeBase: string;
    /** Undefined means allowed; the server still checks. */
    can?: { update?: boolean; delete?: boolean };
}

type LifecycleAction = 'deactivate' | 'activate' | 'restore' | 'destroy';

const ACTION_LABEL: Record<LifecycleAction, string> = {
    deactivate: 'Nonaktifkan',
    activate: 'Aktifkan',
    restore: 'Pulihkan',
    destroy: 'Hapus permanen',
};

/** What a row offers: a trashed row only restores; a master deactivates or deletes by its reference counts. */
function lifecycleActionsOf({ row, can }: LifecycleActionsProp): LifecycleAction[] {
    const mayUpdate = can?.update !== false;
    const mayDelete = can?.delete !== false;

    if (row.deleted_at) return mayDelete ? ['restore'] : [];
    if (!Array.isArray(row.references)) return [];

    const actions: LifecycleAction[] = [];
    if (mayUpdate && row.is_active === true) actions.push('deactivate');
    if (mayUpdate && row.is_active === false) actions.push('activate');
    if (mayDelete && row.references.every((reference) => reference.count === 0)) {
        actions.push('destroy');
    }

    return actions;
}

function dialogCopy(action: LifecycleAction, { row, name, noun }: LifecycleActionsProp) {
    const refs = (row.references ?? [])
        .filter((reference) => reference.count > 0)
        .map((reference) => `${formatNumber(reference.count)} ${reference.label}`)
        .join(', ');

    const description = {
        deactivate: refs
            ? `${noun} "${name}" dipakai ${refs}, jadi tidak bisa dihapus permanen. Setelah dinonaktifkan, data ini tidak muncul di pilihan baru dan riwayatnya tetap utuh.`
            : `Setelah dinonaktifkan, ${noun} "${name}" tidak muncul di pilihan baru. Riwayatnya tetap utuh.`,
        activate: `${noun} "${name}" kembali muncul di pilihan baru.`,
        restore: `${noun} "${name}" kembali muncul di daftar dengan status sebelumnya.`,
        destroy: `${noun} "${name}" belum pernah dipakai data lain. Penghapusan permanen tidak bisa dibatalkan.`,
    }[action];

    return {
        title: `${ACTION_LABEL[action]} ${name}?`,
        description,
        confirmLabel: ACTION_LABEL[action],
        tone: action === 'destroy' ? ('danger' as const) : ('primary' as const),
    };
}

/**
 * One confirmation for the four lifecycle actions. It owns the request: it stays open while the
 * request runs, closes on success only, and shows the server's refusal (`errors.lifecycle`) in place.
 * Mount it only while an action is pending, keyed by the action, so each starts without the last refusal.
 */
function LifecycleDialog({
    action,
    lifecycle,
    onClose,
}: {
    action: LifecycleAction;
    lifecycle: LifecycleActionsProp;
    onClose: () => void;
}) {
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState<string>();
    // Synchronous guard against a same-tick double-click, as in the delete dialog.
    const inFlight = useRef(false);

    const confirm = () => {
        if (inFlight.current) return;

        inFlight.current = true;
        setProcessing(true);
        setError(undefined);

        const url = route(`${lifecycle.routeBase}.${action}`, lifecycle.row.id);
        const options = {
            preserveScroll: true,
            onFinish: () => {
                inFlight.current = false;
                setProcessing(false);
            },
            onSuccess: onClose,
            onError: (errors: Record<string, string>) =>
                setError(errors.lifecycle ?? Object.values(errors)[0]),
        };

        if (action === 'destroy') router.delete(url, options);
        else router.post(url, {}, options);
    };

    return (
        <ConfirmDialog
            open
            {...dialogCopy(action, lifecycle)}
            processing={processing}
            error={error}
            onConfirm={confirm}
            onClose={onClose}
        />
    );
}

export interface DataTableActionsProps {
    showHref?: string;
    onShow?: () => void;
    editHref?: string;
    onEdit?: () => void;
    deleteHref?: string;
    deleteMessage?: string;
    deleteDescription?: string;
    onDeleteSuccess?: () => void;
    /**
     * Master lifecycle (Nonaktifkan, Aktifkan, Pulihkan, Hapus permanen). A trashed row drops the
     * Ubah, Lihat and Hapus entries, because their routes answer 404 for it.
     */
    lifecycle?: LifecycleActionsProp;
}

/**
 * Canonical row actions: one ghost button for the main action (Ubah, or Lihat when the row cannot
 * be edited) and an overflow menu for the rest (Lihat detail, Hapus). The row's name column links
 * to the detail page, so the row stays one line and carries one accent. Delete lives only in the
 * menu and always confirms through an AlertDialog, never window.confirm.
 *
 * Lifecycle contract: the delete request is OWNED by this component. The
 * dialog stays open and Batal/Hapus stay disabled while the request is
 * in flight; it only closes on success (via onDeleteSuccess the consumer can
 * sync any client-side state) and stays open on error so the user can retry.
 * Consumers MUST pass deleteHref (the route): there is no onDelete prop,
 * because a fire-and-forget callback cannot own the request lifecycle.
 */
export function DataTableActions({
    showHref,
    onShow,
    editHref,
    onEdit,
    deleteHref,
    deleteMessage = 'Hapus data ini?',
    deleteDescription,
    onDeleteSuccess,
    lifecycle,
}: DataTableActionsProps) {
    const [pendingAction, setPendingAction] = useState<LifecycleAction | null>(null);
    const trashed = Boolean(lifecycle?.row.deleted_at);
    const lifecycleActions = lifecycle ? lifecycleActionsOf(lifecycle) : [];
    const [confirmOpen, setConfirmOpen] = useState(false);
    const [deleteProcessing, setDeleteProcessing] = useState(false);
    // Synchronous in-flight guard: prevents double-submit from a same-tick
    // double-click before React re-renders the disabled state.
    const deleteInFlight = useRef(false);

    const confirmDelete = () => {
        if (!deleteHref || deleteInFlight.current) return;

        deleteInFlight.current = true;
        setDeleteProcessing(true);

        router.delete(deleteHref, {
            preserveScroll: true,
            onFinish: () => {
                deleteInFlight.current = false;
                setDeleteProcessing(false);
            },
            onSuccess: () => {
                setConfirmOpen(false);
                onDeleteSuccess?.();
            },
        });
    };

    const hasShow = !trashed && Boolean(showHref || onShow);
    const hasEdit = !trashed && Boolean(editHref || onEdit);
    const hasDelete = !trashed && Boolean(deleteHref);
    // The detail link moves into the menu when an edit button takes the visible slot.
    const showInMenu = hasShow && hasEdit;
    const mainHref = hasEdit ? editHref : showHref;
    const mainClick = hasEdit ? onEdit : onShow;
    const mainLabel = hasEdit ? 'Ubah' : 'Lihat';

    return (
        <div className="flex items-center gap-1">
            {hasEdit || hasShow ? (
                mainHref ? (
                    <Button asChild variant="outline" size="sm">
                        <Link href={mainHref}>{mainLabel}</Link>
                    </Button>
                ) : (
                    <Button type="button" variant="outline" size="sm" onClick={mainClick}>
                        {mainLabel}
                    </Button>
                )
            ) : null}
            {showInMenu || hasDelete || lifecycleActions.length > 0 ? (
                <>
                    {/* Not modal: a modal menu and the confirm dialog would both lock the page and leave it locked. */}
                    <DropdownMenu modal={false}>
                        <DropdownMenuTrigger asChild>
                            <IconButton label="Aksi lainnya" variant="ghost" size="sm">
                                <MoreHorizontal className="h-4 w-4" aria-hidden="true" />
                            </IconButton>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                            {showInMenu ? (
                                showHref ? (
                                    <DropdownMenuItem asChild>
                                        <Link href={showHref}>Lihat detail</Link>
                                    </DropdownMenuItem>
                                ) : (
                                    <DropdownMenuItem onSelect={onShow}>
                                        Lihat detail
                                    </DropdownMenuItem>
                                )
                            ) : null}
                            {lifecycleActions.map((action) => (
                                <DropdownMenuItem
                                    key={action}
                                    destructive={action === 'destroy'}
                                    onSelect={() => setPendingAction(action)}
                                >
                                    {ACTION_LABEL[action]}
                                </DropdownMenuItem>
                            ))}
                            {hasDelete ? (
                                <DropdownMenuItem destructive onSelect={() => setConfirmOpen(true)}>
                                    Hapus
                                </DropdownMenuItem>
                            ) : null}
                        </DropdownMenuContent>
                    </DropdownMenu>
                    {hasDelete ? (
                        <AlertDialog
                            open={confirmOpen}
                            onOpenChange={(open) => {
                                // While the request is in flight the dialog must
                                // stay open: only the success path (or a user
                                // cancel when idle) may close it.
                                if (deleteInFlight.current && !open) return;
                                setConfirmOpen(open);
                            }}
                        >
                            <AlertDialogContent>
                                <AlertDialogHeader>
                                    <AlertDialogTitle>{deleteMessage}</AlertDialogTitle>
                                    {deleteDescription ? (
                                        <AlertDialogDescription>
                                            {deleteDescription}
                                        </AlertDialogDescription>
                                    ) : null}
                                </AlertDialogHeader>
                                <AlertDialogFooter>
                                    <AlertDialogCancel disabled={deleteProcessing}>
                                        Batal
                                    </AlertDialogCancel>
                                    <AlertDialogAction
                                        className={cn(
                                            buttonVariants({ variant: 'destructive' }),
                                            'gap-2',
                                        )}
                                        onClick={(event) => {
                                            event.preventDefault();
                                            confirmDelete();
                                        }}
                                        disabled={deleteProcessing}
                                    >
                                        {deleteProcessing ? <Spinner size="sm" /> : null}
                                        Hapus
                                    </AlertDialogAction>
                                </AlertDialogFooter>
                            </AlertDialogContent>
                        </AlertDialog>
                    ) : null}
                    {lifecycle && pendingAction ? (
                        <LifecycleDialog
                            key={pendingAction}
                            action={pendingAction}
                            lifecycle={lifecycle}
                            onClose={() => setPendingAction(null)}
                        />
                    ) : null}
                </>
            ) : null}
        </div>
    );
}
