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

export interface ActionRequest {
    title: string;
    description: string;
    confirmLabel: string;
    href: string;
    method: 'post' | 'delete';
    /** The action cannot be taken back: the confirm button turns red. */
    destructive?: boolean;
}

interface ActionDialogProps {
    request: ActionRequest;
    onClose: () => void;
}

/**
 * Confirms publish, deactivate or delete of a row and keeps the dialog open when the server refuses,
 * showing its message (a banner without an image cannot be published). `ConfirmRequestDialog` does
 * the same for DELETE only. Mount it only while a request is pending, keyed by `request.href`.
 */
export function ActionDialog({ request, onClose }: ActionDialogProps) {
    const form = useForm({});
    const refusal = Object.values(form.errors as Record<string, string | undefined>).find(Boolean);

    const confirm = () =>
        form[request.method](request.href, { preserveScroll: true, onSuccess: onClose });

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
                        <AlertDescription className="text-destructive-text">
                            {refusal}
                        </AlertDescription>
                    </Alert>
                ) : null}
                <AlertDialogFooter>
                    <AlertDialogCancel disabled={form.processing}>Batal</AlertDialogCancel>
                    <Button
                        type="button"
                        variant={request.destructive ? 'danger' : 'primary'}
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
