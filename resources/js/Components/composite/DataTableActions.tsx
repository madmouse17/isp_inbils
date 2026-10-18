import { Link, router } from '@inertiajs/react';
import { MoreHorizontal } from 'lucide-react';
import { useRef, useState } from 'react';
import { Button, buttonVariants } from '@/Components/ui/Button';
import { IconButton } from '@/Components/ui/IconButton';
import { Input } from '@/Components/ui/Input';
import { Spinner } from '@/Components/ui/Spinner';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/Components/ui/dropdown-menu';
import { cn } from '@/lib/utils';
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

export interface DataTableActionsProps {
    showHref?: string;
    onShow?: () => void;
    editHref?: string;
    onEdit?: () => void;
    deleteHref?: string;
    deleteMessage?: string;
    deleteDescription?: string;
    /** The row's code; when set, Hapus stays disabled until the user types it. */
    deleteConfirmCode?: string;
    onDeleteSuccess?: () => void;
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
    deleteConfirmCode,
    onDeleteSuccess,
}: DataTableActionsProps) {
    const [confirmOpen, setConfirmOpen] = useState(false);
    const [typedCode, setTypedCode] = useState('');
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
                setTypedCode('');
                onDeleteSuccess?.();
            },
        });
    };

    const hasShow = Boolean(showHref || onShow);
    const hasEdit = Boolean(editHref || onEdit);
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
            {showInMenu || deleteHref ? (
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
                            {deleteHref ? (
                                <DropdownMenuItem destructive onSelect={() => setConfirmOpen(true)}>
                                    Hapus
                                </DropdownMenuItem>
                            ) : null}
                        </DropdownMenuContent>
                    </DropdownMenu>
                    {deleteHref ? (
                        <AlertDialog
                            open={confirmOpen}
                            onOpenChange={(open) => {
                                // While the request is in flight the dialog must
                                // stay open: only the success path (or a user
                                // cancel when idle) may close it.
                                if (deleteInFlight.current && !open) return;
                                setConfirmOpen(open);
                                if (!open) setTypedCode('');
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
                                {deleteConfirmCode ? (
                                    <Input
                                        label={`Ketik ${deleteConfirmCode} untuk menghapus`}
                                        value={typedCode}
                                        onChange={(event) => setTypedCode(event.target.value)}
                                        autoComplete="off"
                                    />
                                ) : null}
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
                                        disabled={
                                            deleteProcessing ||
                                            (deleteConfirmCode !== undefined &&
                                                typedCode.trim() !== deleteConfirmCode)
                                        }
                                    >
                                        {deleteProcessing ? <Spinner size="sm" /> : null}
                                        Hapus
                                    </AlertDialogAction>
                                </AlertDialogFooter>
                            </AlertDialogContent>
                        </AlertDialog>
                    ) : null}
                </>
            ) : null}
        </div>
    );
}
