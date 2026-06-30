import { cn } from '@/lib/utils';

interface TableProps extends React.TableHTMLAttributes<HTMLTableElement> {}

export function Table({ className, children, ...props }: TableProps) {
    return (
        <div className="overflow-x-auto rounded-xl border border-surface-200 dark:border-surface-800">
            <table className={cn('w-full text-sm', className)} {...props}>
                {children}
            </table>
        </div>
    );
}

interface THeadProps extends React.HTMLAttributes<HTMLTableSectionElement> {}

export function THead({ className, ...props }: THeadProps) {
    return <thead className={cn('border-b border-surface-200 bg-surface-50 dark:border-surface-800 dark:bg-surface-900', className)} {...props} />;
}

interface TBodyProps extends React.HTMLAttributes<HTMLTableSectionElement> {}

export function TBody({ className, ...props }: TBodyProps) {
    return <tbody className={cn('divide-y divide-surface-100 dark:divide-surface-800', className)} {...props} />;
}

interface TRProps extends React.HTMLAttributes<HTMLTableRowElement> {}

export function TR({ className, ...props }: TRProps) {
    return <tr className={cn('hover:bg-surface-50 dark:hover:bg-surface-800/50', className)} {...props} />;
}

interface THProps extends React.ThHTMLAttributes<HTMLTableCellElement> {}

export function TH({ className, ...props }: THProps) {
    return (
        <th
            className={cn(
                'px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-surface-500 dark:text-surface-400',
                className,
            )}
            {...props}
        />
    );
}

interface TDProps extends React.TdHTMLAttributes<HTMLTableCellElement> {}

export function TD({ className, ...props }: TDProps) {
    return <td className={cn('px-4 py-3 text-surface-700 dark:text-surface-300', className)} {...props} />;
}
