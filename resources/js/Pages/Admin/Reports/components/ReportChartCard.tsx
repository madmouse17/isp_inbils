import { useId, type ReactNode } from 'react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui';
import { cn } from '@/lib/utils';

interface ReportChartCardProps {
    /** What the chart counts and per what, so the heading answers the question the chart is for. */
    title: string;
    /** What the period filter does to this chart, when that is not obvious. */
    description?: string;
    className?: string;
    children: ReactNode;
}

export function ReportChartCard({ title, description, className, children }: ReportChartCardProps) {
    const titleId = useId();

    return (
        <Card role="group" aria-labelledby={titleId} className={cn('min-w-0', className)}>
            <CardHeader>
                <CardTitle id={titleId}>{title}</CardTitle>
                {description ? <CardDescription>{description}</CardDescription> : null}
            </CardHeader>
            <CardContent>{children}</CardContent>
        </Card>
    );
}
