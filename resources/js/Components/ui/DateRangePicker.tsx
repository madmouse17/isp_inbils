import { useId, useRef, useState, useSyncExternalStore } from 'react';
import type { KeyboardEvent } from 'react';
import { CalendarDays, X } from 'lucide-react';
import { type DateRange as CalendarRange, DayPicker } from 'react-day-picker';
import { id as calendarLocale } from 'react-day-picker/locale/id';
import { useCompany } from '@/hooks/useCompany';
import { phpToDateFns } from '@/lib/dateFormat';
import {
    DATE_RANGE_PRESETS,
    type DateRange,
    type DateRangePresetId,
    formatRange,
    isoToLocalDate,
    localDateToIso,
    parseRange,
    presetRange,
    todayInZone,
} from '@/lib/dateRange';
import { cn } from '@/lib/utils';
import { Button } from './Button';
import { IconButton } from './IconButton';
import { Input } from './Input';
import { Popover, PopoverAnchor, PopoverContent, PopoverTrigger } from './popover';

export type { DateRange } from '@/lib/dateRange';

export interface DateRangePickerProps {
    /** ISO `yyyy-mm-dd` ends; `null` leaves that end open. */
    value: DateRange;
    /** Called only with a real, non-inverted range. */
    onChange: (range: DateRange) => void;
    label?: string;
    id?: string;
    /** Server-side message; a typed entry that fails to parse shows its own message first. */
    error?: string;
    disabled?: boolean;
    /** The quick ranges (Hari ini, 7 hari terakhir, ...) in the popover. Default true. */
    presets?: boolean;
    className?: string;
}

/** Tailwind `sm`: from here the calendar shows two months. */
const TWO_MONTHS_QUERY = '(min-width: 640px)';

function subscribeTwoMonths(onChange: () => void) {
    const query = window.matchMedia(TWO_MONTHS_QUERY);
    query.addEventListener('change', onChange);

    return () => query.removeEventListener('change', onChange);
}

/**
 * A date range you can type or pick. The text follows the company `date_format` and is committed on
 * blur or Enter, only when it is a real range; the calendar commits when its second day is chosen.
 */
export function DateRangePicker({
    value,
    onChange,
    label,
    id,
    error,
    disabled = false,
    presets = true,
    className,
}: DateRangePickerProps) {
    const company = useCompany();
    const dateFnsFormat = phpToDateFns(company?.settings?.date_format);
    const today = todayInZone(company?.timezone);
    const shown = formatRange(value, dateFnsFormat);

    const generatedId = useId();
    const inputId = id ?? generatedId;
    const errorId = `${inputId}-error`;
    const inputRef = useRef<HTMLInputElement>(null);

    const [text, setText] = useState(shown);
    const [lastShown, setLastShown] = useState(shown);
    const [typedError, setTypedError] = useState<'format' | 'order' | null>(null);
    const [open, setOpen] = useState(false);
    const [draft, setDraft] = useState<CalendarRange | undefined>();
    const twoMonths = useSyncExternalStore(
        subscribeTwoMonths,
        () => window.matchMedia(TWO_MONTHS_QUERY).matches,
        () => true,
    );

    // The value changed from outside (a visit, a reset): show it and drop what was typed.
    if (shown !== lastShown) {
        setLastShown(shown);
        setText(shown);
        setTypedError(null);
    }

    const commit = (range: DateRange) => {
        setText(formatRange(range, dateFnsFormat));
        setTypedError(null);
        if (range.from !== value.from || range.to !== value.to) onChange(range);
    };

    const commitText = () => {
        const result = parseRange(text, dateFnsFormat);
        if (result.ok) commit(result.range);
        else setTypedError(result.reason);
    };

    const pending = text !== shown;

    const handleKeyDown = (event: KeyboardEvent<HTMLInputElement>) => {
        // A typed entry is committed first; a surrounding form must not submit the old range.
        if (event.key === 'Enter' && pending && !event.nativeEvent.isComposing) {
            event.preventDefault();
            commitText();
        }
    };

    const handleOpenChange = (next: boolean) => {
        if (next) {
            const from = isoToLocalDate(value.from);
            const to = isoToLocalDate(value.to);
            if (from && to) setDraft(from <= to ? { from, to } : { from: to, to: from });
            else setDraft(from ? { from, to: undefined } : undefined);
        }
        setOpen(next);
    };

    const pickDay = (range: CalendarRange) => {
        setDraft(range);
        if (range.from && range.to) {
            commit({ from: localDateToIso(range.from), to: localDateToIso(range.to) });
            setOpen(false);
        }
    };

    const pickPreset = (preset: DateRangePresetId) => {
        commit(presetRange(preset, today));
        setOpen(false);
    };

    const clear = () => {
        commit({ from: null, to: null });
        inputRef.current?.focus();
    };

    const message =
        typedError === 'order'
            ? 'Tanggal akhir tidak boleh sebelum tanggal mulai.'
            : typedError === 'format'
              ? `Format tanggal tidak valid. Contoh: ${formatRange(presetRange('last7', today), dateFnsFormat)}.`
              : error;
    const hasContent = text.trim() !== '' || Boolean(value.from) || Boolean(value.to);
    const waitingForEnd = draft?.from !== undefined && draft.to === undefined;

    return (
        <div className={cn('space-y-1.5 w-full', className)}>
            {label ? (
                <label htmlFor={inputId} className="text-sm leading-none font-medium">
                    {label}
                </label>
            ) : null}
            <Popover open={open} onOpenChange={handleOpenChange}>
                <PopoverAnchor asChild>
                    <div className="relative">
                        <Input
                            ref={inputRef}
                            id={inputId}
                            value={text}
                            onChange={(event) => {
                                setText(event.target.value);
                                setTypedError(null);
                            }}
                            onBlur={() => {
                                if (pending) commitText();
                            }}
                            onKeyDown={handleKeyDown}
                            placeholder="Semua tanggal"
                            autoComplete="off"
                            spellCheck={false}
                            disabled={disabled}
                            aria-label={label ? undefined : 'Rentang tanggal'}
                            aria-invalid={message ? true : undefined}
                            aria-describedby={message ? errorId : undefined}
                            className={cn(
                                hasContent && !disabled ? 'pr-18' : 'pr-10',
                                message && 'border-destructive focus-visible:ring-destructive',
                            )}
                        />
                        <div className="absolute inset-y-0 right-0.5 flex items-center gap-0.5">
                            {hasContent && !disabled ? (
                                <IconButton
                                    type="button"
                                    size="sm"
                                    label="Hapus rentang tanggal"
                                    onClick={clear}
                                >
                                    <X className="h-4 w-4" />
                                </IconButton>
                            ) : null}
                            <PopoverTrigger asChild>
                                <IconButton
                                    type="button"
                                    size="sm"
                                    label="Buka kalender"
                                    disabled={disabled}
                                >
                                    <CalendarDays className="h-4 w-4" />
                                </IconButton>
                            </PopoverTrigger>
                        </div>
                    </div>
                </PopoverAnchor>
                <PopoverContent
                    align="start"
                    sideOffset={6}
                    collisionPadding={8}
                    aria-label="Pilih rentang tanggal"
                    // The calendar focuses its own day (autoFocus below); Radix would focus the first preset.
                    onOpenAutoFocus={(event) => event.preventDefault()}
                    className="max-h-(--radix-popover-content-available-height) w-auto max-w-(--radix-popover-content-available-width) overflow-auto p-2 sm:p-3"
                >
                    <div className="flex flex-col gap-3 lg:flex-row">
                        {presets ? (
                            <div
                                role="group"
                                aria-label="Pilihan cepat"
                                className="grid grid-cols-2 gap-1 sm:grid-cols-3 lg:flex lg:w-40 lg:shrink-0 lg:flex-col lg:border-r lg:pr-3"
                            >
                                {DATE_RANGE_PRESETS.map((preset) => (
                                    <Button
                                        key={preset.id}
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        className="h-11 justify-start px-3 text-sm lg:h-9"
                                        onClick={() => pickPreset(preset.id)}
                                    >
                                        {preset.label}
                                    </Button>
                                ))}
                            </div>
                        ) : null}
                        <div className="min-w-0">
                            <DayPicker
                                mode="range"
                                required
                                resetOnSelect
                                autoFocus
                                locale={calendarLocale}
                                selected={draft}
                                onSelect={pickDay}
                                numberOfMonths={twoMonths ? 2 : 1}
                                defaultMonth={
                                    isoToLocalDate(value.from ?? value.to ?? today) ?? undefined
                                }
                                today={isoToLocalDate(today) ?? undefined}
                                modifiers={{ pending: waitingForEnd ? draft?.from : undefined }}
                                modifiersClassNames={{ pending: 'rdp-pending' }}
                                navLayout="around"
                            />
                            <p role="status" className="px-1 pt-2 text-xs text-muted-foreground">
                                {waitingForEnd
                                    ? 'Pilih tanggal akhir.'
                                    : 'Pilih tanggal mulai, lalu tanggal akhir.'}
                            </p>
                        </div>
                    </div>
                </PopoverContent>
            </Popover>
            {message ? (
                <p id={errorId} role="alert" className="text-sm text-red-700 dark:text-red-400">
                    {message}
                </p>
            ) : null}
        </div>
    );
}
