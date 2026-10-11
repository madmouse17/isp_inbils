import { Link, router } from '@inertiajs/react';
import { useRef, useState } from 'react';
import { Button, buttonVariants } from '@/Components/ui/Button';
import { Spinner } from '@/Components/ui/Spinner';
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
    onDeleteSuccess?: () => void;
}

/**
 * Canonical row-action group: Show (brand), Edit (warning), Delete (danger).
 * Delete always confirms through an AlertDialog — never window.confirm.
 *
 * Lifecycle contract: the delete request is OWNED by this component. The
 * dialog stays open and Cancel/Delete stay disabled while the request is
 * in flight; it only closes on success (via onDeleteSuccess the consumer can
 * sync any client-side state) and stays open on error so the user can retry.
 * Consumers MUST pass deleteHref (the route) — there is no onDelete prop:
 * a fire-and-forget callback cannot own the request lifecycle.
 */
export function DataTableActions({
    showHref,
    onShow,
    editHref,
    onEdit,
    deleteHref,
    deleteMessage = 'Delete this item?',
    deleteDescription,
    onDeleteSuccess,
}: DataTableActionsProps) {
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

    return (
        <div className="flex flex-wrap gap-1">
            {showHref ? (
                <Button asChild variant="primary" size="sm">
                    <Link href={showHref}>Show</Link>
                </Button>
            ) : onShow ? (
                <Button type="button" variant="primary" size="sm" onClick={onShow}>
                    Show
                </Button>
            ) : null}
            {editHref ? (
                <Button asChild variant="warning" size="sm">
                    <Link href={editHref}>Edit</Link>
                </Button>
            ) : onEdit ? (
                <Button type="button" variant="warning" size="sm" onClick={onEdit}>
                    Edit
                </Button>
            ) : null}
            {deleteHref ? (
                <>
                    <Button
                        type="button"
                        variant="danger"
                        size="sm"
                        onClick={() => setConfirmOpen(true)}
                    >
                        Delete
                    </Button>
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
                                    Cancel
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
                                    Delete
                                </AlertDialogAction>
                            </AlertDialogFooter>
                        </AlertDialogContent>
                    </AlertDialog>
                </>
            ) : null}
        </div>
    );
}
