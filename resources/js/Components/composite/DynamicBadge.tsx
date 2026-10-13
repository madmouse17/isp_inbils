import type { ReactNode } from 'react';
import { Badge } from '@/Components/ui/Badge';

export interface DynamicBadgeProps {
    value: string;
    children?: ReactNode;
}

/** A name the server invents (a role): one neutral pill. A hash colour would let a role render red by accident. */
export function DynamicBadge({ value, children = value }: DynamicBadgeProps) {
    return <Badge variant="neutral">{children}</Badge>;
}
