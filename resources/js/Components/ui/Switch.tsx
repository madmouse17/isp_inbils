import { cn } from '@/lib/utils';

interface SwitchProps {
    checked: boolean;
    onCheckedChange: (checked: boolean) => void;
    label?: string;
    disabled?: boolean;
}

export function Switch({ checked, onCheckedChange, label, disabled }: SwitchProps) {
    return (
        <label
            className={cn(
                'inline-flex items-center gap-3',
                disabled && 'cursor-not-allowed opacity-60',
            )}
        >
            <button
                type="button"
                role="switch"
                aria-checked={checked}
                aria-label={label}
                disabled={disabled}
                onClick={() => onCheckedChange(!checked)}
                onKeyDown={(e) => {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        onCheckedChange(!checked);
                    }
                }}
                // Off: the track carries a 3:1 control border and a darker thumb, so the state never rests on the fill alone.
                className={cn(
                    'relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full border-2 transition-colors focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-hidden disabled:cursor-not-allowed',
                    checked ? 'border-transparent bg-primary' : 'border-input bg-muted',
                )}
            >
                <span
                    className={cn(
                        'pointer-events-none inline-block h-5 w-5 rounded-full shadow-xs transition-transform',
                        checked ? 'translate-x-5 bg-white' : 'translate-x-0 bg-muted-foreground',
                    )}
                />
            </button>
            {label && <span className="text-sm font-medium text-foreground">{label}</span>}
        </label>
    );
}
