import { useLayoutEffect, useRef } from 'react';
import { cn } from '@/lib/utils';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from './dialog';

const sizes = {
    sm: 'max-w-sm',
    md: 'max-w-md',
    lg: 'max-w-lg',
    xl: 'max-w-xl',
} as const;

interface ModalProps {
    open: boolean;
    onClose: () => void;
    title?: string;
    size?: keyof typeof sizes;
    children: React.ReactNode;
}

/** A titled panel: the short form of the Dialog parts, which hold the one implementation (focus trap, Escape, overlay). */
export function Modal({ open, onClose, title, size = 'md', children }: ModalProps) {
    // Radix hands focus back only to a Dialog.Trigger. A Modal opens from page state, so it remembers what had focus when it
    // opened: before the content mounts, because content with `autoFocus` has taken the focus by then.
    const opener = useRef<HTMLElement | null>(null);
    useLayoutEffect(() => {
        if (open) opener.current = document.activeElement as HTMLElement | null;
    }, [open]);

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                if (!next) onClose();
            }}
        >
            <DialogContent
                aria-describedby={undefined}
                onCloseAutoFocus={(event) => {
                    event.preventDefault();
                    opener.current?.focus();
                }}
                className={cn('max-h-[calc(100dvh-2rem)] overflow-y-auto', sizes[size])}
            >
                <DialogHeader>
                    <DialogTitle className={cn(!title && 'sr-only')}>
                        {title ?? 'Dialog'}
                    </DialogTitle>
                </DialogHeader>
                <div>{children}</div>
            </DialogContent>
        </Dialog>
    );
}
