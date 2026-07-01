import { Pagination, Table, TBody, TD, TH, THead, TR } from '@/Components/ui';

export type SortDirection = 'asc' | 'desc';

export interface DataTableColumn<T> {
    key: Extract<keyof T, string> | string;
    label: string;
    sortable?: boolean;
    render?: (row: T) => React.ReactNode;
}

export interface PaginatorShape<T> {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

interface DataTableProps<T extends object> {
    columns: DataTableColumn<T>[];
    data?: T[];
    pagination?: PaginatorShape<T>;
    sortKey?: string;
    sortDirection?: SortDirection;
    emptyText?: string;
    filters?: React.ReactNode;
    onSort?: (key: string, direction: SortDirection) => void;
    onPageChange?: (page: number) => void;
}

export function DataTable<T extends object>({
    columns,
    data,
    pagination,
    sortKey,
    sortDirection = 'asc',
    emptyText = 'No data found.',
    filters,
    onSort,
    onPageChange,
}: DataTableProps<T>) {
    const rows = pagination?.data ?? data ?? [];

    const nextDirection = (key: string): SortDirection => (sortKey === key && sortDirection === 'asc' ? 'desc' : 'asc');

    return (
        <div className="space-y-3">
            {filters && <div className="rounded-xl border border-surface-200 bg-white p-4 dark:border-surface-800 dark:bg-surface-900">{filters}</div>}
            <Table>
                <THead>
                    <TR>
                        {columns.map((column) => (
                            <TH key={column.key}>
                                {column.sortable && onSort ? (
                                    <button
                                        type="button"
                                        onClick={() => onSort(column.key, nextDirection(column.key))}
                                        className="inline-flex items-center gap-1 rounded text-left hover:text-surface-700 focus:outline-none focus:ring-2 focus:ring-brand-500/40 dark:hover:text-surface-200"
                                    >
                                        {column.label}
                                        {sortKey === column.key && <span aria-hidden="true">{sortDirection === 'asc' ? '↑' : '↓'}</span>}
                                    </button>
                                ) : (
                                    column.label
                                )}
                            </TH>
                        ))}
                    </TR>
                </THead>
                <TBody>
                    {rows.length === 0 ? (
                        <TR>
                            <TD colSpan={columns.length} className="text-center text-surface-500 dark:text-surface-400">
                                {emptyText}
                            </TD>
                        </TR>
                    ) : (
                        rows.map((row, index) => (
                            <TR key={String((row as Record<string, unknown>).id ?? index)}>
                                {columns.map((column) => (
                                    <TD key={column.key}>{column.render ? column.render(row) : String((row as Record<string, unknown>)[column.key] ?? '')}</TD>
                                ))}
                            </TR>
                        ))
                    )}
                </TBody>
            </Table>
            {pagination && onPageChange && (
                <div className="flex flex-col gap-3 text-sm text-surface-500 dark:text-surface-400 sm:flex-row sm:items-center sm:justify-between">
                    <span>
                        {pagination.total} rows, {pagination.per_page} per page
                    </span>
                    <Pagination currentPage={pagination.current_page} lastPage={pagination.last_page} onPageChange={onPageChange} />
                </div>
            )}
        </div>
    );
}
