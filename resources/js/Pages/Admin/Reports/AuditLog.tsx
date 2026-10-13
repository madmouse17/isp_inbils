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
import { DataTable, type DataTableColumn, PageHeader } from '@/Components/composite';
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
    ExportMenu,
    Input,
    Modal,
    Pagination,
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
    Table,
    TBody,
    TD,
    TH,
    THead,
    TR,
} from '@/Components/ui';
import { cn } from '@/lib/utils';
import { formatDateTime } from '@/lib/format';
import { useServerTable } from '@/hooks/useServerTable';
import type { PageProps } from '@/types';

export interface AuditLogCauser {
    id: number;
    name: string;
    email?: string;
}

export interface AuditLogRow extends Record<string, unknown> {
    id: number;
    company_id: number | null;
    log_name: string;
    description: string;
    event: string | null;
    causer_id: number | null;
    causer_type: string | null;
    causer: AuditLogCauser | null;
    subject_type: string | null;
    subject_id: number | null;
    properties?: Record<string, unknown> | unknown[];
    created_at: string;
}

export interface AuditLogFilters {
    user_id?: string | number;
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
    can?: {
        export?: boolean;
        view_properties?: boolean;
    };
}

const COMMON_MODULES = [
    'customer',
    'billing',
    'inventory',
    'spk',
    'ticket',
    'user',
    'auth',
    'location',
    'network_asset',
    'organization',
    'approval',
    'setting',
];

function formatSubjectShort(type?: string | null, id?: number | string | null): string {
    if (!type && id == null) return '-';
    const cleanType = type ? (type.split('\\').pop() ?? type) : 'Entitas';
    return id != null ? `${cleanType} #${id}` : cleanType;
}

function getEventBadgeVariant(
    event?: string | null,
): 'success' | 'info' | 'danger' | 'warning' | 'secondary' {
    if (!event) return 'secondary';
    const lower = event.toLowerCase();
    if (
        lower === 'created' ||
        lower.includes('create') ||
        lower.includes('store') ||
        lower.includes('generate')
    ) {
        return 'success';
    }
    if (lower === 'updated' || lower.includes('update') || lower.includes('edit')) {
        return 'info';
    }
    if (lower === 'deleted' || lower.includes('delete') || lower.includes('destroy')) {
        return 'danger';
    }
    if (lower === 'restored' || lower.includes('restore') || lower.includes('reverse')) {
        return 'warning';
    }
    return 'secondary';
}

function formatEventLabel(event?: string | null): string {
    if (!event) return 'Aktivitas';
    const lower = event.toLowerCase();
    if (lower === 'created') return 'Dibuat';
    if (lower === 'updated') return 'Diperbarui';
    if (lower === 'deleted') return 'Dihapus';
    if (lower === 'restored') return 'Dipulihkan';
    return event;
}

function isPlainObject(val: unknown): val is Record<string, unknown> {
    return val !== null && typeof val === 'object' && !Array.isArray(val);
}

export default function AuditLog({ data, filters, can }: AuditLogProps) {
    const { auth } = usePage<PageProps>().props;
    const searchInputId = useId();
    const moduleSelectId = useId();
    const userIdInputId = useId();
    const dateRangeId = useId();

    const userPermissions = auth.user?.permissions ?? [];
    const userRoles = auth.user?.roles ?? [];
    const canViewAudit =
        userPermissions.includes('report.audit.view') ||
        userRoles.includes('admin') ||
        userRoles.includes('super-admin');
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
        only: ['data', 'filters'],
    });

    // Local form state for manual submission
    const [localSearch, setLocalSearch] = useState<string>(String(params.search ?? ''));
    const [localLogName, setLocalLogName] = useState<string>(String(params.log_name ?? ''));
    const [localUserId, setLocalUserId] = useState<string>(String(params.user_id ?? ''));
    const [localDateFrom, setLocalDateFrom] = useState<string>(String(params.date_from ?? ''));
    const [localDateTo, setLocalDateTo] = useState<string>(String(params.date_to ?? ''));

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

    // Dynamic modules list combining predefined list and modules from data
    const availableModules = useMemo(() => {
        const fromData = safeRows.map((r) => r.log_name).filter(Boolean);
        return Array.from(new Set([...COMMON_MODULES, ...fromData])).sort();
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
            const desc = (row.description ?? '').toLowerCase();
            const logName = (row.log_name ?? '').toLowerCase();
            const event = (row.event ?? '').toLowerCase();
            const causerName = (row.causer?.name ?? '').toLowerCase();
            const causerEmail = (row.causer?.email ?? '').toLowerCase();
            const subject = (row.subject_type ?? '').toLowerCase();
            const idStr = String(row.id);
            const causerIdStr = row.causer_id != null ? String(row.causer_id) : '';
            const subjectIdStr = row.subject_id != null ? String(row.subject_id) : '';

            return (
                desc.includes(term) ||
                logName.includes(term) ||
                event.includes(term) ||
                causerName.includes(term) ||
                causerEmail.includes(term) ||
                subject.includes(term) ||
                idStr.includes(term) ||
                causerIdStr.includes(term) ||
                subjectIdStr.includes(term)
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
        setCurrentPage(1);
        setMany({
            user_id: localUserId || '',
            log_name: localLogName === '__all__' ? '' : localLogName,
            date_from: localDateFrom || '',
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
        setLocalSearch('');
        setLocalLogName('');
        setLocalUserId('');
        setLocalDateFrom('');
        setLocalDateTo('');
        setCurrentPage(1);
        setSortBy('created_at');
        setSortDir('desc');

        router.get(
            route('admin.reports.audit-log'),
            {},
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
                    <div className="flex flex-col text-xs whitespace-nowrap">
                        <span className="font-medium text-foreground">
                            {formatDateTime(row.created_at)}
                        </span>
                        <span className="text-xs text-muted-foreground">
                            {row.created_at
                                ? new Date(row.created_at).toISOString().slice(11, 19) + ' UTC'
                                : '-'}
                        </span>
                    </div>
                ),
            },
            {
                key: 'log_name',
                header: 'Modul',
                sortable: true,
                sortKey: 'log_name',
                cell: (row) => (
                    <Badge variant="outline" className="font-mono text-xs">
                        {row.log_name || 'umum'}
                    </Badge>
                ),
            },
            {
                key: 'event',
                header: 'Aksi',
                sortable: true,
                sortKey: 'event',
                cell: (row) => (
                    <Badge variant={getEventBadgeVariant(row.event)}>
                        {formatEventLabel(row.event)}
                    </Badge>
                ),
            },
            {
                key: 'description',
                header: 'Deskripsi',
                sortable: true,
                sortKey: 'description',
                cell: (row) => (
                    <div className="max-w-xs truncate text-xs font-medium text-foreground sm:max-w-md">
                        {row.description}
                    </div>
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
                                User #{row.causer_id}
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
                        <span className="font-mono text-muted-foreground">
                            {formatSubjectShort(row.subject_type, row.subject_id)}
                        </span>
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
                                    title="Terdapat perubahan properti"
                                />
                            ) : null}
                        </Button>
                    );
                },
            },
        ],
        [],
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
        <AdminLayout title="Audit Log">
            <div className="space-y-6">
                <PageHeader
                    title="Audit Log"
                    subtitle="Rekam jejak aktivitas audit sistem per pengguna, modul, dan rentang waktu."
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
                                <span>Muat Ulang</span>
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
                                    label="Ekspor Log"
                                    className="gap-1.5"
                                />
                            ) : null}
                        </div>
                    }
                />

                {/* Truncated data notice */}
                {isTruncated && (
                    <Alert variant="warning" className="border-amber-500/30 bg-amber-500/10">
                        <AlertTriangle
                            className="h-4 w-4 text-amber-600 dark:text-amber-400"
                            aria-hidden="true"
                        />
                        <AlertTitle className="text-amber-800 dark:text-amber-300">
                            Batas Hasil Tercapai (500 Baris)
                        </AlertTitle>
                        <AlertDescription className="text-amber-700 dark:text-amber-400">
                            Menampilkan 500 log aktivitas terbaru sesuai batas kueri server. Gunakan
                            filter rentang tanggal, modul, atau pengguna untuk mempersempit hasil
                            pencarian.
                        </AlertDescription>
                    </Alert>
                )}

                {/* Filter and Search Card */}
                <Card className="border border-border shadow-xs">
                    <CardHeader className="pt-5 pb-3">
                        <div className="flex items-center justify-between">
                            <CardTitle className="flex items-center gap-2 text-base font-semibold">
                                <Filter
                                    className="h-4 w-4 text-muted-foreground"
                                    aria-hidden="true"
                                />
                                <span>Filter & Pencarian Log</span>
                            </CardTitle>
                            {processing && (
                                <span className="flex items-center gap-1.5 text-xs text-muted-foreground">
                                    <Clock className="h-3 w-3 animate-spin text-primary" />
                                    <span>Memperbarui data...</span>
                                </span>
                            )}
                        </div>
                        <CardDescription className="text-xs">
                            Saring aktivitas audit berdasarkan kata kunci, modul, ID pengguna, atau
                            rentang tanggal kejadian.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <form onSubmit={handleFilterSubmit} className="space-y-3">
                            {/* Row 1: Search, Module, User ID */}
                            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                                <div className="space-y-1 lg:col-span-2">
                                    <label
                                        htmlFor={searchInputId}
                                        className="text-xs font-medium text-muted-foreground"
                                    >
                                        Pencarian Teks
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
                                            placeholder="Cari deskripsi, modul, event, pelaku..."
                                            className="pl-9"
                                        />
                                    </div>
                                </div>

                                <div className="space-y-1">
                                    <label
                                        htmlFor={moduleSelectId}
                                        className="text-xs font-medium text-muted-foreground"
                                    >
                                        Modul
                                    </label>
                                    <Select
                                        value={localLogName || '__all__'}
                                        onValueChange={(val) =>
                                            setLocalLogName(val === '__all__' ? '' : val)
                                        }
                                    >
                                        <SelectTrigger id={moduleSelectId}>
                                            <SelectValue placeholder="Semua Modul" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="__all__">Semua Modul</SelectItem>
                                            {availableModules.map((m) => (
                                                <SelectItem key={m} value={m}>
                                                    {m.toUpperCase()}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>

                                <div className="space-y-1">
                                    <label
                                        htmlFor={userIdInputId}
                                        className="text-xs font-medium text-muted-foreground"
                                    >
                                        ID Pengguna (Causer)
                                    </label>
                                    <Input
                                        id={userIdInputId}
                                        type="number"
                                        value={localUserId}
                                        onChange={(e) => setLocalUserId(e.target.value)}
                                        placeholder="Misal: 1, 2, ..."
                                        min={1}
                                    />
                                </div>
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
                                />
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
                                    rekaman dimuat
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
                                        <span>Atur Ulang</span>
                                    </Button>
                                    <Button
                                        type="submit"
                                        variant="default"
                                        size="sm"
                                        disabled={visiting}
                                        className="gap-1 text-xs"
                                    >
                                        <Filter className="h-3.5 w-3.5" aria-hidden="true" />
                                        <span>Terapkan Filter</span>
                                    </Button>
                                </div>
                            </div>
                        </form>
                    </CardContent>
                </Card>

                {/* Table & Cards Container */}
                <Card className="border border-border shadow-xs">
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
                                emptyTitle="Tidak Ada Log Aktivitas"
                                emptyDescription="Tidak ada riwayat aktivitas yang sesuai dengan kriteria filter saat ini."
                                emptyAction={
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={handleResetFilters}
                                        className="mt-2"
                                    >
                                        Atur Ulang Filter
                                    </Button>
                                }
                            />
                        </div>

                        {/* Mobile Cards View */}
                        <div className="space-y-3 md:hidden">
                            {paginatedRows.length === 0 ? (
                                <EmptyState
                                    icon={<FileText className="h-8 w-8" />}
                                    title="Tidak Ada Log Aktivitas"
                                    description="Tidak ada riwayat aktivitas yang sesuai dengan kriteria filter saat ini."
                                    action={
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            onClick={handleResetFilters}
                                        >
                                            Atur Ulang Filter
                                        </Button>
                                    }
                                />
                            ) : (
                                paginatedRows.map((row) => (
                                    <Card
                                        key={row.id}
                                        className="border border-border bg-card p-4 shadow-xs"
                                    >
                                        <div className="space-y-3">
                                            {/* Header */}
                                            <div className="flex items-start justify-between gap-2">
                                                <div className="flex flex-wrap items-center gap-1.5">
                                                    <Badge
                                                        variant="outline"
                                                        className="font-mono text-xs"
                                                    >
                                                        {row.log_name || 'umum'}
                                                    </Badge>
                                                    <Badge
                                                        variant={getEventBadgeVariant(row.event)}
                                                    >
                                                        {formatEventLabel(row.event)}
                                                    </Badge>
                                                </div>
                                                <span className="text-xs whitespace-nowrap text-muted-foreground">
                                                    {formatDateTime(row.created_at)}
                                                </span>
                                            </div>

                                            {/* Description */}
                                            <p className="text-sm font-medium text-foreground">
                                                {row.description}
                                            </p>

                                            {/* Metadata */}
                                            <div className="grid grid-cols-2 gap-2 text-xs text-muted-foreground">
                                                <div>
                                                    <span className="block text-xs">Pelaku:</span>
                                                    <span className="font-medium text-foreground">
                                                        {row.causer?.name ??
                                                            (row.causer_id
                                                                ? `User #${row.causer_id}`
                                                                : 'Sistem')}
                                                    </span>
                                                </div>
                                                <div>
                                                    <span className="block text-xs">Subjek:</span>
                                                    <span className="font-medium text-foreground">
                                                        {formatSubjectShort(
                                                            row.subject_type,
                                                            row.subject_id,
                                                        )}
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
                                                <span>Lihat Detail Properti</span>
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
                                    <span className="font-medium text-foreground">{fromIndex}</span>{' '}
                                    - <span className="font-medium text-foreground">{toIndex}</span>{' '}
                                    dari{' '}
                                    <span className="font-medium text-foreground">
                                        {totalCount}
                                    </span>{' '}
                                    log
                                </div>

                                <div className="flex items-center gap-3">
                                    <div className="flex items-center gap-1.5">
                                        <span className="text-xs text-muted-foreground">
                                            Per hal:
                                        </span>
                                        <Select
                                            value={String(perPage)}
                                            onValueChange={(val) => {
                                                const next = Number(val);
                                                handlePerPageChange(next);
                                            }}
                                        >
                                            <SelectTrigger className="h-8 w-16 text-xs">
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="10">10</SelectItem>
                                                <SelectItem value="15">15</SelectItem>
                                                <SelectItem value="25">25</SelectItem>
                                                <SelectItem value="50">50</SelectItem>
                                                <SelectItem value="100">100</SelectItem>
                                            </SelectContent>
                                        </Select>
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
                title={selectedRow ? `Detail Log Aktivitas #${selectedRow.id}` : 'Detail Log'}
                size="xl"
            >
                {selectedRow && (
                    <div className="space-y-5">
                        {/* Section 1: Overview Details */}
                        <div className="rounded-lg border border-border bg-muted/30 p-4">
                            <h4 className="mb-3 text-sm font-semibold text-muted-foreground">
                                Informasi Ringkasan
                            </h4>
                            <div className="grid grid-cols-1 gap-3 text-xs sm:grid-cols-2">
                                <div>
                                    <span className="block text-muted-foreground">
                                        Waktu Kejadian:
                                    </span>
                                    <span className="font-medium text-foreground">
                                        {formatDateTime(selectedRow.created_at)}
                                    </span>
                                    <span className="ml-1 text-xs text-muted-foreground">
                                        ({selectedRow.created_at})
                                    </span>
                                </div>
                                <div>
                                    <span className="block text-muted-foreground">
                                        Modul & Aksi:
                                    </span>
                                    <div className="mt-0.5 flex items-center gap-1.5">
                                        <Badge variant="outline" className="font-mono text-xs">
                                            {selectedRow.log_name}
                                        </Badge>
                                        <Badge variant={getEventBadgeVariant(selectedRow.event)}>
                                            {formatEventLabel(selectedRow.event)}
                                        </Badge>
                                    </div>
                                </div>
                                <div>
                                    <span className="block text-muted-foreground">
                                        Pelaku (Causer):
                                    </span>
                                    <span className="font-medium text-foreground">
                                        {selectedRow.causer?.name ??
                                            (selectedRow.causer_id
                                                ? `User #${selectedRow.causer_id}`
                                                : 'Sistem / Otomatis')}
                                    </span>
                                    {selectedRow.causer?.email && (
                                        <span className="block text-xs text-muted-foreground">
                                            {selectedRow.causer.email}
                                        </span>
                                    )}
                                </div>
                                <div>
                                    <span className="block text-muted-foreground">
                                        Objek / Subjek:
                                    </span>
                                    <span className="font-mono font-medium text-foreground">
                                        {selectedRow.subject_type ?? '-'}
                                    </span>
                                    {selectedRow.subject_id != null && (
                                        <span className="block text-xs text-muted-foreground">
                                            ID: #{selectedRow.subject_id}
                                        </span>
                                    )}
                                </div>
                                <div className="sm:col-span-2">
                                    <span className="block text-muted-foreground">
                                        Deskripsi Aktivitas:
                                    </span>
                                    <span className="mt-0.5 block font-medium text-foreground">
                                        {selectedRow.description}
                                    </span>
                                </div>
                            </div>
                        </div>

                        {/* Section 2: Properties & Data Changes */}
                        <div className="space-y-3">
                            <div className="flex items-center justify-between">
                                <h4 className="text-sm font-semibold text-muted-foreground">
                                    Properti & Perubahan Nilai
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
                                                <Check className="h-3 w-3 text-emerald-500" />
                                                <span>Tersalin!</span>
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
                                    <AlertTitle>Akses Properti Dibatasi</AlertTitle>
                                    <AlertDescription>
                                        Anda tidak memiliki izin (`report.audit.view`) untuk melihat
                                        rincian properti dan data sensitif pada log audit ini.
                                    </AlertDescription>
                                </Alert>
                            ) : !hasSelectedProps ? (
                                <div className="rounded-lg border border-dashed border-border p-6 text-center text-xs text-muted-foreground">
                                    Tidak ada data perubahan properti tambahan yang dicatat untuk
                                    aktivitas ini.
                                </div>
                            ) : isModelDiff && isPlainObject(selectedProps) ? (
                                <div className="space-y-3">
                                    <div className="max-h-72 overflow-x-auto rounded-md border border-border">
                                        <Table>
                                            <THead>
                                                <TR className="bg-muted/50 text-xs">
                                                    <TH className="w-1/3">Kolom / Field</TH>
                                                    <TH className="w-1/3">
                                                        Nilai Lama (Sebelumnya)
                                                    </TH>
                                                    <TH className="w-1/3">
                                                        Nilai Baru (Sesudahnya)
                                                    </TH>
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
                                            Lihat JSON Lengkap
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
