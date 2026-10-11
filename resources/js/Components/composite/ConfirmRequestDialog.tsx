import { useForm } from '@inertiajs/react';
import { Alert, AlertDescription, Button } from '@/Components/ui';
import {
    AlertDialog,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/Components/ui/alert-dialog';

export interface ConfirmRequest {
    title: string;
    description: string;
    confirmLabel: string;
    /** DELETE endpoint of the action. */
    href: string;
}

interface ConfirmRequestDialogProps {
    request: ConfirmRequest;
    onClose: () => void;
}

/**
 * Confirms a destructive DELETE and keeps the dialog open when the server refuses it, showing the
 * server's message (a rule such as "still has addresses"). Mount it only while a request is pending,
 * keyed by `request.href`, so each confirmation starts without the previous refusal.
 */
export function ConfirmRequestDialog({ request, onClose }: ConfirmRequestDialogProps) {
    const form = useForm({});
    const refusal = Object.values(form.errors as Record<string, string | undefined>).find(Boolean);

    const confirm = () => form.delete(request.href, { preserveScroll: true, onSuccess: onClose });

    return (
        <AlertDialog
            open
            onOpenChange={(open) => {
                if (!open && !form.processing) onClose();
            }}
        >
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>{request.title}</AlertDialogTitle>
                    <AlertDialogDescription>{request.description}</AlertDialogDescription>
                </AlertDialogHeader>
                {refusal ? (
                    <Alert variant="danger">
                        <AlertDescription className="text-red-700 dark:text-red-400">
                            {refusal}
                        </AlertDescription>
                    </Alert>
                ) : null}
                <AlertDialogFooter>
                    <AlertDialogCancel disabled={form.processing}>Cancel</AlertDialogCancel>
                    <Button
                        type="button"
                        variant="danger"
                        loading={form.processing}
                        onClick={confirm}
                    >
                        {request.confirmLabel}
                    </Button>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}
