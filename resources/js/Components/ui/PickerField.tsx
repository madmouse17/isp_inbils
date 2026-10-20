import { useEffect, useId, useRef, useState } from 'react';
import type { KeyboardEvent, ReactNode } from 'react';
import { X } from 'lucide-react';
import { cn } from '@/lib/utils';
import { IconButton } from './IconButton';
import { Input } from './Input';
import { Popover, PopoverAnchor, PopoverContent, PopoverTrigger } from './popover';

export type PickerParse = { ok: true; value: string } | { ok: false; message: string };

/** The props every picker takes besides its own value. */
export interface PickerFieldShared {
    label?: string;
    id?: string;
    /** Server-side message; a typed entry that does not parse shows its own message first. */
    error?: string;
    hint?: string;
    disabled?: boolean;
    /** Marks the label and tells assistive technology; the server still validates. */
    required?: boolean;
    placeholder?: string;
    className?: string;
}

interface PickerFieldProps extends PickerFieldShared {
    /** The value the server takes, or `''` for none. */
    value: string;
    onChange: (value: string) => void;
    /** The value as the field writes it; `''` for a value that is not a real one. */
    format: (value: string) => string;
    /** Typed text as a value (`''` for blank), or the message that says what is wrong with it. */
    parse: (text: string) => PickerParse;
    icon: ReactNode;
    /** Accessible name of the text field when there is no visible label. */
    fallbackLabel: string;
    openLabel: string;
    clearLabel: string;
    popoverLabel: string;
    popoverClassName?: string;
    /** The popover body. `commit` writes a picked value as if it had been typed; `close` shuts the popover. */
    children: (api: { commit: (value: string) => void; close: () => void }) => ReactNode;
}

/**
 * The shell every date and time picker shares with `DateRangePicker`: a text field that is typed
 * in the company format, committed on blur or Enter and only when it is a real value, with a
 * button that opens the picker popover and one that clears. The picker supplies how a value is
 * written and read, and the popover body.
 */
export function PickerField({
    label,
    id,
    error,
    hint,
    disabled = false,
    required = false,
    placeholder,
    className,
    value,
    onChange,
    format,
    parse,
    icon,
    fallbackLabel,
    openLabel,
    clearLabel,
    popoverLabel,
    popoverClassName,
    children,
}: PickerFieldProps) {
    const generatedId = useId();
    const inputId = id ?? generatedId;
    const errorId = `${inputId}-error`;
    const hintId = `${inputId}-hint`;
    const inputRef = useRef<HTMLInputElement>(null);

    const shown = format(value);
    const [text, setText] = useState(shown);
    const [lastShown, setLastShown] = useState(shown);
    const [typedError, setTypedError] = useState<string | null>(null);
    const [open, setOpen] = useState(false);

    // The value changed from outside (a visit, a reset): show it and drop what was typed.
    if (shown !== lastShown) {
        setLastShown(shown);
        setText(shown);
        setTypedError(null);
    }

    const commit = (next: string) => {
        setText(format(next));
        setTypedError(null);
        if (next !== value) onChange(next);
    };

    const commitText = () => {
        const result = parse(text);
        if (result.ok) commit(result.value);
        else setTypedError(result.message);
    };

    // A text that is not a real value must not let the surrounding form submit: the form would save the old
    // value in its place, and the typed one is lost without a word. Only while the text is invalid.
    useEffect(() => {
        const form = inputRef.current?.form;
        if (typedError === null || !form) return;

        const block = (event: SubmitEvent) => {
            event.preventDefault();
            // React listens on the root, above the form: stopping the event here keeps `onSubmit` from running.
            event.stopPropagation();
            inputRef.current?.focus();
        };
        form.addEventListener('submit', block);

        return () => form.removeEventListener('submit', block);
    }, [typedError]);

    const pending = text !== shown;

    const handleKeyDown = (event: KeyboardEvent<HTMLInputElement>) => {
        // A typed entry is committed first; a surrounding form must not submit the old value.
        if (event.key === 'Enter' && pending && !event.nativeEvent.isComposing) {
            event.preventDefault();
            commitText();
        }
    };

    const clear = () => {
        commit('');
        inputRef.current?.focus();
    };

    const message = typedError ?? error;
    const hasContent = text.trim() !== '' || value !== '';
    // Buttons repeat on a page with several fields, so each one carries its field's name.
    const named = (name: string) => (label ? `${name}: ${label}` : name);

    return (
        <div className={cn('space-y-1.5 w-full', className)}>
            {label ? (
                <label htmlFor={inputId} className="text-sm leading-none font-medium">
                    {label}
                    {required ? (
                        <span aria-hidden="true" className="text-destructive-text">
                            {' '}
                            *
                        </span>
                    ) : null}
                </label>
            ) : null}
            <Popover open={open} onOpenChange={setOpen}>
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
                            placeholder={placeholder}
                            autoComplete="off"
                            spellCheck={false}
                            disabled={disabled}
                            aria-label={label ? undefined : fallbackLabel}
                            aria-required={required || undefined}
                            aria-invalid={message ? true : undefined}
                            aria-describedby={message ? errorId : hint ? hintId : undefined}
                            // Room for both buttons: 44px each on touch, 32px with a mouse.
                            className={cn(
                                'pr-24 sm:pr-18 sm:pointer-coarse:pr-24',
                                message &&
                                    'border-destructive-text focus-visible:ring-destructive-text',
                            )}
                        />
                        <div className="absolute inset-y-0 right-0.5 flex items-center gap-0.5">
                            {hasContent && !disabled ? (
                                <IconButton
                                    type="button"
                                    size="sm"
                                    label={named(clearLabel)}
                                    onClick={clear}
                                >
                                    <X className="h-4 w-4" />
                                </IconButton>
                            ) : null}
                            <PopoverTrigger asChild>
                                <IconButton
                                    type="button"
                                    size="sm"
                                    label={named(openLabel)}
                                    disabled={disabled}
                                >
                                    {icon}
                                </IconButton>
                            </PopoverTrigger>
                        </div>
                    </div>
                </PopoverAnchor>
                <PopoverContent
                    align="start"
                    sideOffset={6}
                    collisionPadding={8}
                    aria-label={named(popoverLabel)}
                    // The body focuses its own day, month or slot; Radix would focus its first button.
                    onOpenAutoFocus={(event) => event.preventDefault()}
                    className={cn(
                        'max-h-(--radix-popover-content-available-height) w-auto max-w-(--radix-popover-content-available-width) overflow-auto p-2 sm:p-3',
                        popoverClassName,
                    )}
                >
                    {children({ commit, close: () => setOpen(false) })}
                </PopoverContent>
            </Popover>
            {message ? (
                <p id={errorId} role="alert" className="text-sm text-destructive-text">
                    {message}
                </p>
            ) : null}
            {!message && hint ? (
                <p id={hintId} className="text-sm text-muted-foreground">
                    {hint}
                </p>
            ) : null}
        </div>
    );
}
