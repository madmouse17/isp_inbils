import { useEffect, useRef } from 'react';
import { TIME_SLOTS, slotAtOrBefore } from '@/lib/dateValue';
import { cn } from '@/lib/utils';
import { moveFocus, pickerCellClass } from './pickerCell';

interface TimeSlotListProps {
    /** `HH:mm`, or empty. A time between two slots marks the slot before it as the place to start. */
    value: string;
    onPick: (slot: string) => void;
    /** Move focus to the slot when the list opens (the time popover); the date-time one has the calendar. */
    autoFocus?: boolean;
    className?: string;
}

/** The half hours of a day, one tab stop; arrow keys move between them. Internal to the time pickers. */
export function TimeSlotList({ value, onPick, autoFocus = false, className }: TimeSlotListProps) {
    const listRef = useRef<HTMLDivElement>(null);
    const start = value ? slotAtOrBefore(value) : TIME_SLOTS[0];

    // Open on the slot in use, centred in the list, without scrolling the page behind it.
    useEffect(() => {
        const list = listRef.current;
        const active = list?.querySelector<HTMLElement>('[data-active]');
        if (!list || !active) return;

        list.scrollTop = active.offsetTop - (list.clientHeight - active.offsetHeight) / 2;
        if (autoFocus) active.focus({ preventScroll: true });
    }, [autoFocus]);

    return (
        <div
            ref={listRef}
            role="group"
            aria-label="Jam"
            onKeyDown={(event) =>
                moveFocus(event, {
                    ArrowDown: 1,
                    ArrowRight: 1,
                    ArrowUp: -1,
                    ArrowLeft: -1,
                    Home: -Infinity,
                    End: Infinity,
                })
            }
            className={cn('relative grid gap-1 overflow-y-auto', className)}
        >
            {TIME_SLOTS.map((slot) => (
                <button
                    key={slot}
                    type="button"
                    data-picker-item
                    data-active={slot === start ? '' : undefined}
                    tabIndex={slot === start ? 0 : -1}
                    aria-pressed={slot === value}
                    onClick={() => onPick(slot)}
                    className={pickerCellClass(slot === value)}
                >
                    {slot}
                </button>
            ))}
        </div>
    );
}
