import { PageHeader } from '@/Components/composite';
import { ResourceEmpty } from '@/Components/composite/ResourceState';
import AdminLayout from '@/Layouts/AdminLayout';
import { formatNumber } from '@/lib/format';
import type { DashboardGroup, DashboardProps, DashboardSection } from '@/types/dashboard';
import { AdminOverview } from './components/AdminOverview';
import { DashboardRegion } from './components/DashboardRegion';
import { NotificationsCard } from './components/NotificationsCard';
import { SectionCard } from './components/SectionCard';
import { ToneBadge } from './components/ToneBadge';

const groupTitles: Record<DashboardGroup, string> = {
    operations: 'Operasional',
    approvals: 'Persetujuan',
    warehouse: 'Gudang',
    finance: 'Keuangan',
};

/** Buckets sections by group, in the order the server sent them. */
function groupSections(sections: DashboardSection[]) {
    const groups = new Map<DashboardGroup, DashboardSection[]>();

    for (const section of sections) {
        groups.set(section.group, [...(groups.get(section.group) ?? []), section]);
    }

    return [...groups];
}

export default function Index({ company, sections, notifications, admin }: DashboardProps) {
    const groups = groupSections(sections);
    const { unreadCount } = notifications;

    return (
        <AdminLayout title="Dasbor">
            <div className="space-y-6">
                <PageHeader
                    title="Dasbor"
                    subtitle="Antrean dan pengecualian yang perlu Anda tangani."
                />

                <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_22rem] xl:items-start">
                    <div className="space-y-6 min-w-0">
                        {groups.length === 0 ? (
                            <ResourceEmpty
                                title="Belum ada antrean untuk akun Anda"
                                description="Peran Anda belum memiliki akses ke antrean atau pengecualian operasional. Hubungi administrator untuk menambah izin."
                            />
                        ) : (
                            groups.map(([group, items]) => (
                                <DashboardRegion
                                    key={group}
                                    id={`dashboard-${group}`}
                                    title={groupTitles[group]}
                                >
                                    <div className="flex flex-wrap gap-4">
                                        {items.map((section) => (
                                            <SectionCard
                                                key={section.key}
                                                section={section}
                                                company={company}
                                                className={
                                                    section.key === 'sla_breached'
                                                        ? 'basis-full'
                                                        : 'grow basis-60'
                                                }
                                            />
                                        ))}
                                    </div>
                                </DashboardRegion>
                            ))
                        )}
                    </div>

                    <DashboardRegion
                        id="dashboard-notifications"
                        title="Notifikasi"
                        meta={
                            unreadCount > 0 ? (
                                <ToneBadge tone="info">
                                    {formatNumber(unreadCount)} belum dibaca
                                </ToneBadge>
                            ) : null
                        }
                    >
                        <NotificationsCard items={notifications.data} />
                    </DashboardRegion>
                </div>

                {admin && (
                    <DashboardRegion id="dashboard-admin" title="Administrasi">
                        <AdminOverview admin={admin} company={company} />
                    </DashboardRegion>
                )}
            </div>
        </AdminLayout>
    );
}
