import { cn } from '@/lib/utils';
import { XMarkIcon, CheckCircleIcon, ExclamationTriangleIcon, XCircleIcon, InformationCircleIcon } from '@heroicons/react/20/solid';

const variants = {
    info: { bg: 'bg-blue-50 border-blue-200 dark:bg-blue-900/20 dark:border-blue-800', text: 'text-blue-700 dark:text-blue-300', Icon: InformationCircleIcon },
    success: { bg: 'bg-green-50 border-green-200 dark:bg-green-900/20 dark:border-green-800', text: 'text-green-700 dark:text-green-300', Icon: CheckCircleIcon },
    warning: { bg: 'bg-amber-50 border-amber-200 dark:bg-amber-900/20 dark:border-amber-800', text: 'text-amber-700 dark:text-amber-300', Icon: ExclamationTriangleIcon },
    danger: { bg: 'bg-red-50 border-red-200 dark:bg-red-900/20 dark:border-red-800', text: 'text-red-700 dark:text-red-300', Icon: XCircleIcon },
} as const;

interface AlertProps {
    variant?: keyof typeof variants;
    title?: string;
    children: React.ReactNode;
    onDismiss?: () => void;
    className?: string;
}

export function Alert({ variant = 'info', title, children, onDismiss, className }: AlertProps) {
    const { bg, text, Icon } = variants[variant];
    return (
        <div className={cn('flex gap-3 rounded-lg border p-4', bg, text, className)} role="alert">
            <Icon className="h-5 w-5 shrink-0 mt-0.5" />
            <div className="flex-1">
                {title && <p className="font-semibold">{title}</p>}
                <div className={cn('text-sm', title && 'mt-1')}>{children}</div>
            </div>
            {onDismiss && (
                <button onClick={onDismiss} className={cn('shrink-0', text)} aria-label="Dismiss">
                    <XMarkIcon className="h-5 w-5" />
                </button>
            )}
        </div>
    );
}
