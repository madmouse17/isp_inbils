import { cn } from '@/lib/utils';
import { Card, CardContent } from './Card';
import { ArrowUpIcon, ArrowDownIcon } from '@heroicons/react/20/solid';

const accents = {
    brand: 'text-brand-600 dark:text-brand-400',
    success: 'text-success',
    warning: 'text-warning',
    danger: 'text-danger',
} as const;

interface StatCardProps {
    label: string;
    value: string | number;
    delta?: number;
    deltaDirection?: 'up' | 'down';
    icon?: React.ReactNode;
    accent?: keyof typeof accents;
    className?: string;
}

export function StatCard({ label, value, delta, deltaDirection = 'up', icon, accent = 'brand', className }: StatCardProps) {
    return (
        <Card className={className}>
            <CardContent className="flex items-start justify-between">
                <div>
                    <p className="text-sm text-surface-500 dark:text-surface-400">{label}</p>
                    <p className="mt-1 text-2xl font-bold text-surface-900 dark:text-surface-100">{value}</p>
                    {delta !== undefined && (
                        <p className={cn(
                            'mt-1 inline-flex items-center gap-1 text-sm font-medium',
                            deltaDirection === 'up' ? 'text-success' : 'text-danger',
                        )}>
                            {deltaDirection === 'up' ? <ArrowUpIcon className="h-4 w-4" /> : <ArrowDownIcon className="h-4 w-4" />}
                            {Math.abs(delta)}%
                        </p>
                    )}
                </div>
                {icon && <span className={cn(accents[accent])}>{icon}</span>}
            </CardContent>
        </Card>
    );
}
