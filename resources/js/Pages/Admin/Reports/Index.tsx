import AdminLayout from '@/Layouts/AdminLayout';
import { PageHeader } from '@/Components/composite';
import { Button, Card, CardContent, CardHeader, CardTitle, EmptyState } from '@/Components/ui';
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
            title: 'Consolidated Metrics',
            description:
                'Business metrics of this company and its subsidiaries, per company and in total',
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
                    subtitle="Real-time read-only reports from workflow history."
                />
                {cards.length === 0 ? (
                    <EmptyState
                        title="No reports available"
                        description="Your role has no report access yet. Ask an administrator to grant one."
                    />
                ) : (
                    <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                        {cards.map((report) => (
                            <Card key={report.key}>
                                <CardContent>
                                    <CardHeader>
                                        <CardTitle>{report.title}</CardTitle>
                                    </CardHeader>
                                    <p className="text-sm text-surface-500 dark:text-surface-400">
                                        {report.description}
                                    </p>
                                    <Button asChild variant="outline" className="mt-4">
                                        <Link href={report.href}>Open {report.title}</Link>
                                    </Button>
                                </CardContent>
                            </Card>
                        ))}
                    </div>
                )}
            </div>
        </AdminLayout>
    );
}
