import { Badge } from '@/Components/ui';
import type { BadgeVariant } from '@/Components/ui';
import { deadlineState } from '@/lib/deadline';
import type { DeadlineTone } from '@/lib/deadline';

// A passed target is a breach, so it takes the one solid red the badge has; the words say the same.
const VARIANT: Record<DeadlineTone, BadgeVariant> = {
    neutral: 'neutral',
    warning: 'warning',
    danger: 'destructive',
};

interface DeadlineBadgeProps {
    /** The target as an ISO 8601 moment. */
    deadline: string;
    /** From one `useNow()` on the page, so every badge of the page shares one timer. */
    now: number;
    className?: string;
}

/** What is left of a target, or how long ago it passed, as the text of a badge: `sisa 41 mnt`, `lewat 12 mnt`. */
export function DeadlineBadge({ deadline, now, className }: DeadlineBadgeProps) {
    const { tone, label } = deadlineState(deadline, now);

    return (
        <Badge variant={VARIANT[tone]} className={className}>
            {label}
        </Badge>
    );
}
