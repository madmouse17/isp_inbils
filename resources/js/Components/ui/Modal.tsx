import { Dialog, DialogPanel, DialogBackdrop, DialogTitle } from '@headlessui/react';
import { XMarkIcon } from '@heroicons/react/20/solid';
import { cn } from '@/lib/utils';

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

export function Modal({ open, onClose, title, size = 'md', children }: ModalProps) {
    return (
        <Dialog open={open} onClose={onClose} className="relative z-50">
            <DialogBackdrop
                transition
                className="fixed inset-0 bg-black/50 transition-opacity data-[closed]:opacity-0 data-[enter]:duration-200 data-[leave]:duration-150"
            />
            <div className="fixed inset-0 flex items-center justify-center p-4 overflow-y-auto">
                <DialogPanel
                    transition
                    className={cn(
                        'w-full rounded-lg border bg-card text-card-foreground shadow-xl transition-transform data-[closed]:scale-95 data-[enter]:duration-200 data-[leave]:duration-150',
                        sizes[size],
                    )}
                >
                    {title && (
                        <div className="flex items-center justify-between border-b border-border px-5 py-4">
                            <DialogTitle className="font-semibold text-foreground">{title}</DialogTitle>
                            <button
                                onClick={onClose}
                                className="rounded-md p-1 text-muted-foreground hover:text-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                                aria-label="Close"
                            >
                                <XMarkIcon className="h-5 w-5" />
                            </button>
                        </div>
                    )}
                    <div className="px-5 py-4">{children}</div>
                </DialogPanel>
            </div>
        </Dialog>
    );
}
