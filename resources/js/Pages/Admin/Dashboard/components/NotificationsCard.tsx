import { Link } from '@inertiajs/react';
import { Card } from '@/Components/ui';
import { formatDateTime } from '@/lib/format';
import type { DashboardNotification } from '@/types/dashboard';
import { ToneBadge } from './ToneBadge';

interface NotificationsCardProps {
    items: DashboardNotification[];
}

export function NotificationsCard({ items }: NotificationsCardProps) {
    return (
        <Card>
            {items.length === 0 ? (
                <p className="p-5 text-sm text-muted-foreground">Belum ada notifikasi.</p>
            ) : (
                <ul className="divide-y divide-border">
                    {items.map((item) => (
                        <li key={item.id} className="space-y-1 p-4">
                            <div className="flex flex-wrap items-center gap-2">
                                <p className="text-sm font-medium text-foreground">{item.title}</p>
                                {!item.read_at && <ToneBadge tone="info">Belum dibaca</ToneBadge>}
                            </div>
                            <p className="break-words text-sm text-muted-foreground">
                                {item.message}
                            </p>
                            <p className="text-xs text-muted-foreground">
                                {formatDateTime(item.created_at)}
                            </p>
                        </li>
                    ))}
                </ul>
            )}
            <Link
                href={route('admin.notifications.index')}
                className="flex min-h-11 items-center justify-center rounded-b-lg border-t border-border px-4 text-sm font-medium text-primary hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-primary"
            >
                Lihat semua notifikasi
            </Link>
        </Card>
    );
}
