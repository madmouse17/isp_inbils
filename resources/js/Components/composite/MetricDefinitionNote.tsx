import { cn } from '@/lib/utils';
import type { MetricDefinitionData } from '@/types';
import { MetricDefinitionDetails } from './MetricDefinitionDetails';

interface MetricDefinitionNoteProps {
    definition?: MetricDefinitionData;
    className?: string;
}

export function MetricDefinitionNote({ definition, className }: MetricDefinitionNoteProps) {
    if (!definition) {
        return null;
    }

    const basis = definition.date_basis === 'snapshot' ? 'Snapshot' : 'Period';

    return (
        <div className={cn('space-y-1 mt-3 min-w-0 border-t border-border pt-3', className)}>
            <p className="text-xs wrap-break-word text-muted-foreground">{definition.definition}</p>
            <p className="text-xs text-muted-foreground">
                {basis} · {definition.version}
            </p>
            <details className="text-xs">
                <summary
                    aria-label={`Formula and sources for ${definition.label}`}
                    className="w-fit cursor-pointer rounded-sm font-medium text-foreground underline-offset-2 hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-hidden"
                >
                    Formula and sources
                </summary>
                <MetricDefinitionDetails definition={definition} className="mt-3" />
            </details>
        </div>
    );
}
