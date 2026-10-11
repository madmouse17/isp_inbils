import { router, usePage } from '@inertiajs/react';
import { BellIcon } from '@heroicons/react/24/outline';
import { Button } from '@/Components/ui';
import type { PageProps } from '@/types';

export function NotificationBell() {
    const { props } = usePage<PageProps>();
    const unreadCount = props.notifications?.unreadCount ?? 0;

    return (
        <Button
            type="button"
            variant="ghost"
            size="icon"
            aria-label={unreadCount > 0 ? `Notifications, ${unreadCount} unread` : 'Notifications'}
            onClick={() => router.get(route('admin.notifications.index'))}
            className="relative shrink-0"
        >
            <BellIcon className="h-5 w-5" />
            {unreadCount > 0 && (
                <span className="absolute -right-0.5 -top-0.5 flex min-h-4 min-w-4 items-center justify-center rounded-full bg-destructive px-1 text-[10px] font-semibold leading-4 text-destructive-foreground">
                    {unreadCount > 99 ? '99+' : unreadCount}
                </span>
            )}
        </Button>
    );
}
