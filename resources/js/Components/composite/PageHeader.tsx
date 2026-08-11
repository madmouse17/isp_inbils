import { Breadcrumb } from '@/Components/ui';

interface BreadcrumbItem {
    label: string;
    href?: string;
}

interface PageHeaderProps {
    title: string;
    subtitle?: string;
    description?: string;
    breadcrumbs?: BreadcrumbItem[];
    actions?: React.ReactNode;
}

export function PageHeader({
    title,
    subtitle,
    description,
    breadcrumbs,
    actions,
}: PageHeaderProps) {
    return (
        <div className="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
            <div className="space-y-2">
                {breadcrumbs && breadcrumbs.length > 0 && <Breadcrumb items={breadcrumbs} />}
                <div className="space-y-1">
                    <h1 className="text-2xl font-semibold tracking-tight text-foreground">
                        {title}
                    </h1>
                    {(subtitle || description) && (
                        <p className="text-sm text-muted-foreground">{subtitle ?? description}</p>
                    )}
                </div>
            </div>
            {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
        </div>
    );
}
