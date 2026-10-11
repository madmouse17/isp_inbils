import { Label } from '@/Components/ui';

interface FormFieldProps {
    label: string;
    htmlFor?: string;
    error?: string;
    required?: boolean;
    children: React.ReactNode;
}

export function FormField({ label, htmlFor, error, required, children }: FormFieldProps) {
    return (
        <div className="space-y-1">
            <Label htmlFor={htmlFor} required={required}>
                {label}
            </Label>
            {children}
            {error && <p className="text-sm text-red-700 dark:text-red-400">{error}</p>}
        </div>
    );
}
