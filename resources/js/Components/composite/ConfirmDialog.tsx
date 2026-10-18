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

interface ConfirmDialogProps {
    open: boolean;
    title: string;
    description: string;
    confirmLabel: string;
    /** `danger` for actions that cannot be taken back; the confirm button then names the object. */
    tone?: 'primary' | 'danger';
    processing?: boolean;
    /** The server's refusal, shown in the dialog so it stays next to the choice. */
    error?: string;
    onConfirm: () => void;
    onClose: () => void;
}

/**
 * One confirmation step for a status change or a one-way action. The caller closes it from the
 * request's `onSuccess`, so a refused request leaves the dialog open and its page banner readable.
 */
export function ConfirmDialog({
    open,
    title,
    description,
    confirmLabel,
    tone = 'primary',
    processing = false,
    error,
    onConfirm,
    onClose,
}: ConfirmDialogProps) {
    return (
        <AlertDialog
            open={open}
            onOpenChange={(next) => {
                if (!next && !processing) onClose();
            }}
        >
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>{title}</AlertDialogTitle>
                    <AlertDialogDescription>{description}</AlertDialogDescription>
                </AlertDialogHeader>
                {error ? (
                    <Alert variant="danger">
                        <AlertDescription className="text-destructive-text">
                            {error}
                        </AlertDescription>
                    </Alert>
                ) : null}
                <AlertDialogFooter>
                    <AlertDialogCancel disabled={processing}>Batal</AlertDialogCancel>
                    <Button
                        type="button"
                        variant={tone === 'danger' ? 'danger' : 'default'}
                        loading={processing}
                        onClick={onConfirm}
                    >
                        {confirmLabel}
                    </Button>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}
