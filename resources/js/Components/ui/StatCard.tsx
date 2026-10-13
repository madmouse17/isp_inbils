import { cn } from '@/lib/utils';
import { Card, CardContent } from './Card';
import { ArrowUp, ArrowDown } from 'lucide-react';

const accents = {
    brand: 'text-brand-600 dark:text-brand-400',
    success: 'text-emerald-700 dark:text-emerald-400',
    warning: 'text-amber-700 dark:text-amber-400',
    danger: 'text-destructive-text',
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

export function StatCard({
    label,
    value,
    delta,
    deltaDirection = 'up',
    icon,
    accent = 'brand',
    className,
}: StatCardProps) {
    return (
        <Card className={className}>
            <CardContent className="flex items-start justify-between">
                <div>
                    <p className="text-sm text-muted-foreground">{label}</p>
                    <p className="mt-1 text-2xl font-bold text-foreground">{value}</p>
                    {delta !== undefined && (
                        <p
                            className={cn(
                                'mt-1 inline-flex items-center gap-1 text-sm font-medium',
                                deltaDirection === 'up'
                                    ? 'text-emerald-700 dark:text-emerald-400'
                                    : 'text-destructive-text',
                            )}
                        >
                            {deltaDirection === 'up' ? (
                                <ArrowUp className="h-4 w-4" />
                            ) : (
                                <ArrowDown className="h-4 w-4" />
                            )}
                            {Math.abs(delta)}%
                        </p>
                    )}
                </div>
                {icon && <span className={cn(accents[accent])}>{icon}</span>}
            </CardContent>
        </Card>
    );
}
