import { Badge, type BadgeVariant } from '@/Components/ui/Badge';

type StatusBadgeProps = {
    variant?: BadgeVariant;
    status?: string;
    label?: React.ReactNode;
    children?: React.ReactNode;
};

export function StatusBadge({ variant = 'neutral', status, label, children }: StatusBadgeProps) {
    return <Badge variant={variant}>{children ?? label ?? status}</Badge>;
}
