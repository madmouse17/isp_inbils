import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

export interface ConfirmDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title?: string;
    description?: string;
    children?: ReactNode;
    className?: string;
}

export function ConfirmDialog({
    open,
    onOpenChange: _onOpenChange,
    title,
    description,
    children,
    className,
}: ConfirmDialogProps) {
    return (
        <div
            className={cn(
                'fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4',
                className,
            )}
            data-open={open}
        >
            <div className="w-full max-w-md rounded-lg border bg-card p-6 shadow-lg">
                {title && <h3 className="mb-2 text-lg font-semibold">{title}</h3>}
                {description && <p className="mb-4 text-sm text-muted-foreground">{description}</p>}
                {children}
            </div>
        </div>
    );
}
