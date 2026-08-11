import React, { useMemo } from 'react';
import { ArrowDown, ArrowUp, ArrowUpDown } from 'lucide-react';
import { cn } from '@/lib/utils';
import { Button } from '@/Components/ui/Button';
import { NativeSelect } from '@/Components/ui';
import { Checkbox } from '@/Components/ui/Checkbox';
import { EmptyState } from '@/Components/ui/EmptyState';
import { Skeleton } from '@/Components/ui/Skeleton';
import { Table, TBody, TD, TH, THead, TR } from '@/Components/ui/Table';
import type { PaginationMeta } from '@/types';

export type SortDirection = 'asc' | 'desc';

export type PaginationShape<T = unknown> = {
    data?: T[];
} & Partial<PaginationMeta>;

export type PaginatorShape<T = unknown> = PaginationShape<T>;

export interface DataTableColumn<T> {
    id?: string;
    key?: keyof T | string;
    sortKey?: string;
    header?: React.ReactNode;
    label?: React.ReactNode;
    accessor?: keyof T | string;
    sortable?: boolean;
    className?: string;
    headerClassName?: string;
    cell?: (row: T) => React.ReactNode;
    render?: (row: T) => React.ReactNode;
}

/** @deprecated Prefer DataTableColumn — kept for existing imports. */
export type Column<T> = DataTableColumn<T>;

export interface DataTableProps<T> {
    columns: DataTableColumn<T>[];
    rows?: T[];
    data?: T[];
    pagination?: PaginationShape<T> | null;
    paginator?: PaginatorShape<T> | null;
    meta?: PaginationMeta | null;
    sort?: string | null;
    direction?: SortDirection | null;
    sortBy?: string | null;
    sortDir?: SortDirection | null;
    sortDirection?: SortDirection | null;
    onSort?: (column: string, sortDir?: SortDirection) => void;
    onPageChange?: (page: number) => void;
    onPerPageChange?: (perPage: number) => void;
    rowKey?: keyof T | ((row: T) => string | number);
    getRowId?: (row: T) => string | number;
    emptyText?: string;
    emptyTitle?: string;
    emptyDescription?: string;
    emptyAction?: React.ReactNode;
    loading?: boolean;
    isLoading?: boolean;
    selectable?: boolean;
    selectedKeys?: Array<string | number>;
    onSelectionChange?: (keys: Array<string | number>) => void;
    onRowClick?: (row: T) => void;
    className?: string;
    toolbar?: React.ReactNode;
}

function resolveColumnId<T>(column: DataTableColumn<T>, index: number): string {
    if (column.id) return column.id;
    if (column.sortKey) return column.sortKey;
    if (column.key != null) return String(column.key);
    if (column.accessor != null) return String(column.accessor);
    return `col-${index}`;
}

function resolveRowId<T>(
    row: T,
    index: number,
    rowKey?: DataTableProps<T>['rowKey'],
    getRowId?: DataTableProps<T>['getRowId'],
): string | number {
    if (getRowId) return getRowId(row);
    if (typeof rowKey === 'function') return rowKey(row);
    if (typeof rowKey === 'string') {
        const value = (row as Record<string, unknown>)[rowKey];
        if (value != null) return value as string | number;
    }
    const fallback = (row as { id?: string | number }).id;
    return fallback ?? index;
}

function readCellValue<T>(row: T, column: DataTableColumn<T>): React.ReactNode {
    if (column.cell) return column.cell(row);
    if (column.render) return column.render(row);
    const path = column.accessor ?? column.key;
    if (path == null) return null;
    const value = (row as Record<string, unknown>)[String(path)];
    if (value == null || value === '') return '—';
    if (typeof value === 'boolean') return value ? 'Yes' : 'No';
    if (typeof value === 'string' || typeof value === 'number' || typeof value === 'bigint') {
        return String(value);
    }
    return '—';
}

export function DataTable<T>({
    columns,
    rows,
    data,
    pagination,
    paginator,
    meta,
    sort,
    direction,
    sortBy,
    sortDir,
    sortDirection,
    onSort,
    onPageChange,
    onPerPageChange,
    rowKey,
    getRowId,
    emptyText = 'No data found.',
    emptyTitle,
    emptyDescription,
    emptyAction,
    loading,
    isLoading,
    selectable = false,
    selectedKeys = [],
    onSelectionChange,
    onRowClick,
    className,
    toolbar,
}: DataTableProps<T>) {
    const items = useMemo(
        () => rows ?? data ?? pagination?.data ?? paginator?.data ?? [],
        [rows, data, pagination?.data, paginator?.data],
    );
    const busy = loading ?? isLoading ?? false;
    const activeSort = sortBy ?? sort ?? null;
    const activeDirection = sortDir ?? sortDirection ?? direction ?? null;
    const page = pagination?.current_page ?? paginator?.current_page ?? meta?.current_page ?? 1;
    const lastPage = pagination?.last_page ?? paginator?.last_page ?? meta?.last_page ?? 1;
    const total = pagination?.total ?? paginator?.total ?? meta?.total ?? items.length;
    const perPage = pagination?.per_page ?? paginator?.per_page ?? meta?.per_page ?? items.length;
    const from =
        pagination?.from ??
        paginator?.from ??
        meta?.from ??
        (total > 0 ? (page - 1) * perPage + 1 : null);
    const to =
        pagination?.to ??
        paginator?.to ??
        meta?.to ??
        (total > 0 ? Math.min(page * perPage, total) : null);

    const selectedSet = useMemo(() => new Set(selectedKeys.map(String)), [selectedKeys]);
    const itemIds = useMemo(
        () => items.map((row, index) => resolveRowId(row, index, rowKey, getRowId)),
        [items, rowKey, getRowId],
    );
    const allSelected = itemIds.length > 0 && itemIds.every((id) => selectedSet.has(String(id)));
    const someSelected = itemIds.some((id) => selectedSet.has(String(id)));

    const toggleAll = (checked: boolean) => {
        if (!onSelectionChange) return;
        if (checked) {
            const next = new Set(selectedSet);
            itemIds.forEach((id) => next.add(String(id)));
            onSelectionChange(Array.from(next));
            return;
        }
        const drop = new Set(itemIds.map(String));
        onSelectionChange(selectedKeys.filter((id) => !drop.has(String(id))));
    };

    const toggleOne = (id: string | number, checked: boolean) => {
        if (!onSelectionChange) return;
        if (checked) {
            onSelectionChange([...selectedKeys, id]);
            return;
        }
        onSelectionChange(selectedKeys.filter((key) => String(key) !== String(id)));
    };

    const title = emptyTitle ?? emptyText;
    const description = emptyDescription ?? 'Try adjusting filters or create a new record.';

    const perPageOptions = [10, 25, 50];

    return (
        <div className={cn('space-y-3', className)}>
            {toolbar}
            <div className="overflow-x-auto rounded-lg border border-border">
                <Table>
                    <THead>
                        <TR>
                            {selectable && (
                                <TH className="w-10">
                                    <Checkbox
                                        checked={allSelected}
                                        indeterminate={!allSelected && someSelected}
                                        onCheckedChange={(value) => toggleAll(value === true)}
                                        aria-label="Select all rows"
                                    />
                                </TH>
                            )}
                            {columns.map((column, index) => {
                                const id = resolveColumnId(column, index);
                                const isActive = activeSort === id;
                                const sortable = Boolean(column.sortable && onSort);
                                const header = column.header ?? column.label ?? id;
                                const nextDirection: SortDirection =
                                    isActive && activeDirection === 'asc' ? 'desc' : 'asc';
                                return (
                                    <TH
                                        key={id}
                                        className={column.headerClassName}
                                        aria-sort={
                                            isActive
                                                ? activeDirection === 'asc'
                                                    ? 'ascending'
                                                    : 'descending'
                                                : undefined
                                        }
                                    >
                                        {sortable ? (
                                            <button
                                                type="button"
                                                className="inline-flex items-center gap-1 font-medium hover:text-foreground"
                                                onClick={() => onSort?.(id, nextDirection)}
                                            >
                                                <span>{header}</span>
                                                {isActive && activeDirection === 'asc' ? (
                                                    <ArrowUp className="h-3.5 w-3.5" aria-hidden />
                                                ) : isActive && activeDirection === 'desc' ? (
                                                    <ArrowDown
                                                        className="h-3.5 w-3.5"
                                                        aria-hidden
                                                    />
                                                ) : (
                                                    <ArrowUpDown
                                                        className="h-3.5 w-3.5 opacity-50"
                                                        aria-hidden
                                                    />
                                                )}
                                            </button>
                                        ) : (
                                            header
                                        )}
                                    </TH>
                                );
                            })}
                        </TR>
                    </THead>
                    <TBody>
                        {busy &&
                            Array.from({ length: 5 }).map((_, rowIndex) => (
                                <TR key={`skeleton-${rowIndex}`}>
                                    {selectable && (
                                        <TD>
                                            <Skeleton className="h-4 w-4" />
                                        </TD>
                                    )}
                                    {columns.map((column, colIndex) => (
                                        <TD key={resolveColumnId(column, colIndex)}>
                                            <Skeleton className="h-4 w-full" />
                                        </TD>
                                    ))}
                                </TR>
                            ))}
                        {!busy && items.length === 0 && (
                            <TR>
                                <TD colSpan={columns.length + (selectable ? 1 : 0)} className="p-0">
                                    <EmptyState
                                        title={title}
                                        description={description}
                                        action={emptyAction}
                                    />
                                </TD>
                            </TR>
                        )}
                        {!busy &&
                            items.map((row, index) => {
                                const id = resolveRowId(row, index, rowKey, getRowId);
                                const selected = selectedSet.has(String(id));
                                return (
                                    <TR
                                        key={String(id)}
                                        data-state={selected ? 'selected' : undefined}
                                        className={cn(onRowClick && 'cursor-pointer')}
                                        onClick={() => onRowClick?.(row)}
                                    >
                                        {selectable && (
                                            <TD onClick={(event) => event.stopPropagation()}>
                                                <Checkbox
                                                    checked={selected}
                                                    onCheckedChange={(value) =>
                                                        toggleOne(id, value === true)
                                                    }
                                                    aria-label={`Select row ${id}`}
                                                />
                                            </TD>
                                        )}
                                        {columns.map((column, colIndex) => (
                                            <TD
                                                key={resolveColumnId(column, colIndex)}
                                                className={column.className}
                                            >
                                                {readCellValue(row, column)}
                                            </TD>
                                        ))}
                                    </TR>
                                );
                            })}
                    </TBody>
                </Table>
            </div>
            {((onPageChange && lastPage > 1) || onPerPageChange) && (
                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <p className="text-sm text-muted-foreground">
                        {from != null && to != null
                            ? `Showing ${from}–${to} of ${total}`
                            : `${total} total`}
                    </p>
                    <div className="flex flex-wrap items-center gap-2">
                        {onPerPageChange && (
                            <NativeSelect
                                value={String(perPage)}
                                onChange={(event) => onPerPageChange(Number(event.target.value))}
                                options={perPageOptions.map((n) => ({
                                    value: String(n),
                                    label: `${n} / page`,
                                }))}
                                className="w-32"
                            />
                        )}
                        {onPageChange && (
                            <div className="flex items-center gap-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    disabled={page <= 1}
                                    onClick={() => onPageChange(page - 1)}
                                >
                                    Previous
                                </Button>
                                <span className="text-sm text-muted-foreground">
                                    Page {page} of {lastPage}
                                </span>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    disabled={page >= lastPage}
                                    onClick={() => onPageChange(page + 1)}
                                >
                                    Next
                                </Button>
                            </div>
                        )}
                    </div>
                </div>
            )}
        </div>
    );
}

export default DataTable;
