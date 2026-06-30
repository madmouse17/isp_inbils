import { useState, useRef, useId } from 'react';
import { cn } from '@/lib/utils';

interface TooltipProps {
    label: string;
    children: React.ReactElement;
    side?: 'top' | 'bottom';
}

export function Tooltip({ label, children, side = 'top' }: TooltipProps) {
    const [visible, setVisible] = useState(false);
    const id = useId();
    const timeoutRef = useRef<ReturnType<typeof setTimeout> | null>(null);

    const show = () => {
        if (timeoutRef.current) clearTimeout(timeoutRef.current);
        setVisible(true);
    };
    const hide = () => {
        timeoutRef.current = setTimeout(() => setVisible(false), 100);
    };

    return (
        <span
            className="relative inline-flex"
            onMouseEnter={show}
            onMouseLeave={hide}
            onFocus={show}
            onBlur={hide}
        >
            {children}
            <span
                id={id}
                role="tooltip"
                className={cn(
                    'pointer-events-none absolute left-1/2 z-50 -translate-x-1/2 whitespace-nowrap rounded-md bg-surface-900 px-2.5 py-1 text-xs font-medium text-white shadow-sm dark:bg-surface-100 dark:text-surface-900',
                    side === 'top' ? 'bottom-full mb-2' : 'top-full mt-2',
                    visible ? 'opacity-100' : 'opacity-0',
                    'transition-opacity duration-150',
                )}
                aria-hidden={!visible}
            >
                {label}
            </span>
        </span>
    );
}
