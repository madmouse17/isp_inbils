import { useLayoutEffect, useRef } from 'react';
import type { ReactNode } from 'react';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/Components/ui/dialog';

interface EditorDialogProps {
    title: string;
    description: string;
    onClose: () => void;
    /** The phone preview: beside the form on a wide screen, under it on a narrow one. */
    preview: ReactNode;
    children: ReactNode;
}

/**
 * What saving a row means for the customer app, by the status the row has now. A new row starts as a
 * draft; once published, a change is live at once.
 */
export function saveNote(status: string | undefined): string {
    switch (status) {
        case undefined:
        case 'draft':
            return 'Disimpan sebagai draf. Terbitkan dari daftar setelah dicek di pratinjau.';
        case 'scheduled':
        case 'live':
            return 'Sudah terbit, jadi perubahan langsung berlaku di aplikasi pelanggan.';
        case 'ended':
            return 'Periodenya sudah berakhir. Ubah tanggal selesai agar tampil lagi.';
        default:
            return 'Sudah dinonaktifkan: tidak tampil di aplikasi dan tidak bisa diaktifkan lagi.';
    }
}

/** A wide dialog with the form on the left and the phone preview that follows it on the right. Mount it only while open. */
export function EditorDialog({
    title,
    description,
    onClose,
    preview,
    children,
}: EditorDialogProps) {
    // `Modal` hands focus back the same way: this opens from page state, not from a Dialog.Trigger.
    const opener = useRef<HTMLElement | null>(null);
    useLayoutEffect(() => {
        opener.current = document.activeElement as HTMLElement | null;
    }, []);

    return (
        <Dialog
            open
            onOpenChange={(open) => {
                if (!open) onClose();
            }}
        >
            <DialogContent
                className="max-h-[calc(100dvh-2rem)] max-w-5xl overflow-y-auto"
                onCloseAutoFocus={(event) => {
                    event.preventDefault();
                    opener.current?.focus();
                }}
            >
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    <DialogDescription>{description}</DialogDescription>
                </DialogHeader>
                <div className="flex flex-col gap-6 lg:flex-row">
                    <div className="min-w-0 flex-1">{children}</div>
                    <div className="shrink-0 lg:sticky lg:top-0 lg:self-start">
                        <h3 className="mb-3 text-center text-sm font-medium">
                            Pratinjau di aplikasi pelanggan
                        </h3>
                        {preview}
                    </div>
                </div>
            </DialogContent>
        </Dialog>
    );
}
