import { router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Badge, Button, Card, CardContent, CardHeader, CardTitle } from '@/Components/ui';
import { PageHeader } from '@/Components/composite';

interface EvalData {
    id: number;
    reference_type: string;
    reference_id: number;
    score: string;
    customer_rating?: string | null;
    first_response_minutes?: number | null;
    resolution_minutes?: number | null;
    comment?: string | null;
    evaluated_at: string;
    employee?: { name: string } | null;
    evaluator?: { name: string } | null;
}

interface ShowProps extends Record<string, unknown> {
    evaluation: { data: EvalData };
}

export default function Show({ evaluation }: ShowProps) {
    const ev = evaluation.data;

    return (
        <AdminLayout title="Evaluation Detail">
            <div className="space-y-6">
                <PageHeader
                    title="Evaluation"
                    subtitle={`${ev.reference_type}:${ev.reference_id} · ${ev.evaluated_at}`}
                    actions={
                        <>
                            <Button
                                type="button"
                                variant="secondary"
                                onClick={() => router.get(route('admin.evaluations.edit', ev.id))}
                            >
                                Edit
                            </Button>
                            <Button
                                type="button"
                                variant="secondary"
                                onClick={() => router.get(route('admin.evaluations.index'))}
                            >
                                Back
                            </Button>
                        </>
                    }
                />
                <div className="grid gap-6 xl:grid-cols-[2fr_1fr]">
                    <Card>
                        <CardHeader>
                            <CardTitle>Details</CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-4 text-sm md:grid-cols-2">
                            <p>
                                <span className="text-surface-500 dark:text-surface-400">
                                    Employee:{' '}
                                </span>
                                {ev.employee?.name ?? '-'}
                            </p>
                            <p>
                                <span className="text-surface-500 dark:text-surface-400">
                                    Evaluator:{' '}
                                </span>
                                {ev.evaluator?.name ?? '-'}
                            </p>
                            <p className="text-lg font-bold">
                                <span className="text-surface-500 dark:text-surface-400">
                                    Score:{' '}
                                </span>
                                {ev.score}
                            </p>
                            <p>
                                <span className="text-surface-500 dark:text-surface-400">
                                    Customer Rating:{' '}
                                </span>
                                {ev.customer_rating ?? '-'}
                            </p>
                            <p>
                                <span className="text-surface-500 dark:text-surface-400">
                                    FRT (min):{' '}
                                </span>
                                {ev.first_response_minutes ?? '-'}
                            </p>
                            <p>
                                <span className="text-surface-500 dark:text-surface-400">
                                    Resolution (min):{' '}
                                </span>
                                {ev.resolution_minutes ?? '-'}
                            </p>
                            {ev.comment && (
                                <p className="md:col-span-2">
                                    <span className="text-surface-500 dark:text-surface-400">
                                        Comment:{' '}
                                    </span>
                                    {ev.comment}
                                </p>
                            )}
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle>Reference</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3 text-sm">
                            <p>
                                <span className="text-muted-foreground">Reference: </span>
                                <Badge variant="neutral">
                                    {ev.reference_type}:{ev.reference_id}
                                </Badge>
                            </p>
                            <p>
                                <span className="text-muted-foreground">Evaluated At: </span>
                                {ev.evaluated_at}
                            </p>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AdminLayout>
    );
}
