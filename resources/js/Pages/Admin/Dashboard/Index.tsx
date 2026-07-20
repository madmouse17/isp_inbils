import { Link } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Card, CardContent, CardHeader, CardTitle, StatCard } from '@/Components/ui';
import { PageHeader, StatusBadge } from '@/Components/composite';
import { formatDate } from '@/lib/format';
import type { Company } from '@/types';

interface ActivityItem {
    id: number;
    log_name: string;
    description: string;
    created_at: string | null;
}

interface DashboardModule {
    name: string;
    href: string;
    count: number;
}

interface DashboardProps {
    company: Company | null;
    userCount: number;
    roleCount: number;
    activeUserCount: number;
    permissionCount: number;
    recentActivity: ActivityItem[];
    modules: DashboardModule[];
}

export default function Index({
    company,
    userCount,
    roleCount,
    activeUserCount,
    permissionCount,
    recentActivity,
    modules,
}: DashboardProps) {
    return (
        <AdminLayout title="Dashboard">
            <div className="space-y-6">
                <PageHeader
                    title="Dashboard"
                    subtitle="Monitor users, modules, and recent activity."
                />

                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <StatCard label="Users" value={userCount} />
                    <StatCard label="Active Users" value={activeUserCount} accent="success" />
                    <StatCard label="Roles" value={roleCount} accent="warning" />
                    <StatCard label="Permissions" value={permissionCount} />
                </div>

                <div className="grid gap-6 xl:grid-cols-[1fr_1.5fr]">
                    <Card>
                        <CardHeader>
                            <CardTitle>Company</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3 text-sm">
                            <InfoRow label="Name" value={company?.name ?? '-'} />
                            <InfoRow label="Code" value={company?.code ?? '-'} />
                            <InfoRow label="Currency" value={company?.currency ?? '-'} />
                            <InfoRow label="Timezone" value={company?.timezone ?? '-'} />
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Recent Activity</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            {recentActivity.length === 0 ? (
                                <p className="text-sm text-surface-500 dark:text-surface-400">
                                    No activity yet.
                                </p>
                            ) : (
                                recentActivity.map((activity) => (
                                    <div
                                        key={activity.id}
                                        className="flex flex-col gap-2 rounded-lg border border-surface-200 p-3 dark:border-surface-800 sm:flex-row sm:items-center sm:justify-between"
                                    >
                                        <div>
                                            <p className="text-sm font-medium text-surface-900 dark:text-surface-100">
                                                {activity.description}
                                            </p>
                                            <p className="text-xs text-surface-500 dark:text-surface-400">
                                                {formatDate(activity.created_at, company)}
                                            </p>
                                        </div>
                                        <StatusBadge variant="info">
                                            {activity.log_name}
                                        </StatusBadge>
                                    </div>
                                ))
                            )}
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Modules</CardTitle>
                    </CardHeader>
                    <CardContent className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                        {modules.map((module) => (
                            <Link
                                key={module.name}
                                href={module.href}
                                className="rounded-xl border border-surface-200 p-4 transition hover:border-brand-300 hover:bg-brand-50/50 focus:outline-none focus:ring-2 focus:ring-brand-500/40 dark:border-surface-800 dark:hover:border-brand-700 dark:hover:bg-brand-950/30"
                            >
                                <div className="flex items-center justify-between gap-3">
                                    <h3 className="font-semibold text-surface-900 dark:text-surface-100">
                                        {module.name}
                                    </h3>
                                    <StatusBadge variant="muted">{module.count}</StatusBadge>
                                </div>
                                <p className="mt-2 text-sm text-surface-500 dark:text-surface-400">
                                    Open module
                                </p>
                            </Link>
                        ))}
                    </CardContent>
                </Card>
            </div>
        </AdminLayout>
    );
}

function InfoRow({ label, value }: { label: string; value: string }) {
    return (
        <div className="flex items-center justify-between gap-4">
            <span className="text-surface-500 dark:text-surface-400">{label}</span>
            <span className="font-medium text-surface-900 dark:text-surface-100">{value}</span>
        </div>
    );
}
