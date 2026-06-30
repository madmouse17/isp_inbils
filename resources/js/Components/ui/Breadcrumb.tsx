import { Link } from '@inertiajs/react';
import { ChevronRightIcon } from '@heroicons/react/20/solid';
import { cn } from '@/lib/utils';

interface BreadcrumbItem {
    label: string;
    href?: string;
}

interface BreadcrumbProps {
    items: BreadcrumbItem[];
    className?: string;
}

export function Breadcrumb({ items, className }: BreadcrumbProps) {
    return (
        <nav aria-label="Breadcrumb" className={cn(className)}>
            <ol className="flex items-center gap-1.5">
                {items.map((item, i) => {
                    const isLast = i === items.length - 1;
                    return (
                        <li key={i} className="flex items-center gap-1.5">
                            {i > 0 && <ChevronRightIcon className="h-4 w-4 text-surface-400" />}
                            {isLast || !item.href ? (
                                <span aria-current={isLast ? 'page' : undefined} className="text-sm font-medium text-surface-500 dark:text-surface-400">
                                    {item.label}
                                </span>
                            ) : (
                                <Link href={item.href} className="text-sm font-medium text-surface-700 hover:text-brand-600 dark:text-surface-300 dark:hover:text-brand-400">
                                    {item.label}
                                </Link>
                            )}
                        </li>
                    );
                })}
            </ol>
        </nav>
    );
}
