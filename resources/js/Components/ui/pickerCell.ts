import type { KeyboardEvent } from 'react';
import { cn } from '@/lib/utils';
import { controlHeight } from './controlSize';

/**
 * A button in a popover list or grid (a time slot, a month). Selected is the accent the calendar
 * uses for its chosen day, so a picked value looks the same in every picker; the current one
 * (this month) keeps a ring and a tinted text, so it does not rest on colour alone.
 */
export function pickerCellClass(selected: boolean, current = false) {
    return cn(
        'inline-flex items-center justify-center rounded-md border px-2 text-sm transition-colors',
        'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 dark:focus-visible:outline-brand-400',
        controlHeight.md,
        selected
            ? 'border-transparent bg-brand-600 font-semibold text-white'
            : current
              ? 'border-current text-brand-700 hover:bg-accent dark:text-brand-400'
              : 'border-transparent hover:bg-accent',
    );
}

/**
 * `DayPicker` draws one chosen day as a ring, the same as today. The range picker fills the ends of its
 * range with the accent, so the single-date calendars do the same: a chosen day is filled, today stays a ring.
 */
export const SELECTED_DAY_CLASSES = {
    selected:
        '[&>button]:border-transparent [&>button]:bg-brand-600 [&>button]:font-semibold [&>button]:text-white',
} as const;

/**
 * Roving focus for a popover list: put `onKeyDown={(event) => moveFocus(event, { ArrowDown: 1,
 * ArrowUp: -1 })}` on the container and `data-picker-item` on its buttons. The delta is how many
 * buttons to move; `Home` and `End` use an infinite one, which the clamp turns into the first or last.
 */
export function moveFocus(
    event: KeyboardEvent<HTMLElement>,
    deltas: Readonly<Record<string, number>>,
) {
    const delta = Object.hasOwn(deltas, event.key) ? deltas[event.key] : undefined;
    if (delta === undefined) return;

    const items = Array.from(
        event.currentTarget.querySelectorAll<HTMLElement>('[data-picker-item]'),
    );
    const index = items.indexOf(document.activeElement as HTMLElement);
    if (index < 0) return;

    event.preventDefault();
    items[Math.min(items.length - 1, Math.max(0, index + delta))]?.focus();
}
