import { resolveStatus } from '@/lib/statuses/types';
import type { StatusMap, StatusValue } from '@/lib/statuses/types';
import { Badge } from './Badge';

export interface EnumBadgeProps {
    /** One of the maps in `@/lib/statuses` (`TICKET_STATUS`, `PRIORITY`, ...). */
    map: StatusMap;
    value: StatusValue;
    className?: string;
}

/**
 * A stored value as its label, in its tone. A value the map does not list shows as itself in the
 * neutral tone, so a new status on the server never breaks a page; no value shows `-`.
 */
export function EnumBadge({ map, value, className }: EnumBadgeProps) {
    const status = resolveStatus(map, value);

    if (!status) return <span className="text-muted-foreground">-</span>;

    return (
        <Badge variant={status.tone} className={className}>
            {status.label}
        </Badge>
    );
}
