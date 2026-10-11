import { Badge } from '@/Components/ui';

export type BadgeTone = 'success' | 'info' | 'warning' | 'danger' | 'secondary';

/*
 * Badge's own warning and danger variants measure 4.46:1 and 3.6:1 in light mode, under the 4.5:1 AA
 * minimum for 12px text (the dashboard's ToneBadge found the same). These pairs measure 5.0:1 or more in
 * light and dark mode; success (4.77:1), info (5.09:1) and secondary already pass.
 */
const toneClass: Partial<Record<BadgeTone, string>> = {
    danger: 'border-transparent bg-red-500/10 text-red-700 dark:text-red-400',
    warning: 'border-transparent bg-amber-500/15 text-amber-800 dark:text-amber-400',
};

/** A Badge whose text carries the state and whose colours pass AA in both themes. */
export function ToneBadge({ tone, children }: { tone: BadgeTone; children: React.ReactNode }) {
    const className = toneClass[tone];

    return (
        <Badge variant={className ? 'outline' : tone} className={className}>
            {children}
        </Badge>
    );
}
