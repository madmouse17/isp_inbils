import { formatNumber } from '@/lib/format';
import type { LifecycleFields } from '@/types/models';

/** "Dipakai 3 paket, 12 langganan": what keeps a master from a permanent delete. Nothing when unused. */
export function UsageLine({ references }: { references: LifecycleFields['references'] }) {
    const used = (references ?? []).filter((reference) => reference.count > 0);

    if (used.length === 0) return null;

    return (
        <p className="text-xs text-muted-foreground">
            Dipakai{' '}
            {used
                .map((reference) => `${formatNumber(reference.count)} ${reference.label}`)
                .join(', ')}
        </p>
    );
}
