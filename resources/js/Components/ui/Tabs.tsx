import { cn } from '@/lib/utils';

interface TabsProps {
    value: string;
    onValueChange: (value: string) => void;
    children: React.ReactNode;
    className?: string;
}

interface TabsContextValue {
    value: string;
    onValueChange: (value: string) => void;
}

import { createContext, useContext, useMemo } from 'react';

const TabsContext = createContext<TabsContextValue | null>(null);

export function Tabs({ value, onValueChange, children, className }: TabsProps) {
    const ctx = useMemo(() => ({ value, onValueChange }), [value, onValueChange]);
    return (
        <TabsContext.Provider value={ctx}>
            <div className={cn(className)}>{children}</div>
        </TabsContext.Provider>
    );
}

interface TabListProps {
    children: React.ReactNode;
    className?: string;
}

export function TabList({ children, className }: TabListProps) {
    return (
        <div className={cn('flex border-b border-surface-200 dark:border-surface-800', className)} role="tablist">
            {children}
        </div>
    );
}

interface TabProps {
    value: string;
    children: React.ReactNode;
    disabled?: boolean;
}

export function Tab({ value, children, disabled }: TabProps) {
    const ctx = useContext(TabsContext);
    const active = ctx?.value === value;
    return (
        <button
            role="tab"
            aria-selected={active}
            disabled={disabled}
            onClick={() => ctx?.onValueChange(value)}
            className={cn(
                'px-4 py-2 text-sm font-medium transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/50 disabled:opacity-40 disabled:cursor-not-allowed',
                active
                    ? 'border-b-2 border-brand-600 text-brand-600 dark:text-brand-400 dark:border-brand-400'
                    : 'text-surface-500 hover:text-surface-700 dark:text-surface-400 dark:hover:text-surface-200',
            )}
        >
            {children}
        </button>
    );
}

interface TabPanelProps {
    value: string;
    children: React.ReactNode;
    className?: string;
}

export function TabPanel({ value, children, className }: TabPanelProps) {
    const ctx = useContext(TabsContext);
    if (ctx?.value !== value) return null;
    return (
        <div role="tabpanel" className={cn('py-4', className)}>
            {children}
        </div>
    );
}
