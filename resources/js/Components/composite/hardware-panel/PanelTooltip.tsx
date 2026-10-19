import { useLayoutEffect, useRef, useSyncExternalStore } from 'react';
import type { RefObject } from 'react';

export interface TooltipTarget {
    /** Index of the port in `PanelGeometry.ports` (the `data-i` of its button). */
    i: number;
    el: HTMLElement;
    open: boolean;
}

/**
 * Where the tooltip points. Hover and focus handlers write to the store, so the panel itself never
 * re-renders on hover: `PanelTooltip` is the only component that subscribes.
 */
export interface TooltipStore {
    show: (i: number, el: HTMLElement) => void;
    hide: () => void;
    subscribe: (listener: () => void) => () => void;
    getSnapshot: () => TooltipTarget | null;
}

export function createTooltipStore(): TooltipStore {
    let current: TooltipTarget | null = null;
    const listeners = new Set<() => void>();
    const set = (next: TooltipTarget | null) => {
        current = next;
        listeners.forEach((listener) => listener());
    };

    return {
        show(i, el) {
            if (current?.open && current.i === i && current.el === el) return;
            performance.mark('panel:hover:start');
            set({ i, el, open: true });
        },
        // Keep the last target so the text stays while the tooltip fades out.
        hide() {
            if (current && current.open) set({ ...current, open: false });
        },
        subscribe(listener) {
            listeners.add(listener);
            return () => listeners.delete(listener);
        },
        getSnapshot: () => current,
    };
}

interface PanelTooltipProps {
    store: TooltipStore;
    /** The positioned ancestor the tooltip is placed in; it must not clip overflow. */
    rootRef: RefObject<HTMLElement | null>;
    /** Text lines of port `i`; the first is the heading. */
    lines: (i: number) => string[];
}

/** The one tooltip of a panel: name, state, link or drop, subscribers behind. It repeats what the button's name says, so it is hidden from assistive tech. */
export function PanelTooltip({ store, rootRef, lines }: PanelTooltipProps) {
    const target = useSyncExternalStore(store.subscribe, store.getSnapshot);
    const tipRef = useRef<HTMLDivElement>(null);

    useLayoutEffect(() => {
        const tip = tipRef.current;
        const root = rootRef.current;
        if (!tip || !root || !target?.open) return;

        const rootBox = root.getBoundingClientRect();
        const anchor = target.el.getBoundingClientRect();
        const gap = 6;
        const left = Math.min(
            Math.max(0, anchor.left + anchor.width / 2 - rootBox.left - tip.offsetWidth / 2),
            Math.max(0, rootBox.width - tip.offsetWidth),
        );
        // Below the port, or above it when the viewport has no room underneath.
        const fitsBelow = anchor.bottom + gap + tip.offsetHeight <= window.innerHeight;
        const top = fitsBelow
            ? anchor.bottom - rootBox.top + gap
            : anchor.top - rootBox.top - gap - tip.offsetHeight;

        tip.style.transform = `translate(${left}px, ${top}px)`;
        performance.measure('panel:hover', 'panel:hover:start');
        performance.clearMarks('panel:hover:start');
    }, [target, rootRef]);

    return (
        <div
            ref={tipRef}
            role="tooltip"
            aria-hidden="true"
            data-open={target?.open ? '' : undefined}
            className="pointer-events-none invisible absolute top-0 left-0 z-20 w-max max-w-64 rounded-md border bg-popover px-2.5 py-1.5 text-xs text-popover-foreground opacity-0 shadow-md transition-[opacity,visibility] duration-120 data-open:visible data-open:opacity-100 motion-reduce:transition-none"
        >
            {target
                ? lines(target.i).map((line, n) => (
                      <p key={n} className={n === 0 ? 'font-semibold' : 'text-muted-foreground'}>
                          {line}
                      </p>
                  ))
                : null}
        </div>
    );
}
