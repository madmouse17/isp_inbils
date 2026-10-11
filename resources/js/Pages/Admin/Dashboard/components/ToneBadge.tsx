import type { ReactNode } from 'react';
import { Badge } from '@/Components/ui';
import { cn } from '@/lib/utils';

export type Tone = 'danger' | 'warning' | 'info' | 'neutral';

// Badge's own warning and danger variants measure 4.46:1 and 3.6:1 in light mode, under the 4.5:1 AA
// minimum for 12px text. These pairs measure 5.0:1 or more in light and dark mode. The class strings
// live in a .tsx file because tailwind.config.js only scans .tsx.
const toneClass: Record<Tone, string> = {
    danger: 'border-transparent bg-red-500/10 text-red-700 dark:text-red-400',
    warning: 'border-transparent bg-amber-500/15 text-amber-800 dark:text-amber-400',
    info: 'border-transparent bg-sky-500/15 text-sky-700 dark:text-sky-400',
    neutral: 'border-transparent bg-secondary text-secondary-foreground',
};

interface ToneBadgeProps {
    tone: Tone;
    children: ReactNode;
}

export function ToneBadge({ tone, children }: ToneBadgeProps) {
    return (
        <Badge variant="outline" className={cn('shrink-0 whitespace-nowrap', toneClass[tone])}>
            {children}
        </Badge>
    );
}
