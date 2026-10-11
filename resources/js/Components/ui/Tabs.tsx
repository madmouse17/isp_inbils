import { cn } from '@/lib/utils';
import { createContext, useCallback, useContext, useId, useMemo, useState } from 'react';
import type { KeyboardEvent, ReactNode } from 'react';

interface TabsProps {
    value: string;
    onValueChange: (value: string) => void;
    children: ReactNode;
    className?: string;
}

interface TabEntry {
    node: HTMLButtonElement | null;
    disabled: boolean;
}

interface TabsRegistry {
    /** Registration order of tab values. */
    order: string[];
    entries: Map<string, TabEntry>;
}

interface TabsContextValue {
    value: string;
    onValueChange: (value: string) => void;
    baseId: string;
    registry: TabsRegistry;
}

const TabsContext = createContext<TabsContextValue | null>(null);

export function Tabs({ value, onValueChange, children, className }: TabsProps) {
    const baseId = useId();
    // Stable mutable registry shared with Tab children via context. Created once
    // per Tabs instance; the lazy pattern avoids useState re-renders.
    const [registry] = useState<TabsRegistry>(() => ({ order: [], entries: new Map() }));

    const ctx = useMemo<TabsContextValue>(
        () => ({ value, onValueChange, baseId, registry }),
        [value, onValueChange, baseId, registry],
    );

    return (
        <TabsContext.Provider value={ctx}>
            <div className={cn(className)}>{children}</div>
        </TabsContext.Provider>
    );
}

interface TabListProps {
    children: ReactNode;
    className?: string;
    'aria-label'?: string;
}

export function TabList({ children, className, 'aria-label': ariaLabel }: TabListProps) {
    const ctx = useContext(TabsContext);

    const onKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
        if (!ctx) return;
        if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
        event.preventDefault();

        const { registry } = ctx;
        const enabled = registry.order.filter((tabValue) => {
            const entry = registry.entries.get(tabValue);
            return entry && !entry.disabled && entry.node;
        });
        if (enabled.length === 0) return;

        const currentIndex = enabled.findIndex(
            (tabValue) => registry.entries.get(tabValue)?.node === document.activeElement,
        );

        let nextIndex: number;
        if (event.key === 'Home') {
            nextIndex = 0;
        } else if (event.key === 'End') {
            nextIndex = enabled.length - 1;
        } else if (event.key === 'ArrowRight') {
            nextIndex = currentIndex < 0 ? 0 : (currentIndex + 1) % enabled.length;
        } else {
            nextIndex =
                currentIndex < 0
                    ? enabled.length - 1
                    : (currentIndex - 1 + enabled.length) % enabled.length;
        }

        const nextValue = enabled[nextIndex];
        ctx.onValueChange(nextValue);
        registry.entries.get(nextValue)?.node?.focus();
    };

    return (
        <div
            className={cn('flex border-b border-border', className)}
            role="tablist"
            aria-label={ariaLabel}
            onKeyDown={onKeyDown}
        >
            {children}
        </div>
    );
}

interface TabProps {
    value: string;
    children: ReactNode;
    disabled?: boolean;
    className?: string;
}

export function Tab({ value, children, disabled, className }: TabProps) {
    const ctx = useContext(TabsContext);
    const active = ctx?.value === value;

    // Keep the registry in sync during render (before keyboard events can fire).
    if (ctx) {
        const { registry } = ctx;
        const existing = registry.entries.get(value);
        if (existing) {
            existing.disabled = Boolean(disabled);
        } else {
            registry.entries.set(value, { node: null, disabled: Boolean(disabled) });
        }
        if (!registry.order.includes(value)) {
            registry.order.push(value);
        }
    }

    const setRef = useCallback(
        (node: HTMLButtonElement | null) => {
            const entry = ctx?.registry.entries.get(value);
            if (entry) entry.node = node;
        },
        [ctx, value],
    );

    const tabId = ctx ? `${ctx.baseId}-tab-${value}` : undefined;
    const panelId = ctx ? `${ctx.baseId}-panel-${value}` : undefined;

    return (
        <button
            ref={setRef}
            type="button"
            role="tab"
            id={tabId}
            aria-selected={active}
            aria-controls={panelId}
            tabIndex={active ? 0 : -1}
            disabled={disabled}
            onClick={() => ctx?.onValueChange(value)}
            className={cn(
                'px-4 py-2 text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-40',
                active
                    ? 'border-b-2 border-primary text-primary'
                    : 'text-muted-foreground hover:text-foreground',
                className,
            )}
        >
            {children}
        </button>
    );
}

interface TabPanelProps {
    value: string;
    children: ReactNode;
    className?: string;
}

export function TabPanel({ value, children, className }: TabPanelProps) {
    const ctx = useContext(TabsContext);
    if (ctx?.value !== value) return null;

    const tabId = `${ctx.baseId}-tab-${value}`;
    const panelId = `${ctx.baseId}-panel-${value}`;

    return (
        <div
            role="tabpanel"
            id={panelId}
            aria-labelledby={tabId}
            tabIndex={0}
            className={cn('py-4 focus-visible:outline-none', className)}
        >
            {children}
        </div>
    );
}
