import AdminLayout from '@/Layouts/AdminLayout';
import { PageHeader } from '@/Components/composite';
import { EmptyState } from '@/Components/ui';
import { Link, usePage } from '@inertiajs/react';
import type { PageProps, ReportHubCard } from '@/types';

interface Props extends Record<string, unknown> {
    reports: ReportHubCard[];
}

/** The registry has no entry for it: the server flags a Head the user may consolidate, and the card follows. */
function withConsolidatedCard(reports: ReportHubCard[]): ReportHubCard[] {
    const afterBusiness = reports.findIndex((report) => report.key === 'business') + 1;

    return [
        ...reports.slice(0, afterBusiness),
        {
            key: 'consolidated',
            title: 'Metrik Konsolidasi',
            description:
                'Metrik bisnis perusahaan ini dan anak perusahaannya, per perusahaan dan total.',
            href: route('admin.reports.consolidated'),
        },
        ...reports.slice(afterBusiness),
    ];
}

export default function Index({ reports }: Props) {
    const canConsolidate = usePage<PageProps>().props.companyHierarchy?.can_consolidate === true;
    const cards = canConsolidate ? withConsolidatedCard(reports) : reports;

    return (
        <AdminLayout title="Laporan">
            <div className="space-y-6">
                <PageHeader
                    title="Laporan"
                    subtitle="Laporan baca-saja yang dihitung langsung dari riwayat kerja."
                />
                {cards.length === 0 ? (
                    <EmptyState
                        title="Belum ada laporan yang bisa dibuka"
                        description="Peran Anda belum punya akses laporan. Minta administrator memberi akses."
                    />
                ) : (
                    <ul className="divide-y divide-border rounded-lg border border-border">
                        {cards.map((report) => (
                            <li key={report.key} className="px-4 py-3">
                                <Link
                                    href={report.href}
                                    className="rounded-sm font-medium text-foreground underline-offset-2 hover:underline focus-visible:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-hidden"
                                >
                                    {report.title}
                                </Link>
                                <p className="mt-0.5 text-sm text-muted-foreground">
                                    {report.description}
                                </p>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </AdminLayout>
    );
}
