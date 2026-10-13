import { cn } from '@/lib/utils';

interface LabelProps extends React.LabelHTMLAttributes<HTMLLabelElement> {
    required?: boolean;
}

export function Label({ htmlFor, children, required, className, ...props }: LabelProps) {
    return (
        <label
            htmlFor={htmlFor}
            className={cn(
                'block text-sm font-medium text-surface-700 dark:text-surface-300',
                className,
            )}
            {...props}
        >
            {children}
            {required && (
                <>
                    {' '}
                    <span className="text-destructive-text" aria-hidden="true">
                        *
                    </span>
                </>
            )}
        </label>
    );
}
