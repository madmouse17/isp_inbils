import AdminLayout from '@/Layouts/AdminLayout';
import { MetricDefinitionDetails, PageHeader } from '@/Components/composite';
import { Card, CardContent, CardHeader, CardTitle, EmptyState } from '@/Components/ui';
import type { DictionaryReport } from '@/types';

interface Props extends Record<string, unknown> {
    reports: DictionaryReport[];
}

export default function Dictionary({ reports }: Props) {
    return (
        <AdminLayout title="Data Dictionary">
            <div className="space-y-6">
                <PageHeader
                    title="Data Dictionary"
                    subtitle="Definition, formula and source columns of every report figure. Read-only, and the same for every company."
                />
                {reports.length === 0 ? (
                    <EmptyState title="Dictionary is empty" />
                ) : (
                    <>
                        <p className="text-sm text-muted-foreground">
                            Figures are limited to your company. Soft-deleted records are not
                            counted unless a figure&apos;s known caveats say otherwise. Version v1
                            records each formula exactly as implemented today, known quirks
                            included.
                        </p>
                        <nav aria-label="Reports in this dictionary">
                            <ul className="flex flex-wrap gap-x-6 gap-y-2 text-sm">
                                {reports.map((report) => (
                                    <li key={report.key}>
                                        <a
                                            href={`#dictionary-${report.key}`}
                                            className="rounded-sm font-medium text-foreground underline underline-offset-2 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-hidden"
                                        >
                                            {report.title} ({report.metrics.length})
                                        </a>
                                    </li>
                                ))}
                            </ul>
                        </nav>
                        {reports.map((report) => (
                            <section
                                key={report.key}
                                id={`dictionary-${report.key}`}
                                aria-labelledby={`dictionary-${report.key}-heading`}
                                className="space-y-4 scroll-mt-4"
                            >
                                <div className="space-y-1">
                                    <h2
                                        id={`dictionary-${report.key}-heading`}
                                        className="text-xl font-semibold tracking-tight text-foreground"
                                    >
                                        {report.title}
                                    </h2>
                                    <p className="text-xs wrap-break-word text-muted-foreground">
                                        Formula version {report.formula_version ?? 'none'} ·
                                        Requires{' '}
                                        <code className="font-mono">{report.permission}</code>
                                    </p>
                                    <p className="text-sm text-muted-foreground">
                                        {report.description}
                                    </p>
                                </div>
                                <div className="grid gap-4 lg:grid-cols-2">
                                    {report.metrics.map((metric) => (
                                        <Card
                                            key={metric.key}
                                            role="article"
                                            aria-labelledby={`dictionary-${report.key}-${metric.key}-title`}
                                            className="min-w-0"
                                        >
                                            <CardHeader className="space-y-1">
                                                <CardTitle
                                                    as="h3"
                                                    id={`dictionary-${report.key}-${metric.key}-title`}
                                                    className="leading-tight wrap-break-word"
                                                >
                                                    {metric.label}
                                                </CardTitle>
                                                <p className="text-xs wrap-break-word text-muted-foreground">
                                                    <code className="font-mono">{metric.key}</code>{' '}
                                                    · {metric.version}
                                                </p>
                                            </CardHeader>
                                            <CardContent className="space-y-4 min-w-0">
                                                <p className="text-sm wrap-break-word text-foreground">
                                                    {metric.definition}
                                                </p>
                                                <MetricDefinitionDetails definition={metric} />
                                            </CardContent>
                                        </Card>
                                    ))}
                                </div>
                            </section>
                        ))}
                    </>
                )}
            </div>
        </AdminLayout>
    );
}
