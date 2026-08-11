import { Badge } from '@/Components/ui';

const variants = {
    success: 'success',
    warning: 'warning',
    danger: 'danger',
    muted: 'muted',
    info: 'info',
    brand: 'brand',
    neutral: 'neutral',
} as const;

type StatusBadgeProps = {
    variant?: keyof typeof variants;
    status?: string;
    label?: React.ReactNode;
    children?: React.ReactNode;
};

export function StatusBadge({ variant, status, label, children }: StatusBadgeProps) {
    const content = children ?? label ?? status;
    const tone = variant ?? 'neutral';

    return <Badge variant={variants[tone]}>{content}</Badge>;
}
