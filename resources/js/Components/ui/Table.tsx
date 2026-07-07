import { cn } from '@/lib/utils';

interface TableProps extends React.TableHTMLAttributes<HTMLTableElement> {}

export function Table({ className, children, ...props }: TableProps) {
    return (
        <div className="w-full overflow-auto rounded-md border border-border">
            <table className={cn('w-full caption-bottom text-sm', className)} {...props}>
                {children}
            </table>
        </div>
    );
}

interface THeadProps extends React.HTMLAttributes<HTMLTableSectionElement> {}

export function THead({ className, ...props }: THeadProps) {
    return <thead className={cn('[&_tr]:border-b bg-muted/50', className)} {...props} />;
}

interface TBodyProps extends React.HTMLAttributes<HTMLTableSectionElement> {}

export function TBody({ className, ...props }: TBodyProps) {
    return <tbody className={cn('[&_tr:last-child]:border-0', className)} {...props} />;
}

interface TRProps extends React.HTMLAttributes<HTMLTableRowElement> {}

export function TR({ className, ...props }: TRProps) {
    return <tr className={cn('border-b transition-colors hover:bg-muted/50 data-[state=selected]:bg-muted', className)} {...props} />;
}

interface THProps extends React.ThHTMLAttributes<HTMLTableCellElement> {}

export function TH({ className, ...props }: THProps) {
    return (
        <th
            className={cn(
                'h-10 px-3 text-left align-middle text-xs font-medium uppercase tracking-wide text-muted-foreground',
                className,
            )}
            {...props}
        />
    );
}

interface TDProps extends React.TdHTMLAttributes<HTMLTableCellElement> {}

export function TD({ className, ...props }: TDProps) {
    return <td className={cn('p-3 align-middle text-sm', className)} {...props} />;
}
