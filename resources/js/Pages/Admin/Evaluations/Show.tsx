import { router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Badge, Button, Card, CardContent, CardHeader, CardTitle } from '@/Components/ui';

interface EvalData {
    id: number; reference_type: string; reference_id: number; score: string;
    customer_rating?: string | null; first_response_minutes?: number | null;
    resolution_minutes?: number | null; comment?: string | null; evaluated_at: string;
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
                <div className="flex items-center justify-between gap-4">
                    <div>
                        <h2 className="text-2xl font-bold text-surface-900 dark:text-surface-100">Evaluation</h2>
                        <p className="mt-1 text-sm text-surface-500 dark:text-surface-400">
                            <Badge variant="neutral">{ev.reference_type}:{ev.reference_id}</Badge> · {ev.evaluated_at}
                        </p>
                    </div>
                    <div className="flex gap-2">
                        <Button type="button" variant="secondary" onClick={() => router.get(route('admin.evaluations.edit', ev.id))}>Edit</Button>
                        <Button type="button" variant="secondary" onClick={() => router.get(route('admin.evaluations.index'))}>Back</Button>
                    </div>
                </div>
                <Card>
                    <CardHeader><CardTitle>Details</CardTitle></CardHeader>
                    <CardContent className="grid gap-4 text-sm md:grid-cols-2">
                        <p><span className="text-surface-500 dark:text-surface-400">Employee: </span>{ev.employee?.name ?? '-'}</p>
                        <p><span className="text-surface-500 dark:text-surface-400">Evaluator: </span>{ev.evaluator?.name ?? '-'}</p>
                        <p className="text-lg font-bold"><span className="text-surface-500 dark:text-surface-400">Score: </span>{ev.score}</p>
                        <p><span className="text-surface-500 dark:text-surface-400">Customer Rating: </span>{ev.customer_rating ?? '-'}</p>
                        <p><span className="text-surface-500 dark:text-surface-400">FRT (min): </span>{ev.first_response_minutes ?? '-'}</p>
                        <p><span className="text-surface-500 dark:text-surface-400">Resolution (min): </span>{ev.resolution_minutes ?? '-'}</p>
                        {ev.comment && <p className="md:col-span-2"><span className="text-surface-500 dark:text-surface-400">Comment: </span>{ev.comment}</p>}
                    </CardContent>
                </Card>
            </div>
        </AdminLayout>
    );
}
