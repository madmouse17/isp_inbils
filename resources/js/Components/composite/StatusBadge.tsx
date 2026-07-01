import { Badge } from '@/Components/ui';

const variants = {
    success: 'success',
    warning: 'warning',
    danger: 'danger',
    muted: 'neutral',
    info: 'brand',
} as const;

interface StatusBadgeProps {
    variant: keyof typeof variants;
    children: React.ReactNode;
}

export function StatusBadge({ variant, children }: StatusBadgeProps) {
    return <Badge variant={variants[variant]}>{children}</Badge>;
}
