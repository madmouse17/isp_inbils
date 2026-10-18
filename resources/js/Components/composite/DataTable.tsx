import React, { useMemo, useState } from 'react';
import { AlertCircle, ArrowDown, ArrowUp, ArrowUpDown, Inbox, SearchX } from 'lucide-react';
import { cn } from '@/lib/utils';
import { Button } from '@/Components/ui/Button';
import { NativeSelect } from '@/Components/ui';
import { Checkbox } from '@/Components/ui/Checkbox';
import { EmptyCell } from '@/Components/ui/EmptyCell';
import { EmptyState } from '@/Components/ui/EmptyState';
import { Skeleton } from '@/Components/ui/Skeleton';
import { Table, TableFooter, TBody, TD, TH, THead, TR } from '@/Components/ui/Table';
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
    /** First-run title: nothing has been recorded yet. */
    emptyText?: string;
    emptyTitle?: string;
    /** First-run reason. Say why the list is empty and what fills it. */
    emptyDescription?: string;
    /** First-run action, normally the create button. */
    emptyAction?: React.ReactNode;
    /** A filter or search is active, so an empty page means "nothing matches", not "nothing exists". */
    hasFilters?: boolean;
    /** Clears the filters; shown as "Reset filter" beside the "nothing matches" text. */
    onResetFilters?: () => void;
    /** What the rows are, lower case ("pelanggan"): "Tidak ada pelanggan yang cocok". */
    itemLabel?: string;
    /** The load failed. A string is shown as the cause. */
    error?: boolean | string;
    /** Loads again; shown as "Coba lagi" in the error state. */
    onRetry?: () => void;
    loading?: boolean;
    isLoading?: boolean;
    selectable?: boolean;
    selectedKeys?: Array<string | number>;
    onSelectionChange?: (keys: Array<string | number>) => void;
    onRowClick?: (row: T) => void;
    className?: string;
    toolbar?: React.ReactNode;
    /** Pinned rows rendered in a `<tfoot>` below the body (e.g. totals). Omit for no footer. */
    footer?: React.ReactNode;
}

function resolveColumnId<T>(column: DataTableColumn<T>, index: number): string {
    if (column.id) return column.id;
    if (column.sortKey) return column.sortKey;
    if (column.key != null) return String(column.key);
    if (column.accessor != null) return String(column.accessor);
    return `col-${index}`;
}

/** Rows are caller-defined shapes that the table reads by column key at runtime; this is the one place that trusts the key. */
function readField(row: unknown, key: string): unknown {
    if (typeof row !== 'object' || row === null) return undefined;

    return (row as Record<string, unknown>)[key];
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
        const value = readField(row, rowKey);
        if (typeof value === 'string' || typeof value === 'number') return value;
    }
    const fallback = readField(row, 'id');
    return typeof fallback === 'string' || typeof fallback === 'number' ? fallback : index;
}

function readCellValue<T>(row: T, column: DataTableColumn<T>): React.ReactNode {
    if (column.cell) return column.cell(row);
    if (column.render) return column.render(row);
    const path = column.accessor ?? column.key;
    if (path == null) return null;
    const value = readField(row, String(path));
    if (value == null || value === '') return <EmptyCell />;
    if (typeof value === 'boolean') return value ? 'Ya' : 'Tidak';
    if (typeof value === 'string' || typeof value === 'number' || typeof value === 'bigint') {
        return String(value);
    }
    return <EmptyCell />;
}

/** Skeleton rows follow the page size, bounded so a 50-row page does not render a 50-row placeholder. */
const SKELETON_ROWS = { min: 3, max: 10 } as const;

/** Empty until the first load starts, so a table that renders idle announces nothing. */
function useLoadingAnnouncement(busy: boolean): string {
    const [wasBusy, setWasBusy] = useState(busy);
    const [announcement, setAnnouncement] = useState('');

    if (busy !== wasBusy) {
        setWasBusy(busy);
        setAnnouncement(busy ? 'Memuat data…' : 'Data dimuat');
    }

    return announcement;
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
    emptyText = 'Tidak ada data.',
    emptyTitle,
    emptyDescription,
    emptyAction,
    hasFilters = false,
    onResetFilters,
    itemLabel,
    error,
    onRetry,
    loading,
    isLoading,
    selectable = false,
    selectedKeys = [],
    onSelectionChange,
    onRowClick,
    className,
    toolbar,
    footer,
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
    const skeletonRows = Math.min(SKELETON_ROWS.max, Math.max(SKELETON_ROWS.min, perPage || 0));
    const loadingAnnouncement = useLoadingAnnouncement(busy);

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

    const perPageOptions = [10, 25, 50];
    const columnSpan = columns.length + (selectable ? 1 : 0);

    return (
        <div className={cn('space-y-3', className)}>
            {toolbar}
            <div className="overflow-x-auto rounded-lg border border-border">
                <Table aria-busy={busy || undefined}>
                    <THead>
                        <TR>
                            {selectable && (
                                <TH className="w-10">
                                    <Checkbox
                                        checked={allSelected}
                                        indeterminate={!allSelected && someSelected}
                                        onCheckedChange={(value) => toggleAll(value === true)}
                                        aria-label="Pilih semua baris"
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
                                                    <ArrowUp
                                                        className="h-3.5 w-3.5 text-foreground"
                                                        aria-hidden
                                                    />
                                                ) : isActive && activeDirection === 'desc' ? (
                                                    <ArrowDown
                                                        className="h-3.5 w-3.5 text-foreground"
                                                        aria-hidden
                                                    />
                                                ) : (
                                                    <ArrowUpDown
                                                        className="h-3.5 w-3.5"
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
                            Array.from({ length: skeletonRows }).map((_, rowIndex) => (
                                <TR
                                    key={`skeleton-${rowIndex}`}
                                    aria-hidden="true"
                                    className="h-12 hover:bg-transparent"
                                >
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
                        {!busy && error && (
                            <TR className="hover:bg-transparent">
                                <TD colSpan={columnSpan} className="p-0">
                                    <div role="alert">
                                        <EmptyState
                                            icon={<AlertCircle />}
                                            title="Data tidak dapat dimuat"
                                            description={
                                                typeof error === 'string'
                                                    ? error
                                                    : 'Terjadi kesalahan saat memuat data. Coba lagi, atau muat ulang halaman jika masalah berlanjut.'
                                            }
                                            action={
                                                onRetry ? (
                                                    <Button
                                                        type="button"
                                                        variant="outline"
                                                        onClick={onRetry}
                                                    >
                                                        Coba lagi
                                                    </Button>
                                                ) : undefined
                                            }
                                        />
                                    </div>
                                </TD>
                            </TR>
                        )}
                        {!busy && !error && items.length === 0 && (
                            <TR className="hover:bg-transparent">
                                <TD colSpan={columnSpan} className="p-0">
                                    {hasFilters ? (
                                        <EmptyState
                                            icon={<SearchX />}
                                            title={`Tidak ada ${itemLabel ?? 'data'} yang cocok`}
                                            description="Ubah kata kunci atau filter, atau kembalikan semuanya ke awal."
                                            action={
                                                onResetFilters ? (
                                                    <Button
                                                        type="button"
                                                        variant="outline"
                                                        onClick={onResetFilters}
                                                    >
                                                        Reset filter
                                                    </Button>
                                                ) : (
                                                    emptyAction
                                                )
                                            }
                                        />
                                    ) : (
                                        <EmptyState
                                            icon={<Inbox />}
                                            title={emptyTitle ?? emptyText}
                                            description={
                                                emptyDescription ??
                                                (itemLabel
                                                    ? `Belum ada ${itemLabel} yang tercatat.`
                                                    : 'Belum ada data yang tercatat.')
                                            }
                                            action={emptyAction}
                                        />
                                    )}
                                </TD>
                            </TR>
                        )}
                        {!busy &&
                            !error &&
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
                                                    aria-label={`Pilih baris ${id}`}
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
                    {footer && <TableFooter>{footer}</TableFooter>}
                </Table>
            </div>
            {!error && total > 0 && ((onPageChange && lastPage > 1) || onPerPageChange) && (
                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <p className="text-sm text-muted-foreground">
                        {from != null && to != null
                            ? `Menampilkan ${from}–${to} dari ${total}`
                            : `Total ${total}`}
                    </p>
                    <div className="flex flex-wrap items-center gap-2">
                        {onPerPageChange && (
                            <NativeSelect
                                aria-label="Baris per halaman"
                                value={String(perPage)}
                                onChange={(event) => onPerPageChange(Number(event.target.value))}
                                options={perPageOptions.map((n) => ({
                                    value: String(n),
                                    label: `${n} / halaman`,
                                }))}
                                wrapperClassName="w-36"
                            />
                        )}
                        {onPageChange && (
                            <div className="flex items-center gap-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    aria-label="Halaman sebelumnya"
                                    disabled={page <= 1}
                                    onClick={() => onPageChange(page - 1)}
                                >
                                    Sebelumnya
                                </Button>
                                <span className="text-sm text-muted-foreground">
                                    Halaman {page} dari {lastPage}
                                </span>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    aria-label="Halaman berikutnya"
                                    disabled={page >= lastPage}
                                    onClick={() => onPageChange(page + 1)}
                                >
                                    Berikutnya
                                </Button>
                            </div>
                        )}
                    </div>
                </div>
            )}
            <div role="status" aria-live="polite" className="sr-only">
                {loadingAnnouncement}
            </div>
        </div>
    );
}

export default DataTable;
