import { cn } from '@/lib/utils';

interface SwitchProps {
    checked: boolean;
    onCheckedChange: (checked: boolean) => void;
    label?: string;
    disabled?: boolean;
}

export function Switch({ checked, onCheckedChange, label, disabled }: SwitchProps) {
    return (
        <label className={cn('inline-flex items-center gap-3', disabled && 'opacity-60 cursor-not-allowed')}>
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
                className={cn(
                    'relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors focus:outline-none focus:ring-2 focus:ring-brand-500/50 disabled:cursor-not-allowed',
                    checked ? 'bg-brand-600' : 'bg-surface-200 dark:bg-surface-700',
                )}
            >
                <span
                    className={cn(
                        'pointer-events-none inline-block h-5 w-5 rounded-full bg-white shadow-sm transition-transform',
                        checked ? 'translate-x-5' : 'translate-x-0',
                    )}
                />
            </button>
            {label && <span className="text-sm font-medium text-surface-700 dark:text-surface-300">{label}</span>}
        </label>
    );
}
