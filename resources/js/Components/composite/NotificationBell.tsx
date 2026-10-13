import { router, usePage } from '@inertiajs/react';
import { Bell } from 'lucide-react';
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
            aria-label={unreadCount > 0 ? `Notifikasi, ${unreadCount} belum dibaca` : 'Notifikasi'}
            onClick={() => router.get(route('admin.notifications.index'))}
            className="relative shrink-0"
        >
            <Bell className="h-5 w-5" aria-hidden="true" />
            {unreadCount > 0 && (
                // White on red-600 is 4.83:1; the label above already speaks the count.
                <span
                    aria-hidden="true"
                    className="absolute top-0 right-0 flex min-h-4 min-w-4 items-center justify-center rounded-full bg-red-600 px-1 text-xs leading-4 font-semibold text-white"
                >
                    {unreadCount > 99 ? '99+' : unreadCount}
                </span>
            )}
        </Button>
    );
}
