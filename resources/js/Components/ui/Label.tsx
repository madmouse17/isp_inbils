import { cn } from '@/lib/utils';

interface LabelProps extends React.LabelHTMLAttributes<HTMLLabelElement> {
    required?: boolean;
}

export function Label({ htmlFor, children, required, className, ...props }: LabelProps) {
    return (
        <label
            htmlFor={htmlFor}
            className={cn('block text-sm font-medium text-foreground', className)}
            {...props}
        >
            {children}
            {required && <span className="ml-1 text-destructive">*</span>}
        </label>
    );
}
