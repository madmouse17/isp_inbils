import { ChevronUpDownIcon, XMarkIcon } from '@heroicons/react/20/solid';
import { useEffect, useMemo, useState } from 'react';
import { cn } from '@/lib/utils';
import { Label } from './Label';

export interface SearchSelectOption {
    value: string;
    label: string;
    description?: string;
}

export interface SearchSelectProps {
    label?: string;
    value: string;
    onChange: (value: string) => void;
    options: SearchSelectOption[];
    placeholder?: string;
    emptyText?: string;
    error?: string;
    hint?: string;
    disabled?: boolean;
    required?: boolean;
    className?: string;
}

export function SearchSelect({
    label,
    value,
    onChange,
    options,
    placeholder = 'Search...',
    emptyText = 'No options found.',
    error,
    hint,
    disabled,
    required,
    className,
}: SearchSelectProps) {
    const [open, setOpen] = useState(false);
    const selectedOption = options.find((option) => option.value === value);
    const [query, setQuery] = useState(selectedOption?.label ?? '');
    const inputId = label?.toLowerCase().replace(/\s+/g, '-');
    const filteredOptions = useMemo(() => {
        const needle = query.trim().toLowerCase();

        if (!needle || selectedOption?.label === query) {
            return options;
        }

        return options.filter((option) =>
            `${option.label} ${option.description ?? ''}`.toLowerCase().includes(needle),
        );
    }, [options, query, selectedOption?.label]);

    useEffect(() => {
        setQuery(selectedOption?.label ?? '');
    }, [selectedOption?.label]);

    const select = (option: SearchSelectOption) => {
        onChange(option.value);
        setQuery(option.label);
        setOpen(false);
    };

    const clear = () => {
        onChange('');
        setQuery('');
        setOpen(false);
    };

    return (
        <div className={cn('relative w-full', className)}>
            {label && (
                <Label htmlFor={inputId} required={required}>
                    {label}
                </Label>
            )}
            <div className="relative mt-1">
                <input
                    id={inputId}
                    name={`${inputId ?? 'search-select'}-lookup`}
                    autoComplete="new-password"
                    spellCheck={false}
                    value={query}
                    onChange={(event) => {
                        setQuery(event.target.value);
                        onChange('');
                        setOpen(true);
                    }}
                    onFocus={() => setOpen(true)}
                    onBlur={() => window.setTimeout(() => setOpen(false), 120)}
                    placeholder={placeholder}
                    disabled={disabled}
                    role="combobox"
                    aria-expanded={open}
                    aria-invalid={error ? 'true' : undefined}
                    className={cn(
                        'h-9 w-full rounded-md border border-input bg-background px-3 py-1 pr-16 text-sm text-foreground shadow-sm transition-colors placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50',
                        error && 'border-destructive focus-visible:ring-destructive',
                    )}
                />
                {value && !disabled && (
                    <button
                        type="button"
                        onMouseDown={(event) => event.preventDefault()}
                        onClick={clear}
                        className="absolute right-9 top-1/2 -translate-y-1/2 rounded p-0.5 text-muted-foreground hover:text-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                        aria-label="Clear selection"
                    >
                        <XMarkIcon className="h-4 w-4" />
                    </button>
                )}
                <ChevronUpDownIcon className="pointer-events-none absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                {open && !disabled && (
                    <div className="absolute z-50 mt-1 max-h-56 w-full overflow-y-auto rounded-md border border-border bg-popover p-1 text-popover-foreground shadow-lg">
                        {filteredOptions.length === 0 ? (
                            <p className="px-3 py-2 text-sm text-muted-foreground">{emptyText}</p>
                        ) : (
                            filteredOptions.map((option) => (
                                <button
                                    key={option.value}
                                    type="button"
                                    onMouseDown={(event) => event.preventDefault()}
                                    onClick={() => select(option)}
                                    className={cn(
                                        'w-full rounded px-3 py-2 text-left text-sm transition-colors hover:bg-accent hover:text-accent-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring',
                                        option.value === value && 'bg-accent text-accent-foreground',
                                    )}
                                >
                                    <span className="block font-medium">{option.label}</span>
                                    {option.description && (
                                        <span className="block text-xs text-muted-foreground">
                                            {option.description}
                                        </span>
                                    )}
                                </button>
                            ))
                        )}
                    </div>
                )}
            </div>
            {error && <p className="mt-1 text-sm text-destructive">{error}</p>}
            {!error && hint && <p className="mt-1 text-sm text-muted-foreground">{hint}</p>}
        </div>
    );
}
