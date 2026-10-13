import { useEffect, useId, useMemo, useRef, useState } from 'react';
import type { KeyboardEvent } from 'react';
import { ChevronsUpDown, X } from 'lucide-react';
import { cn } from '@/lib/utils';
import { controlHeight } from './controlSize';
import { IconButton } from './IconButton';
import { Label } from './Label';
import { Popover, PopoverAnchor, PopoverContent } from './popover';
import { Spinner } from './Spinner';

export interface SearchSelectOption {
    value: string;
    label: string;
    description?: string;
    /** Heading the option is listed under. Options of one group are shown together, in the order the groups first appear. */
    group?: string;
}

export interface SearchSelectProps {
    id?: string;
    label?: string;
    /** The chosen option's value, `''` for none. Typing in the field never changes it; only a choice or the clear button does. */
    value: string;
    onChange: (value: string) => void;
    options: SearchSelectOption[];
    /**
     * The option that goes with `value` when `options` does not hold it: the one a page loaded
     * with before any search. An option chosen in this field is remembered without it.
     */
    selected?: SearchSelectOption | null;
    /**
     * Server-side search: called with every change of the typed text. `options` is then the
     * server's answer for that text and is shown as it is, not filtered here. See `RemoteSearchSelect`.
     */
    onSearch?: (query: string) => void;
    /** An answer to `onSearch` is on its way. */
    loading?: boolean;
    /** Characters to type before options show. Default 2 with `onSearch`, 0 without. */
    minChars?: number;
    placeholder?: string;
    emptyText?: string;
    error?: string;
    hint?: string;
    disabled?: boolean;
    required?: boolean;
    className?: string;
}

/**
 * A field you search by typing and choose from a list. The list is a popover, so a dialog or a
 * card never clips it and Escape closes the list before the dialog. Arrow keys move through the
 * options, Enter chooses, Escape closes and the chosen label comes back.
 */
export function SearchSelect({
    id,
    label,
    value,
    onChange,
    options,
    selected,
    onSearch,
    loading = false,
    minChars,
    placeholder = 'Cari...',
    emptyText = 'Tidak ada hasil.',
    error,
    hint,
    disabled,
    required,
    className,
}: SearchSelectProps) {
    const generatedId = useId();
    const inputId = id ?? generatedId;
    const listId = `${inputId}-list`;
    const anchorRef = useRef<HTMLDivElement>(null);
    const inputRef = useRef<HTMLInputElement>(null);

    const [open, setOpen] = useState(false);
    // What the person is typing; null while they are not, when the field shows the chosen label.
    const [query, setQuery] = useState<string | null>(null);
    const [active, setActive] = useState(-1);
    // The last option chosen here, so its label survives a list that no longer holds it.
    const [picked, setPicked] = useState<SearchSelectOption | null>(null);

    const remote = onSearch !== undefined;
    const threshold = minChars ?? (remote ? 2 : 0);
    const isOpen = open && !disabled;
    const needle = (query ?? '').trim().toLowerCase();
    const ready = needle.length >= threshold;

    const chosen = useMemo(
        () =>
            value === ''
                ? undefined
                : [options.find((option) => option.value === value), picked, selected].find(
                      (option) => option?.value === value,
                  ),
        [options, picked, selected, value],
    );

    const visible = useMemo(() => {
        if (!ready) return [];

        const matching =
            remote || needle === ''
                ? options
                : options.filter((option) =>
                      `${option.label} ${option.description ?? ''}`.toLowerCase().includes(needle),
                  );

        if (!matching.some((option) => option.group)) return matching;

        // Group order is the first appearance of each group; options keep their order inside a group.
        const buckets = new Map<string, SearchSelectOption[]>();
        for (const option of matching) {
            const bucket = buckets.get(option.group ?? '');
            if (bucket) bucket.push(option);
            else buckets.set(option.group ?? '', [option]);
        }

        return [...buckets.values()].flat();
    }, [options, ready, remote, needle]);

    // Consecutive options of one group, each with its index in `visible` (the keyboard order).
    const runs = useMemo(() => {
        const result: { group: string; items: { option: SearchSelectOption; index: number }[] }[] =
            [];
        visible.forEach((option, index) => {
            const group = option.group ?? '';
            const last = result[result.length - 1];
            if (last && last.group === group) last.items.push({ option, index });
            else result.push({ group, items: [{ option, index }] });
        });

        return result;
    }, [visible]);

    const activeIndex = Math.min(active, visible.length - 1);
    const listShown = isOpen && visible.length > 0;

    useEffect(() => {
        if (listShown && activeIndex >= 0) {
            document
                .getElementById(`${inputId}-option-${activeIndex}`)
                ?.scrollIntoView({ block: 'nearest' });
        }
    }, [listShown, activeIndex, inputId]);

    const choose = (option: SearchSelectOption) => {
        setPicked(option);
        onChange(option.value);
        setQuery(null);
        setOpen(false);
    };

    const clear = () => {
        setPicked(null);
        onChange('');
        setQuery(null);
        inputRef.current?.focus();
    };

    const handleOpenChange = (next: boolean) => {
        setOpen(next);
        if (!next) setQuery(null);
    };

    const handleKeyDown = (event: KeyboardEvent<HTMLInputElement>) => {
        if (event.nativeEvent.isComposing) return;

        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            if (!isOpen) {
                setOpen(true);
            } else if (visible.length > 0) {
                const step = event.key === 'ArrowDown' ? 1 : -1;
                // From no highlight, Down goes to the first option and Up to the last.
                const from = activeIndex >= 0 ? activeIndex : step === 1 ? -1 : 0;
                setActive((from + step + visible.length) % visible.length);
            }
        } else if (event.key === 'Enter' && listShown && activeIndex >= 0) {
            // Choosing is what Enter means while an option is highlighted; a form must not submit.
            event.preventDefault();
            choose(visible[activeIndex]);
        } else if (event.key === 'Escape' && isOpen) {
            // The popover closes itself too; this also covers the instant before it has registered.
            handleOpenChange(false);
        }
    };

    return (
        <div className={cn('w-full', className)}>
            {label && (
                <Label htmlFor={inputId} required={required}>
                    {label}
                </Label>
            )}
            <Popover open={isOpen} onOpenChange={handleOpenChange}>
                <PopoverAnchor asChild>
                    <div ref={anchorRef} className={cn('relative', label && 'mt-1')}>
                        <input
                            ref={inputRef}
                            id={inputId}
                            role="combobox"
                            aria-expanded={isOpen}
                            aria-controls={listShown ? listId : undefined}
                            aria-autocomplete="list"
                            aria-activedescendant={
                                listShown && activeIndex >= 0
                                    ? `${inputId}-option-${activeIndex}`
                                    : undefined
                            }
                            aria-required={required || undefined}
                            aria-invalid={error ? true : undefined}
                            aria-describedby={
                                error ? `${inputId}-error` : hint ? `${inputId}-hint` : undefined
                            }
                            aria-busy={loading || undefined}
                            autoComplete="off"
                            spellCheck={false}
                            // Open, the box is for the search and the chosen label waits in it as the placeholder.
                            value={isOpen ? (query ?? '') : (chosen?.label ?? '')}
                            placeholder={isOpen && chosen ? chosen.label : placeholder}
                            disabled={disabled}
                            onClick={() => setOpen(true)}
                            onChange={(event) => {
                                setQuery(event.target.value);
                                setActive(event.target.value.trim() === '' ? -1 : 0);
                                setOpen(true);
                                onSearch?.(event.target.value);
                            }}
                            onKeyDown={handleKeyDown}
                            className={cn(
                                'flex w-full rounded-md border border-input bg-transparent px-3 py-1 pr-20 text-base transition-colors placeholder:text-muted-foreground focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-hidden disabled:cursor-not-allowed disabled:opacity-50 sm:pr-16 md:text-sm sm:pointer-coarse:pr-20',
                                controlHeight.md,
                                error &&
                                    'border-destructive-text focus-visible:ring-destructive-text',
                                // The chosen label shown as the placeholder must read as text, not as a hint.
                                isOpen && chosen && 'placeholder:text-foreground',
                            )}
                        />
                        <div className="absolute inset-y-0 right-0.5 flex items-center gap-0.5">
                            {value && !disabled ? (
                                <IconButton
                                    type="button"
                                    size="sm"
                                    label={label ? `Hapus pilihan: ${label}` : 'Hapus pilihan'}
                                    onMouseDown={(event) => event.preventDefault()}
                                    // Reached by keyboard, the button leaves the search: the list closes.
                                    onFocus={() => handleOpenChange(false)}
                                    onClick={clear}
                                >
                                    <X className="h-4 w-4" />
                                </IconButton>
                            ) : null}
                            <ChevronsUpDown
                                aria-hidden="true"
                                className="pointer-events-none mr-2.5 h-4 w-4 text-muted-foreground"
                            />
                        </div>
                    </div>
                </PopoverAnchor>
                <PopoverContent
                    // A list of options, not the dialog Radix assumes.
                    role="presentation"
                    align="start"
                    sideOffset={4}
                    collisionPadding={8}
                    // Focus stays in the field; the options are reached with the arrow keys.
                    onOpenAutoFocus={(event) => event.preventDefault()}
                    onCloseAutoFocus={(event) => event.preventDefault()}
                    onInteractOutside={(event) => {
                        if (anchorRef.current?.contains(event.target as Node))
                            event.preventDefault();
                    }}
                    // A drag on the scrollbar must not take the focus out of the field.
                    onMouseDown={(event) => event.preventDefault()}
                    style={{ width: 'var(--radix-popover-trigger-width)' }}
                    className="max-h-60 max-w-(--radix-popover-content-available-width) overflow-y-auto p-1"
                >
                    {loading ? (
                        <p
                            role="status"
                            className="flex items-center gap-2 px-3 py-2 text-sm text-muted-foreground"
                        >
                            <Spinner size="sm" />
                            Mencari...
                        </p>
                    ) : null}
                    {!ready ? (
                        <p className="px-3 py-2 text-sm text-muted-foreground">
                            Ketik minimal {threshold} huruf untuk mencari.
                        </p>
                    ) : visible.length === 0 ? (
                        !loading && (
                            <p role="status" className="px-3 py-2 text-sm text-muted-foreground">
                                {emptyText}
                            </p>
                        )
                    ) : (
                        <ul id={listId} role="listbox" aria-label={label ?? placeholder}>
                            {runs.map((run, runIndex) => {
                                const items = run.items.map(({ option, index }) => (
                                    <li
                                        key={option.value}
                                        id={`${inputId}-option-${index}`}
                                        role="option"
                                        aria-selected={option.value === value}
                                        // The choice is made on click; the focus stays in the field.
                                        onMouseDown={(event) => event.preventDefault()}
                                        onClick={() => choose(option)}
                                        onMouseMove={() => setActive(index)}
                                        className={cn(
                                            'flex min-h-11 cursor-pointer flex-col justify-center rounded px-3 py-2 text-sm sm:min-h-9 sm:pointer-coarse:min-h-11',
                                            index === activeIndex &&
                                                'bg-accent text-accent-foreground',
                                            option.value === value && 'font-medium',
                                        )}
                                    >
                                        <span>{option.label}</span>
                                        {option.description && (
                                            <span className="text-xs text-muted-foreground">
                                                {option.description}
                                            </span>
                                        )}
                                    </li>
                                ));

                                if (run.group === '') return items;

                                const headingId = `${inputId}-group-${runIndex}`;

                                return (
                                    <li key={`group-${runIndex}`} role="presentation">
                                        <div
                                            id={headingId}
                                            role="presentation"
                                            className="px-3 pt-2 pb-1 text-xs font-medium text-muted-foreground"
                                        >
                                            {run.group}
                                        </div>
                                        <ul role="group" aria-labelledby={headingId}>
                                            {items}
                                        </ul>
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                </PopoverContent>
            </Popover>
            {error && (
                <p id={`${inputId}-error`} className="mt-1 text-sm text-destructive-text">
                    {error}
                </p>
            )}
            {!error && hint && (
                <p id={`${inputId}-hint`} className="mt-1 text-sm text-muted-foreground">
                    {hint}
                </p>
            )}
        </div>
    );
}
