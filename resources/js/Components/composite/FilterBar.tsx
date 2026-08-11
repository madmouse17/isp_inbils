import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

export interface FilterBarProps {
    search: string;
    onSearchChange: (value: string) => void;
    children?: ReactNode;
    className?: string;
}

export function FilterBar({ search, onSearchChange, children, className }: FilterBarProps) {
    return (
        <div className={cn('flex flex-col gap-3 sm:flex-row sm:items-center', className)}>
            <input
                type="text"
                placeholder="Search..."
                value={search}
                onChange={(e) => onSearchChange(e.target.value)}
                className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-ring sm:w-64"
            />
            {children}
        </div>
    );
}
