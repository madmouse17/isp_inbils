import { DynamicBadge } from './DynamicBadge';

export interface RoleBadgeProps {
    role: string;
}

export function RoleBadge({ role }: RoleBadgeProps) {
    return <DynamicBadge value={role} />;
}
