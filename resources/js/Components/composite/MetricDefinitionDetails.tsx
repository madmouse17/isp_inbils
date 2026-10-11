import { cn } from '@/lib/utils';
import type { MetricDefinitionData, MetricUnit } from '@/types';

const UNIT_LABELS: Record<MetricUnit, string> = {
    currency: 'Currency',
    count: 'Count',
    percent: 'Percent',
    minutes: 'Minutes',
    rating: 'Rating',
    quantity: 'Quantity',
    breakdown: 'Breakdown (grouped counts)',
    records: 'Records (ledger rows)',
};

interface MetricDefinitionDetailsProps {
    definition: MetricDefinitionData;
    className?: string;
}

export function MetricDefinitionDetails({ definition, className }: MetricDefinitionDetailsProps) {
    const dateBasis =
        definition.date_basis === 'snapshot'
            ? 'Snapshot. Reflects current records; the From/To filter does not change it.'
            : `Period. Counts records whose ${definition.date_column ?? 'date'} falls inside the From/To range.`;

    return (
        <dl className={cn('min-w-0 space-y-3 text-sm', className)}>
            <div className="min-w-0">
                <dt className="font-medium text-foreground">Formula</dt>
                <dd className="mt-0.5 min-w-0 break-words text-muted-foreground">
                    {definition.formula}
                </dd>
            </div>
            <div className="min-w-0">
                <dt className="font-medium text-foreground">Unit</dt>
                <dd className="mt-0.5 min-w-0 break-words text-muted-foreground">
                    {UNIT_LABELS[definition.unit]}
                </dd>
            </div>
            <div className="min-w-0">
                <dt className="font-medium text-foreground">Date basis</dt>
                <dd className="mt-0.5 min-w-0 break-words text-muted-foreground">{dateBasis}</dd>
            </div>
            {definition.statuses.length > 0 && (
                <div className="min-w-0">
                    <dt className="font-medium text-foreground">Statuses</dt>
                    <dd className="mt-0.5 min-w-0 break-words text-muted-foreground">
                        {definition.statuses.join(', ')}
                    </dd>
                </div>
            )}
            <div className="min-w-0">
                <dt className="font-medium text-foreground">Sources</dt>
                <dd className="mt-0.5 min-w-0 text-muted-foreground">
                    <ul className="space-y-1">
                        {definition.sources.map((source) => (
                            <li key={source} className="min-w-0 break-words">
                                <code className="break-words font-mono text-xs text-foreground">
                                    {source}
                                </code>
                            </li>
                        ))}
                    </ul>
                </dd>
            </div>
            <div className="min-w-0">
                <dt className="font-medium text-foreground">Scope</dt>
                <dd className="mt-0.5 min-w-0 break-words text-muted-foreground">
                    {definition.scope}
                </dd>
            </div>
            {definition.caveats.length > 0 && (
                <div className="min-w-0">
                    <dt className="font-medium text-foreground">Known caveats</dt>
                    <dd className="mt-0.5 min-w-0 text-muted-foreground">
                        <ul className="list-disc space-y-1 pl-5">
                            {definition.caveats.map((caveat) => (
                                <li key={caveat} className="min-w-0 break-words">
                                    {caveat}
                                </li>
                            ))}
                        </ul>
                    </dd>
                </div>
            )}
        </dl>
    );
}
