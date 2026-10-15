import { Link } from '@inertiajs/react';
import { Badge, Card, CardContent, CardHeader, CardTitle, StatCard } from '@/Components/ui';
import { formatDateTime, formatNumber } from '@/lib/format';
import type { Company } from '@/types';
import type { DashboardAdminStats } from '@/types/dashboard';
import { activityText } from './activityText';

interface AdminOverviewProps {
    admin: DashboardAdminStats;
    company: Company | null;
}

export function AdminOverview({ admin, company }: AdminOverviewProps) {
    const companyRows = [
        { label: 'Nama', value: company?.name },
        { label: 'Kode', value: company?.code },
        { label: 'Mata uang', value: company?.currency },
        { label: 'Zona waktu', value: company?.timezone },
    ];

    return (
        <div className="space-y-6">
            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard label="Pengguna" value={formatNumber(admin.userCount)} />
                <StatCard label="Pengguna aktif" value={formatNumber(admin.activeUserCount)} />
                <StatCard label="Peran" value={formatNumber(admin.roleCount)} />
                <StatCard label="Izin akses" value={formatNumber(admin.permissionCount)} />
            </div>

            <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_minmax(0,1.5fr)]">
                <Card>
                    <CardHeader>
                        <CardTitle>Perusahaan</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <dl className="space-y-3 text-sm">
                            {companyRows.map(({ label, value }) => (
                                <div
                                    key={label}
                                    className="flex items-center justify-between gap-4"
                                >
                                    <dt className="text-muted-foreground">{label}</dt>
                                    <dd className="min-w-0 text-right font-medium wrap-break-word text-foreground">
                                        {value ?? '-'}
                                    </dd>
                                </div>
                            ))}
                        </dl>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Aktivitas terbaru</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {admin.recentActivity.length === 0 ? (
                            <p className="text-sm text-muted-foreground">Belum ada aktivitas.</p>
                        ) : (
                            <ul className="space-y-3">
                                {admin.recentActivity.map((activity) => (
                                    <li
                                        key={activity.id}
                                        className="rounded-lg border border-border p-3"
                                    >
                                        <p className="text-sm font-medium wrap-break-word text-foreground">
                                            {activityText(activity)}
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            {formatDateTime(activity.created_at, company)}
                                        </p>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </CardContent>
                </Card>
            </div>

            {admin.modules.length > 0 && (
                <Card>
                    <CardHeader>
                        <CardTitle>Modul</CardTitle>
                    </CardHeader>
                    <CardContent className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                        {admin.modules.map((module) => (
                            <Link
                                key={module.name}
                                href={module.href}
                                className="block rounded-lg border border-border p-4 transition-colors hover:bg-accent/30 focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-hidden motion-reduce:transition-none"
                            >
                                <div className="flex items-center justify-between gap-3">
                                    <span className="font-semibold text-foreground">
                                        {module.name}
                                    </span>
                                    <Badge variant="secondary">{formatNumber(module.count)}</Badge>
                                </div>
                                <p className="mt-2 text-sm text-muted-foreground">Buka modul</p>
                            </Link>
                        ))}
                    </CardContent>
                </Card>
            )}
        </div>
    );
}
