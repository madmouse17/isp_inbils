import { type FormEvent, useCallback, useId, useMemo, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    Check,
    Clock,
    Copy,
    Eye,
    FileText,
    Filter,
    RefreshCw,
    RotateCcw,
    Search,
    ShieldAlert,
    Tag,
    User as UserIcon,
} from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import {
    DataTable,
    type DataTableColumn,
    NativeSelect,
    PageHeader,
    type RemoteOption,
    RemoteSearchSelect,
} from '@/Components/composite';
import {
    Alert,
    AlertDescription,
    AlertTitle,
    Badge,
    Button,
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
    DateRangePicker,
    EmptyState,
    EnumBadge,
    ExportMenu,
    Input,
    Modal,
    Pagination,
    SearchSelect,
    Table,
    TBody,
    TD,
    TH,
    THead,
    TR,
} from '@/Components/ui';
import { cn } from '@/lib/utils';
import { presetRange, todayInZone } from '@/lib/dateRange';
import { formatDateTime } from '@/lib/format';
import { AUDIT_MODULE, labelOf } from '@/lib/statuses';
import type { StatusMap } from '@/lib/statuses/types';
import { useServerTable } from '@/hooks/useServerTable';
import { useCompany } from '@/hooks/useCompany';
import type { PageProps } from '@/types';

export interface AuditLogCauser {
    id: string;
    name: string;
    email?: string;
}

export interface AuditLogRow extends Record<string, unknown> {
    id: string;
    company_id: string | null;
    log_name: string;
    description: string;
    /** The sentence the server words for a support entry; null for every other entry. */
    support_text: string | null;
    /** The one sentence of the entry, worded by the server for every kind of entry. */
    text: string;
    event: string | null;
    causer_id: string | null;
    causer_type: string | null;
    causer: AuditLogCauser | null;
    subject_label: string | null;
    subject_type: string | null;
    subject_id: string | null;
    properties?: Record<string, unknown> | unknown[];
    created_at: string;
}

export interface AuditLogFilters {
    user_id?: string;
    log_name?: string;
    event?: string;
    date_from?: string;
    date_to?: string;
    search?: string;
    sort?: string;
    sort_by?: string;
    direction?: 'asc' | 'desc';
    sort_dir?: 'asc' | 'desc';
    page?: number | string;
    per_page?: number | string;
    limit?: number | string;
}

export interface PaginationMeta {
    current_page: number;
    from: number | null;
    last_page: number;
    per_page: number;
    to: number | null;
    total: number;
    links?: Array<{ url: string | null; label: string; active: boolean }>;
}

export interface PaginatedData<T> {
    data: T[];
    meta?: PaginationMeta;
    links?: Record<string, unknown>;
}

export interface AuditLogProps {
    data?: AuditLogRow[] | PaginatedData<AuditLogRow>;
    filters: AuditLogFilters;
    userOptions?: RemoteOption[];
    selectedUser?: RemoteOption | null;
    can?: {
        export?: boolean;
        view_properties?: boolean;
    };
}

const PER_PAGE_OPTIONS = ['10', '15', '25', '50', '100'].map((value) => ({ value, label: value }));

function isPlainObject(val: unknown): val is Record<string, unknown> {
    return val !== null && typeof val === 'object' && !Array.isArray(val);
}

// The tenant query masks the operator of a `support` entry as "Tim dukungan Inbils"; the server words the entry itself.
const MODULES = {
    ...AUDIT_MODULE,
    support: { label: 'Akses dukungan', tone: 'neutral' },
} satisfies StatusMap;

function ModuleBadge({ value }: { value: string | null | undefined }) {
    if (!value) return <Badge variant="outline">Umum</Badge>;

    return <EnumBadge map={MODULES} value={value} />;
}

export default function AuditLog({ data, filters, userOptions, selectedUser, can }: AuditLogProps) {
    const { auth, errors } = usePage<PageProps>().props;
    const company = useCompany();
    const searchInputId = useId();
    const dateRangeId = useId();

    const userPermissions = auth.user?.permissions ?? [];
    const canViewAudit = userPermissions.includes('report.audit.view');
    const canExport = can?.export === true;

    const {
        filters: params,
        setMany,
        processing,
        visiting,
    } = useServerTable({
        url: route('admin.reports.audit-log'),
        filters: {
            user_id: filters.user_id ?? '',
            log_name: filters.log_name ?? '',
            date_from: filters.date_from ?? '',
            date_to: filters.date_to ?? '',
            search: filters.search ?? '',
            sort: filters.sort ?? filters.sort_by ?? 'created_at',
            direction: filters.direction ?? filters.sort_dir ?? 'desc',
            page: filters.page ? Number(filters.page) : 1,
            per_page: filters.per_page ? Number(filters.per_page) : 15,
        },
        only: ['data', 'filters', 'selectedUser'],
    });

    // Local form state for manual submission
    const [localSearch, setLocalSearch] = useState<string>(String(params.search ?? ''));
    const [localLogName, setLocalLogName] = useState<string>(String(params.log_name ?? ''));
    const [localUserId, setLocalUserId] = useState<string>(String(params.user_id ?? ''));
    const [localDateFrom, setLocalDateFrom] = useState<string>(String(params.date_from ?? ''));
    const [localDateTo, setLocalDateTo] = useState<string>(String(params.date_to ?? ''));

    // The audit page always has a period: no start means the 7 days up to the end, as the server does.
    const startOrDefault = (from: string, to: string) =>
        from || presetRange('last7', to || todayInZone(company?.timezone)).from || '';

    // Sorting & Pagination local state
    const [sortBy, setSortBy] = useState<string>(String(params.sort ?? 'created_at'));
    const [sortDir, setSortDir] = useState<'asc' | 'desc'>(
        params.direction === 'asc' ? 'asc' : 'desc',
    );
    const [currentPage, setCurrentPage] = useState<number>(params.page ? Number(params.page) : 1);
    const [perPage, setPerPage] = useState<number>(params.per_page ? Number(params.per_page) : 15);

    // Selected row for property disclosure modal
    const [selectedRow, setSelectedRow] = useState<AuditLogRow | null>(null);
    const [copiedJson, setCopiedJson] = useState(false);

    // Extract raw rows
    const rawRows: AuditLogRow[] = useMemo(() => {
        if (!data) return [];
        if (Array.isArray(data)) return data;
        if (Array.isArray(data.data)) return data.data;
        return [];
    }, [data]);

    // FAIL-CLOSED SECURITY GUARD:
    // Legacy rows without company_id (company_id === null) must NEVER be exposed in the UI.
    const safeRows = useMemo(() => {
        return rawRows.filter((row) => row.company_id != null);
    }, [rawRows]);

    // Known modules first, then any log_name seen on the page that the map does not list.
    const moduleOptions = useMemo(() => {
        const known = Object.keys(MODULES);
        const seen = safeRows
            .map((r) => r.log_name)
            .filter((name) => name && !known.includes(name));
        return [...known, ...Array.from(new Set(seen)).sort()].map((value) => ({
            value,
            label: labelOf(MODULES, value),
        }));
    }, [safeRows]);

    const meta: PaginationMeta | null = useMemo(() => {
        if (data && !Array.isArray(data) && data.meta) {
            return data.meta;
        }
        return null;
    }, [data]);

    // Client-side search & filtering over safe rows (isolated fallback for meta-less datasets only)
    const filteredRows = useMemo(() => {
        if (meta) {
            return safeRows;
        }
        const term = localSearch.trim().toLowerCase();
        if (!term) return safeRows;

        return safeRows.filter((row) => {
            const desc = row.text.toLowerCase();
            const logName = (row.log_name ?? '').toLowerCase();
            const event = (row.event ?? '').toLowerCase();
            const causerName = (row.causer?.name ?? '').toLowerCase();
            const causerEmail = (row.causer?.email ?? '').toLowerCase();
            const subject = (row.subject_label ?? '').toLowerCase();
            const idStr = String(row.id);
            const causerIdStr = row.causer_id != null ? String(row.causer_id) : '';

            return (
                desc.includes(term) ||
                logName.includes(term) ||
                event.includes(term) ||
                causerName.includes(term) ||
                causerEmail.includes(term) ||
                subject.includes(term) ||
                idStr.includes(term) ||
                causerIdStr.includes(term)
            );
        });
    }, [safeRows, localSearch, meta]);

    // Sorting (isolated fallback for meta-less datasets only)
    const sortedRows = useMemo(() => {
        if (meta) {
            return safeRows;
        }
        const sorted = [...filteredRows];
        sorted.sort((a, b) => {
            const valA: unknown = a[sortBy];
            const valB: unknown = b[sortBy];

            if (valA == null && valB == null) return 0;
            if (valA == null) return sortDir === 'asc' ? -1 : 1;
            if (valB == null) return sortDir === 'asc' ? 1 : -1;

            if (typeof valA === 'number' && typeof valB === 'number') {
                return sortDir === 'asc' ? valA - valB : valB - valA;
            }

            const strA =
                typeof valA === 'string'
                    ? valA.toLowerCase()
                    : typeof valA === 'number' || typeof valA === 'boolean'
                      ? String(valA).toLowerCase()
                      : '';
            const strB =
                typeof valB === 'string'
                    ? valB.toLowerCase()
                    : typeof valB === 'number' || typeof valB === 'boolean'
                      ? String(valB).toLowerCase()
                      : '';
            if (strA < strB) return sortDir === 'asc' ? -1 : 1;
            if (strA > strB) return sortDir === 'asc' ? 1 : -1;
            return 0;
        });
        return sorted;
    }, [filteredRows, sortBy, sortDir, meta, safeRows]);

    // Pagination: Server-authoritative when meta is provided; client-fallback otherwise
    const totalCount = meta ? meta.total : sortedRows.length;
    const lastPage = meta ? meta.last_page : Math.max(1, Math.ceil(totalCount / perPage));
    const activeCurrentPage = meta ? meta.current_page : Math.min(currentPage, lastPage);
    const fromIndex = meta
        ? (meta.from ?? (totalCount > 0 ? 1 : 0))
        : totalCount > 0
          ? (activeCurrentPage - 1) * perPage + 1
          : 0;
    const toIndex = meta
        ? (meta.to ?? totalCount)
        : Math.min(activeCurrentPage * perPage, totalCount);

    const paginatedRows = useMemo(() => {
        if (meta) {
            return safeRows;
        }
        const startIndex = (activeCurrentPage - 1) * perPage;
        return sortedRows.slice(startIndex, startIndex + perPage);
    }, [meta, safeRows, sortedRows, activeCurrentPage, perPage]);

    // Validation & State Checks
    const isTruncated = !meta && safeRows.length >= 500;

    // Handle form submit (Sync to server)
    const handleFilterSubmit = (e?: FormEvent) => {
        if (e) e.preventDefault();
        const from = startOrDefault(localDateFrom, localDateTo);
        setLocalDateFrom(from);
        setCurrentPage(1);
        setMany({
            user_id: localUserId || '',
            log_name: localLogName,
            date_from: from,
            date_to: localDateTo || '',
            search: localSearch || '',
            sort: sortBy,
            direction: sortDir,
            sort_by: sortBy,
            sort_dir: sortDir,
            page: 1,
            per_page: perPage,
        });
    };

    // Reset all filters
    const handleResetFilters = () => {
        const from = startOrDefault('', '');
        setLocalSearch('');
        setLocalLogName('');
        setLocalUserId('');
        setLocalDateFrom(from);
        setLocalDateTo('');
        setCurrentPage(1);
        setSortBy('created_at');
        setSortDir('desc');

        router.get(
            route('admin.reports.audit-log'),
            { date_from: from },
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
            },
        );
    };

    // Refresh data
    const handleRefresh = () => {
        router.reload({ only: ['data', 'filters'] });
    };

    // Allowed sort fields strictly synchronized with ReportFilterRequest & AuditLogQuery
    const ALLOWED_SORT_FIELDS = ['created_at', 'log_name', 'event', 'description', 'id'];

    // Sort handler
    const handleSort = (field: string) => {
        if (!ALLOWED_SORT_FIELDS.includes(field)) {
            return;
        }
        const nextDir = sortBy === field && sortDir === 'asc' ? 'desc' : 'asc';
        setSortBy(field);
        setSortDir(nextDir);
        setCurrentPage(1);
        if (meta) {
            setMany({
                sort: field,
                direction: nextDir,
                sort_by: field,
                sort_dir: nextDir,
                page: 1,
            });
        }
    };

    // Page change handler
    const handlePageChange = (nextPage: number) => {
        setCurrentPage(nextPage);
        if (meta) {
            setMany({
                page: nextPage,
            });
        }
    };

    // Per-page change handler
    const handlePerPageChange = (nextPerPage: number) => {
        setPerPage(nextPerPage);
        setCurrentPage(1);
        if (meta) {
            setMany({
                per_page: nextPerPage,
                page: 1,
            });
        }
    };

    // Copy JSON in modal
    const handleCopyJson = useCallback(() => {
        if (!selectedRow?.properties) return;
        const text = JSON.stringify(selectedRow.properties, null, 2);
        void navigator.clipboard.writeText(text).then(() => {
            setCopiedJson(true);
            setTimeout(() => setCopiedJson(false), 2000);
        });
    }, [selectedRow]);

    // DataTable columns definition
    const columns: DataTableColumn<AuditLogRow>[] = useMemo(
        () => [
            {
                key: 'created_at',
                header: 'Waktu',
                sortable: true,
                sortKey: 'created_at',
                cell: (row) => (
                    <span className="text-xs font-medium whitespace-nowrap text-foreground">
                        {formatDateTime(row.created_at, company)}
                    </span>
                ),
            },
            {
                key: 'log_name',
                header: 'Modul',
                sortable: true,
                sortKey: 'log_name',
                cell: (row) => <ModuleBadge value={row.log_name} />,
            },
            {
                key: 'description',
                header: 'Deskripsi',
                cell: (row) => (
                    <div className="max-w-md text-xs font-medium text-foreground">{row.text}</div>
                ),
            },
            {
                key: 'causer',
                header: 'Pelaku',
                cell: (row) => (
                    <div className="flex items-center gap-1.5 text-xs">
                        <UserIcon
                            className="h-3.5 w-3.5 text-muted-foreground"
                            aria-hidden="true"
                        />
                        {row.causer ? (
                            <div className="flex flex-col">
                                <span className="font-medium text-foreground">
                                    {row.causer.name}
                                </span>
                                {row.causer.email && (
                                    <span className="text-xs text-muted-foreground">
                                        {row.causer.email}
                                    </span>
                                )}
                            </div>
                        ) : row.causer_id ? (
                            <span className="font-mono text-muted-foreground">
                                Pengguna #{row.causer_id}
                            </span>
                        ) : (
                            <span className="text-muted-foreground italic">Sistem</span>
                        )}
                    </div>
                ),
            },
            {
                key: 'subject',
                header: 'Subjek',
                cell: (row) => (
                    <div className="flex items-center gap-1 text-xs">
                        <Tag className="h-3.5 w-3.5 text-muted-foreground" aria-hidden="true" />
                        <span className="text-foreground">{row.subject_label ?? '-'}</span>
                    </div>
                ),
            },
            {
                key: 'actions',
                header: 'Detail',
                className: 'text-right',
                cell: (row) => {
                    const hasProps =
                        row.properties &&
                        (Array.isArray(row.properties)
                            ? row.properties.length > 0
                            : Object.keys(row.properties).length > 0);

                    return (
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            className="h-8 gap-1 px-2.5 text-xs"
                            onClick={() => setSelectedRow(row)}
                            aria-label={`Lihat detail log aktivitas #${row.id}`}
                        >
                            <Eye className="h-3.5 w-3.5" aria-hidden="true" />
                            <span>Detail</span>
                            {hasProps ? (
                                <span
                                    className="h-1.5 w-1.5 rounded-full bg-primary"
                                    title="Ada perubahan properti"
                                />
                            ) : null}
                        </Button>
                    );
                },
            },
        ],
        [company],
    );

    // Selected row properties extraction
    const selectedProps = selectedRow?.properties;
    const hasSelectedProps =
        selectedProps != null &&
        (Array.isArray(selectedProps)
            ? selectedProps.length > 0
            : Object.keys(selectedProps).length > 0);

    const isModelDiff =
        isPlainObject(selectedProps) && ('attributes' in selectedProps || 'old' in selectedProps);

    const modelDiffKeys = useMemo(() => {
        if (!isModelDiff || !isPlainObject(selectedProps)) return [];
        const attrs = isPlainObject(selectedProps.attributes)
            ? Object.keys(selectedProps.attributes)
            : [];
        const oldVals = isPlainObject(selectedProps.old) ? Object.keys(selectedProps.old) : [];
        return Array.from(new Set([...attrs, ...oldVals])).sort();
    }, [isModelDiff, selectedProps]);

    return (
        <AdminLayout title="Log audit">
            <div className="space-y-6">
                <PageHeader
                    title="Log audit"
                    subtitle="Rekam jejak aktivitas pengguna dan sistem, bisa disaring per pengguna, modul, dan periode."
                    actions={
                        <div className="flex flex-wrap items-center gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={handleRefresh}
                                disabled={visiting}
                                className="gap-1.5"
                                aria-label="Muat ulang data"
                            >
                                <RefreshCw
                                    className={cn('h-3.5 w-3.5', processing && 'animate-spin')}
                                    aria-hidden="true"
                                />
                                <span>Muat ulang</span>
                            </Button>

                            {canExport ? (
                                <ExportMenu
                                    exportUrl={route('admin.reports.audit-log.export')}
                                    params={{
                                        user_id: params.user_id,
                                        log_name: params.log_name,
                                        event: filters.event,
                                        search: params.search,
                                        date_from: params.date_from,
                                        date_to: params.date_to,
                                        sort: params.sort,
                                        direction: params.direction,
                                    }}
                                    formats={['csv', 'pdf']}
                                    canExport={canExport}
                                    label="Ekspor log"
                                    className="gap-1.5"
                                />
                            ) : null}
                        </div>
                    }
                />

                {/* Truncated data notice */}
                {isTruncated && (
                    <Alert variant="warning">
                        <AlertTriangle className="h-4 w-4" aria-hidden="true" />
                        <AlertTitle>Batas 500 baris tercapai</AlertTitle>
                        <AlertDescription>
                            Menampilkan 500 log aktivitas terbaru sesuai batas kueri server. Gunakan
                            filter periode, modul, atau pengguna untuk mempersempit hasil.
                        </AlertDescription>
                    </Alert>
                )}

                {/* Filter and Search Card */}
                <Card className="border border-border">
                    <CardHeader className="pt-5 pb-3">
                        <div className="flex items-center justify-between">
                            <CardTitle className="flex items-center gap-2 text-base font-semibold">
                                <Filter
                                    className="h-4 w-4 text-muted-foreground"
                                    aria-hidden="true"
                                />
                                <span>Filter log</span>
                            </CardTitle>
                            {processing && (
                                <span className="flex items-center gap-1.5 text-xs text-muted-foreground">
                                    <Clock className="h-3 w-3 animate-spin text-primary-text" />
                                    <span>Memperbarui data...</span>
                                </span>
                            )}
                        </div>
                        <CardDescription className="text-xs">
                            Saring aktivitas berdasarkan kata kunci, modul, pengguna, atau periode
                            kejadian.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <form onSubmit={handleFilterSubmit} className="space-y-3">
                            {/* Row 1: Search, Module, User */}
                            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                                <div className="space-y-1 lg:col-span-2">
                                    <label
                                        htmlFor={searchInputId}
                                        className="text-xs font-medium text-muted-foreground"
                                    >
                                        Pencarian teks
                                    </label>
                                    <div className="relative">
                                        <Search
                                            className="pointer-events-none absolute top-2.5 left-3 h-4 w-4 text-muted-foreground"
                                            aria-hidden="true"
                                        />
                                        <Input
                                            id={searchInputId}
                                            type="text"
                                            value={localSearch}
                                            onChange={(e) => setLocalSearch(e.target.value)}
                                            placeholder="Cari deskripsi, modul, aksi, pelaku..."
                                            className="pl-9"
                                        />
                                    </div>
                                </div>

                                <SearchSelect
                                    label="Modul"
                                    placeholder="Semua modul"
                                    options={moduleOptions}
                                    value={localLogName}
                                    onChange={setLocalLogName}
                                />

                                <RemoteSearchSelect
                                    field="user"
                                    label="Pengguna"
                                    placeholder="Cari nama atau email..."
                                    options={userOptions}
                                    selected={selectedUser}
                                    value={localUserId}
                                    onChange={setLocalUserId}
                                />
                            </div>

                            {/* Row 2: Date range */}
                            <div className="max-w-xl">
                                <DateRangePicker
                                    id={dateRangeId}
                                    label="Periode"
                                    value={{ from: localDateFrom || null, to: localDateTo || null }}
                                    onChange={(range) => {
                                        setLocalDateFrom(range.from ?? '');
                                        setLocalDateTo(range.to ?? '');
                                    }}
                                    error={errors.date_from ?? errors.date_to}
                                />
                                <p className="mt-1 text-xs text-muted-foreground">
                                    Paling lama 92 hari. Tanpa tanggal mulai, 7 hari terakhir.
                                </p>
                            </div>

                            {/* Buttons */}
                            <div className="flex flex-wrap items-center justify-between gap-2 border-t border-border pt-3">
                                <div className="text-xs text-muted-foreground">
                                    Menampilkan{' '}
                                    <span className="font-semibold text-foreground">
                                        {paginatedRows.length}
                                    </span>{' '}
                                    dari{' '}
                                    <span className="font-semibold text-foreground">
                                        {totalCount}
                                    </span>{' '}
                                    log
                                </div>
                                <div className="flex items-center gap-2">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={handleResetFilters}
                                        className="gap-1 text-xs"
                                    >
                                        <RotateCcw className="h-3.5 w-3.5" aria-hidden="true" />
                                        <span>Reset filter</span>
                                    </Button>
                                    <Button
                                        type="submit"
                                        variant="default"
                                        size="sm"
                                        disabled={visiting}
                                        className="gap-1 text-xs"
                                    >
                                        <Filter className="h-3.5 w-3.5" aria-hidden="true" />
                                        <span>Terapkan filter</span>
                                    </Button>
                                </div>
                            </div>
                        </form>
                    </CardContent>
                </Card>

                {/* Table & Cards Container */}
                <Card className="border border-border">
                    <CardContent className="space-y-4 p-4 sm:p-6">
                        {/* Desktop Table */}
                        <div className="hidden md:block">
                            <DataTable
                                columns={columns}
                                data={paginatedRows}
                                sortBy={sortBy}
                                sortDir={sortDir}
                                onSort={handleSort}
                                loading={processing}
                                emptyTitle="Tidak ada log aktivitas"
                                emptyDescription="Tidak ada aktivitas yang cocok dengan filter ini."
                                emptyAction={
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={handleResetFilters}
                                        className="mt-2"
                                    >
                                        Reset filter
                                    </Button>
                                }
                            />
                        </div>

                        {/* Mobile Cards View */}
                        <div className="space-y-3 md:hidden">
                            {paginatedRows.length === 0 ? (
                                <EmptyState
                                    icon={<FileText className="h-8 w-8" />}
                                    title="Tidak ada log aktivitas"
                                    description="Tidak ada aktivitas yang cocok dengan filter ini."
                                    action={
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            onClick={handleResetFilters}
                                        >
                                            Reset filter
                                        </Button>
                                    }
                                />
                            ) : (
                                paginatedRows.map((row) => (
                                    <Card key={row.id} className="border border-border bg-card p-4">
                                        <div className="space-y-3">
                                            {/* Header */}
                                            <div className="flex items-start justify-between gap-2">
                                                <div className="flex flex-wrap items-center gap-1.5">
                                                    <ModuleBadge value={row.log_name} />
                                                </div>
                                                <span className="text-xs whitespace-nowrap text-muted-foreground">
                                                    {formatDateTime(row.created_at, company)}
                                                </span>
                                            </div>

                                            {/* Description */}
                                            <p className="text-sm font-medium text-foreground">
                                                {row.text}
                                            </p>

                                            {/* Metadata */}
                                            <div className="grid grid-cols-2 gap-2 text-xs text-muted-foreground">
                                                <div>
                                                    <span className="block text-xs">Pelaku:</span>
                                                    <span className="font-medium text-foreground">
                                                        {row.causer?.name ??
                                                            (row.causer_id
                                                                ? `Pengguna #${row.causer_id}`
                                                                : 'Sistem')}
                                                    </span>
                                                </div>
                                                <div>
                                                    <span className="block text-xs">Subjek:</span>
                                                    <span className="font-medium text-foreground">
                                                        {row.subject_label ?? '-'}
                                                    </span>
                                                </div>
                                            </div>

                                            {/* Button */}
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                className="w-full gap-1 text-xs"
                                                onClick={() => setSelectedRow(row)}
                                            >
                                                <Eye className="h-3.5 w-3.5" aria-hidden="true" />
                                                <span>Lihat detail properti</span>
                                            </Button>
                                        </div>
                                    </Card>
                                ))
                            )}
                        </div>

                        {/* Pagination Bar */}
                        {totalCount > 0 && (
                            <div className="flex flex-col items-center justify-between gap-3 border-t border-border pt-4 sm:flex-row">
                                <div className="text-xs text-muted-foreground">
                                    Menampilkan{' '}
                                    <span className="font-medium text-foreground">
                                        {fromIndex}-{toIndex}
                                    </span>{' '}
                                    dari{' '}
                                    <span className="font-medium text-foreground">
                                        {totalCount}
                                    </span>{' '}
                                    log
                                </div>

                                <div className="flex items-center gap-3">
                                    <div className="flex items-center gap-1.5">
                                        <span className="text-xs text-muted-foreground">
                                            Baris per halaman
                                        </span>
                                        <NativeSelect
                                            aria-label="Baris per halaman"
                                            options={PER_PAGE_OPTIONS}
                                            value={String(perPage)}
                                            onChange={(e) =>
                                                handlePerPageChange(Number(e.target.value))
                                            }
                                            wrapperClassName="w-24"
                                        />
                                    </div>

                                    <Pagination
                                        currentPage={activeCurrentPage}
                                        lastPage={lastPage}
                                        onPageChange={handlePageChange}
                                    />
                                </div>
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>

            {/* Property Disclosure Modal */}
            <Modal
                open={selectedRow !== null}
                onClose={() => setSelectedRow(null)}
                title={selectedRow ? `Detail log aktivitas #${selectedRow.id}` : 'Detail log'}
                size="xl"
            >
                {selectedRow && (
                    <div className="space-y-5">
                        {/* Section 1: Overview Details */}
                        <div className="rounded-lg border border-border bg-muted/30 p-4">
                            <h4 className="mb-3 text-sm font-semibold text-muted-foreground">
                                Ringkasan
                            </h4>
                            <div className="grid grid-cols-1 gap-3 text-xs sm:grid-cols-2">
                                <div>
                                    <span className="block text-muted-foreground">
                                        Waktu kejadian:
                                    </span>
                                    <span className="font-medium text-foreground">
                                        {formatDateTime(selectedRow.created_at, company)}
                                    </span>
                                </div>
                                <div>
                                    <span className="block text-muted-foreground">Modul:</span>
                                    <div className="mt-0.5 flex items-center gap-1.5">
                                        <ModuleBadge value={selectedRow.log_name} />
                                    </div>
                                </div>
                                <div>
                                    <span className="block text-muted-foreground">Pelaku:</span>
                                    <span className="font-medium text-foreground">
                                        {selectedRow.causer?.name ??
                                            (selectedRow.causer_id
                                                ? `Pengguna #${selectedRow.causer_id}`
                                                : 'Sistem')}
                                    </span>
                                    {selectedRow.causer?.email && (
                                        <span className="block text-xs text-muted-foreground">
                                            {selectedRow.causer.email}
                                        </span>
                                    )}
                                </div>
                                <div>
                                    <span className="block text-muted-foreground">Subjek:</span>
                                    <span className="font-medium text-foreground">
                                        {selectedRow.subject_label ?? '-'}
                                    </span>
                                </div>
                                <div className="sm:col-span-2">
                                    <span className="block text-muted-foreground">Deskripsi:</span>
                                    <span className="mt-0.5 block font-medium text-foreground">
                                        {selectedRow.text}
                                    </span>
                                </div>
                            </div>
                        </div>

                        {/* Section 2: Properties & Data Changes */}
                        <div className="space-y-3">
                            <div className="flex items-center justify-between">
                                <h4 className="text-sm font-semibold text-muted-foreground">
                                    Properti dan perubahan nilai
                                </h4>
                                {hasSelectedProps && canViewAudit && (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={handleCopyJson}
                                        className="h-7 gap-1 px-2 text-xs"
                                    >
                                        {copiedJson ? (
                                            <>
                                                <Check className="h-3 w-3 text-primary-text" />
                                                <span>Tersalin</span>
                                            </>
                                        ) : (
                                            <>
                                                <Copy className="h-3 w-3" />
                                                <span>Salin JSON</span>
                                            </>
                                        )}
                                    </Button>
                                )}
                            </div>

                            {!canViewAudit ? (
                                <Alert variant="warning">
                                    <ShieldAlert className="h-4 w-4" aria-hidden="true" />
                                    <AlertTitle>Akses properti dibatasi</AlertTitle>
                                    <AlertDescription>
                                        Anda tidak punya izin untuk melihat rincian properti dan
                                        data sensitif pada log ini.
                                    </AlertDescription>
                                </Alert>
                            ) : !hasSelectedProps ? (
                                <div className="rounded-lg border border-dashed border-border p-6 text-center text-xs text-muted-foreground">
                                    Tidak ada perubahan properti yang dicatat untuk aktivitas ini.
                                </div>
                            ) : isModelDiff && isPlainObject(selectedProps) ? (
                                <div className="space-y-3">
                                    <div className="max-h-72 overflow-x-auto rounded-md border border-border">
                                        <Table>
                                            <THead>
                                                <TR className="bg-muted/50 text-xs">
                                                    <TH className="w-1/3">Kolom</TH>
                                                    <TH className="w-1/3">Nilai lama</TH>
                                                    <TH className="w-1/3">Nilai baru</TH>
                                                </TR>
                                            </THead>
                                            <TBody>
                                                {modelDiffKeys.map((key) => {
                                                    const attrs = isPlainObject(
                                                        selectedProps.attributes,
                                                    )
                                                        ? selectedProps.attributes
                                                        : {};
                                                    const olds = isPlainObject(selectedProps.old)
                                                        ? selectedProps.old
                                                        : {};
                                                    const oldVal = olds[key];
                                                    const newVal = attrs[key];

                                                    return (
                                                        <TR key={key} className="text-xs">
                                                            <TD className="font-mono font-medium text-foreground">
                                                                {key}
                                                            </TD>
                                                            <TD className="font-mono text-muted-foreground">
                                                                {oldVal !== undefined
                                                                    ? String(JSON.stringify(oldVal))
                                                                    : '-'}
                                                            </TD>
                                                            <TD className="font-mono font-medium text-foreground">
                                                                {newVal !== undefined
                                                                    ? String(JSON.stringify(newVal))
                                                                    : '-'}
                                                            </TD>
                                                        </TR>
                                                    );
                                                })}
                                            </TBody>
                                        </Table>
                                    </div>

                                    {/* Raw JSON Accordion / View */}
                                    <details className="rounded-md border border-border bg-muted/20 p-2 text-xs">
                                        <summary className="cursor-pointer font-medium text-muted-foreground hover:text-foreground">
                                            Lihat JSON lengkap
                                        </summary>
                                        <pre className="mt-2 max-h-48 overflow-auto rounded bg-muted/60 p-3 font-mono text-xs text-foreground">
                                            {JSON.stringify(selectedProps, null, 2)}
                                        </pre>
                                    </details>
                                </div>
                            ) : (
                                <div className="space-y-3">
                                    <pre className="max-h-72 overflow-auto rounded-lg border border-border bg-muted/50 p-4 font-mono text-xs text-foreground">
                                        {JSON.stringify(selectedProps, null, 2)}
                                    </pre>
                                </div>
                            )}
                        </div>

                        {/* Footer Close Button */}
                        <div className="flex justify-end border-t border-border pt-4">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setSelectedRow(null)}
                            >
                                Tutup
                            </Button>
                        </div>
                    </div>
                )}
            </Modal>
        </AdminLayout>
    );
}
