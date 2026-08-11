import { Badge } from '@/Components/ui/Badge';

const roleColors = [
    'border-transparent bg-danger text-white',
    'border-transparent bg-warning text-white',
    'border-transparent bg-success text-white',
    'border-transparent bg-brand-600 text-white dark:bg-brand-500',
    'border-transparent bg-primary text-white',
    'border-transparent bg-surface-600 text-white dark:bg-surface-500',
];

export interface RoleBadgeProps {
    role: string;
}

export function RoleBadge({ role }: RoleBadgeProps) {
    let hash = 2166136261;

    for (const character of role.toLowerCase()) {
        hash ^= character.charCodeAt(0);
        hash = Math.imul(hash, 16777619);
    }

    return (
        <Badge variant="outline" className={roleColors[(hash >>> 0) % roleColors.length]}>
            {role}
        </Badge>
    );
}
