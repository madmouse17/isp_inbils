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

interface ConfirmDescriptionProps {
    description: string;
    /** What the action does, one line each, read together with the description. */
    details?: readonly string[];
}

/**
 * The description of a confirmation and, under it, the lines naming what the action does. Both sit in one
 * description region, so a screen reader reads them together; the list never nests inside a paragraph.
 */
export function ConfirmDescription({ description, details }: ConfirmDescriptionProps) {
    if (!details?.length) {
        return <AlertDialogDescription>{description}</AlertDialogDescription>;
    }

    return (
        <AlertDialogDescription asChild>
            <div className="space-y-2">
                <p>{description}</p>
                <ul className="space-y-1 list-disc pl-5 text-left">
                    {details.map((line) => (
                        <li key={line}>{line}</li>
                    ))}
                </ul>
            </div>
        </AlertDialogDescription>
    );
}

interface ConfirmDialogProps extends ConfirmDescriptionProps {
    open: boolean;
    title: string;
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
    details,
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
                    <ConfirmDescription description={description} details={details} />
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
