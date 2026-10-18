import { router } from '@inertiajs/react';
import { BellRing } from 'lucide-react';
import { LoadingState, PageHeader } from '@/Components/composite';
import { EmptyState } from '@/Components/ui/EmptyState';
import { Button, Card, CardContent } from '@/Components/ui';
import { Pagination } from '@/Components/ui/Pagination';
import AdminLayout from '@/Layouts/AdminLayout';
import { useCompany } from '@/hooks/useCompany';
import { useVisitPending } from '@/hooks/useVisitPending';
import { formatDateTime } from '@/lib/format';
import { cn } from '@/lib/utils';
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

export default function NotificationIndex({ notifications }: NotificationIndexProps) {
    const company = useCompany();
    const { pending, callbacks } = useVisitPending();

    return (
        <AdminLayout title="Notifikasi">
            <div className="space-y-6">
                <PageHeader
                    title="Notifikasi"
                    subtitle="Pemberitahuan akun dan operasional terbaru untuk Anda."
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
                            Tandai semua dibaca
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
                                title="Belum ada notifikasi"
                                description="Pemberitahuan baru untuk akun Anda muncul di sini."
                            />
                        ) : (
                            <div className="divide-y divide-border">
                                {notifications.data.map((notification) => (
                                    <article
                                        key={notification.id}
                                        className={cn(
                                            'flex flex-col gap-3 p-4 sm:flex-row sm:items-start sm:justify-between sm:p-6',
                                            !notification.read_at && 'bg-muted/30',
                                        )}
                                    >
                                        <div className="space-y-1 min-w-0">
                                            <h2
                                                className={cn(
                                                    'text-foreground',
                                                    notification.read_at
                                                        ? 'font-normal'
                                                        : 'font-semibold',
                                                )}
                                            >
                                                {!notification.read_at && (
                                                    <span className="sr-only">Belum dibaca: </span>
                                                )}
                                                {notification.title}
                                            </h2>
                                            <p className="text-sm text-muted-foreground">
                                                {notification.message}
                                            </p>
                                            <p className="text-xs text-muted-foreground">
                                                {formatDateTime(notification.created_at, company)}
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
                                                    Lihat detail
                                                </Button>
                                            )}
                                            {!notification.read_at && (
                                                <Button
                                                    type="button"
                                                    variant="outline"
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
                                                    Tandai dibaca
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
