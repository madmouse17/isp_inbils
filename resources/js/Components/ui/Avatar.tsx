import { cn } from '@/lib/utils';

const sizes = { sm: 'h-8 w-8 text-xs', md: 'h-10 w-10 text-sm', lg: 'h-12 w-12 text-base' } as const;

const statusColors = { online: 'bg-success', offline: 'bg-surface-400' } as const;

interface AvatarProps {
    src?: string;
    name?: string;
    size?: keyof typeof sizes;
    status?: keyof typeof statusColors;
    className?: string;
}

export function Avatar({ src, name, size = 'md', status, className }: AvatarProps) {
    const initials = name
        ? name.split(' ').map((w) => w[0]).slice(0, 2).join('').toUpperCase()
        : '';

    return (
        <span className={cn('relative inline-flex shrink-0', className)}>
            <span
                className={cn(
                    'inline-flex items-center justify-center rounded-full overflow-hidden bg-surface-200 dark:bg-surface-700',
                    sizes[size],
                )}
            >
                {src ? (
                    <img src={src} alt={name || ''} className="h-full w-full object-cover" />
                ) : (
                    <span className="font-medium text-surface-600 dark:text-surface-300">{initials}</span>
                )}
            </span>
            {status && (
                <span
                    className={cn(
                        'absolute bottom-0 right-0 block rounded-full ring-2 ring-white dark:ring-surface-900',
                        statusColors[status],
                        size === 'sm' ? 'h-2 w-2' : 'h-2.5 w-2.5',
                    )}
                />
            )}
        </span>
    );
}
