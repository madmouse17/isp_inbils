import { router } from '@inertiajs/react';
import { BellRing } from 'lucide-react';
import { LoadingState, PageHeader } from '@/Components/composite';
import { EmptyState } from '@/Components/ui/EmptyState';
import { Badge, Button, Card, CardContent } from '@/Components/ui';
import { Pagination } from '@/Components/ui/Pagination';
import AdminLayout from '@/Layouts/AdminLayout';
import { useVisitPending } from '@/hooks/useVisitPending';
import type { Paginated } from '@/types';

interface NotificationItem {
    id: string;
    type: string;
    title: string;
    message: string;
    created_at: string | null;
    read_at: string | null;
    detail_url: string | null;
}

interface NotificationIndexProps extends Record<string, unknown> {
    notifications: Paginated<NotificationItem> & { unreadCount: number };
}

function formatDate(value: string | null) {
    if (!value) return '';
    return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(
        new Date(value),
    );
}

export default function NotificationIndex({ notifications }: NotificationIndexProps) {
    const { pending, callbacks } = useVisitPending();

    return (
        <AdminLayout title="Notifications">
            <div className="space-y-6">
                <PageHeader
                    title="Notifications"
                    subtitle="Your latest account and operational notifications"
                    actions={
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() =>
                                router.patch(route('admin.notifications.read-all'), undefined, {
                                    preserveScroll: true,
                                })
                            }
                            disabled={notifications.unreadCount === 0}
                        >
                            Mark all as read
                        </Button>
                    }
                />

                <Card>
                    <CardContent className="p-0">
                        {pending ? (
                            <LoadingState variant="rows" className="p-4 sm:p-6" />
                        ) : notifications.data.length === 0 ? (
                            <EmptyState
                                icon={<BellRing className="h-10 w-10" />}
                                title="No notifications"
                                description="You are all caught up."
                            />
                        ) : (
                            <div className="divide-y divide-border">
                                {notifications.data.map((notification) => (
                                    <article
                                        key={notification.id}
                                        className={`flex flex-col gap-3 p-4 sm:flex-row sm:items-start sm:justify-between sm:p-6 ${notification.read_at ? '' : 'bg-muted/30'}`}
                                    >
                                        <div className="space-y-1 min-w-0">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <h2 className="font-semibold text-foreground">
                                                    {notification.title}
                                                </h2>
                                                {!notification.read_at && <Badge>Unread</Badge>}
                                            </div>
                                            <p className="text-sm text-muted-foreground">
                                                {notification.message}
                                            </p>
                                            <p className="text-xs text-muted-foreground">
                                                {formatDate(notification.created_at)}
                                            </p>
                                        </div>
                                        <div className="flex shrink-0 flex-wrap items-center gap-2">
                                            {notification.detail_url && (
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    size="sm"
                                                    onClick={() => {
                                                        const url = notification.detail_url!;
                                                        if (!notification.read_at) {
                                                            router.patch(
                                                                route('admin.notifications.read', {
                                                                    notification: notification.id,
                                                                }),
                                                                undefined,
                                                                {
                                                                    preserveScroll: true,
                                                                    onSuccess: () =>
                                                                        router.get(url),
                                                                },
                                                            );
                                                        } else {
                                                            router.get(url);
                                                        }
                                                    }}
                                                >
                                                    View details
                                                </Button>
                                            )}
                                            {!notification.read_at && (
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() =>
                                                        router.patch(
                                                            route('admin.notifications.read', {
                                                                notification: notification.id,
                                                            }),
                                                            undefined,
                                                            { preserveScroll: true },
                                                        )
                                                    }
                                                >
                                                    Mark read
                                                </Button>
                                            )}
                                        </div>
                                    </article>
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>

                <Pagination
                    meta={notifications.meta}
                    disabled={pending}
                    onPageChange={(page) =>
                        router.get(
                            route('admin.notifications.index'),
                            { page },
                            { preserveState: true, preserveScroll: true, ...callbacks },
                        )
                    }
                />
            </div>
        </AdminLayout>
    );
}
