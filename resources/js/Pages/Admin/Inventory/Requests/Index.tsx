import React, { useMemo, useState, useEffect, useCallback } from 'react';
import { Head, Link } from '@inertiajs/react';
import { Plus, Package, Clock, AlertCircle, RefreshCw, Truck, ShieldAlert } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { PageHeader } from '@/Components/composite/PageHeader';
import { StatusBadge } from '@/Components/composite/StatusBadge';
import { DataTable, type DataTableColumn } from '@/Components/composite/DataTable';
import { Button } from '@/Components/ui/Button';
import { Input } from '@/Components/ui/Input';
import { Badge } from '@/Components/ui/Badge';
import { NativeSelect } from '@/Components/ui';
import { Card, CardContent } from '@/Components/ui/Card';
import { Tabs, TabList, Tab, TabPanel } from '@/Components/ui/Tabs';
import { useServerTable } from '@/hooks/useServerTable';
import { usePermission } from '@/hooks/usePermission';
import { formatDate, formatDateTime } from '@/lib/format';
import type { PaginationMeta } from '@/types';
import type { InventoryRequestRow, MaterialReturnCaseRow } from '@/types/inventory';
import { returnStatusLabelMap, returnStatusVariantMap } from './constants';

interface PaginatedData<T> {
    data: T[];
    meta?: PaginationMeta;
    links?: Array<{ url: string | null; label: string; active: boolean }>;
}

interface Props {
    requests: PaginatedData<InventoryRequestRow>;
    filters: {
        tab?: string;
        status?: string;
        search?: string;
        requester_id?: string;
        warehouse_location_id?: string;
        needed_from?: string;
        needed_to?: string;
        sort?: string;
        direction?: string;
        per_page?: string | number;
        page?: string | number;
        return_search?: string;
        return_status?: string;
        return_sort?: string;
        return_direction?: string;
        return_per_page?: string | number;
        return_page?: string | number;
    };
    badgeCounts?: {
        my_requests?: number | null;
        warehouse_queue?: number | null;
    };
    can?: {
        create?: boolean;
        viewAll?: boolean;
        approve?: boolean;
        prepare?: boolean;
        issue?: boolean;
        audit?: boolean;
    };
}

interface ReturnsApiResponse {
    data: MaterialReturnCaseRow[];
    meta?: PaginationMeta;
    badges?: {
        my_pending_returns?: number;
        warehouse_return_queue?: number;
    };
}

const statusVariantMap: Record<string, 'neutral' | 'info' | 'warning' | 'success' | 'danger'> = {
    draft: 'neutral',
    submitted: 'warning',
    approved_reserved: 'info',
    preparing: 'info',
    ready_for_pickup: 'info',
    issued: 'success',
    rejected: 'danger',
    cancelled: 'neutral',
};

export default function InventoryRequestsIndex({
    requests,
    filters,
    badgeCounts = {},
    can: capabilities = {},
}: Props) {
    const { can } = usePermission();
    const canViewAll = capabilities.viewAll ?? can('inventory.request.view.all');
    const canWarehouse =
        capabilities.approve ||
        capabilities.prepare ||
        capabilities.issue ||
        can('inventory.request.approve') ||
        can('inventory.request.prepare') ||
        can('inventory.request.issue');

    // Capabilities for return custody queues
    const canDeclareReturn = can('inventory.return.declare');
    const canReceiveReturn = can('inventory.return.receive');
    const canResolveReturn = can('inventory.return.resolve');
    const canViewAllReturns = can('inventory.return.view.all');
    const canViewOwnReturns = can('inventory.return.view.own');
    const canViewReturns =
        canViewAllReturns ||
        canViewOwnReturns ||
        canDeclareReturn ||
        canReceiveReturn ||
        canResolveReturn;

    const activeTab = filters.tab ?? (canWarehouse && canViewAll ? 'warehouse' : 'my');

    const { params, set, setMany, sortBy, sortDir, onSort, processing } = useServerTable({
        url: route('admin.inventory.requests.index'),
        initialFilters: {
            ...filters,
            tab: activeTab,
            sort: filters.sort ?? 'created_at',
            direction: filters.direction ?? 'desc',
            per_page: filters.per_page ?? 10,
            return_status:
                filters.return_status ??
                (canReceiveReturn
                    ? 'awaiting_receipt'
                    : canResolveReturn
                      ? 'exception_open'
                      : canDeclareReturn
                        ? 'awaiting_declaration'
                        : 'all'),
            return_sort: filters.return_sort ?? 'created_at',
            return_direction: filters.return_direction ?? 'desc',
            return_per_page: filters.return_per_page ?? 10,
            return_page: filters.return_page ?? 1,
        },
        only: ['requests', 'filters', 'badgeCounts'],
    });
    const setReturn = (key: string, value: string | number) => {
        setMany({
            page: params.page ?? 1,
            ...(key === 'page' ? {} : { return_page: 1 }),
            [`return_${key}`]: value,
        });
    };

    // Return cases state for the return queue tab
    const [returnsList, setReturnsList] = useState<MaterialReturnCaseRow[]>([]);
    const [returnsMeta, setReturnsMeta] = useState<PaginationMeta>();
    const [returnsLoading, setReturnsLoading] = useState(false);
    const [returnsError, setReturnsError] = useState<string | null>(null);
    const [returnsBadges, setReturnsBadges] = useState<{
        my_pending_returns?: number;
        warehouse_return_queue?: number;
    }>({});

    const returnsQueueFilter = params.return_status === 'all' ? '' : (params.return_status ?? '');
    const returnsSearch = params.return_search ?? '';
    const setReturnsQueueFilter = (value: string) => setReturn('status', value || 'all');

    const fetchReturnsData = useCallback(
        (statusFilter: string, searchVal?: string) => {
            setReturnsLoading(true);
            setReturnsError(null);
            const queryParams: Record<string, string> = {
                sort: params.return_sort,
                direction: params.return_direction,
                per_page: params.return_per_page,
                page: params.return_page,
            };
            if (statusFilter) {
                queryParams.status = statusFilter;
            }
            if (searchVal && searchVal.trim()) {
                queryParams.search = searchVal.trim();
            }

            const url = (
                window as unknown as {
                    route?: (name: string, p?: Record<string, string>) => string;
                }
            ).route
                ? route('admin.inventory.returns.index', queryParams)
                : `/admin/inventory/returns?${new URLSearchParams(queryParams)}`;

            fetch(url, {
                headers: { Accept: 'application/json' },
            })
                .then(async (res) => {
                    if (!res.ok) {
                        const errData = (await res.json().catch(() => ({}))) as {
                            message?: string;
                        };
                        throw new Error(
                            errData.message ||
                                `Gagal memuat antrean retur material (${res.status}).`,
                        );
                    }
                    return res.json() as Promise<ReturnsApiResponse>;
                })
                .then((json) => {
                    setReturnsList(json.data || []);
                    setReturnsMeta(json.meta);
                    setReturnsBadges(json.badges || {});
                })
                .catch((err: unknown) => {
                    const msg =
                        err instanceof Error
                            ? err.message
                            : 'Terjadi kesalahan saat memuat antrean retur material.';
                    setReturnsError(msg);
                })
                .finally(() => {
                    setReturnsLoading(false);
                });
        },
        [params.return_sort, params.return_direction, params.return_per_page, params.return_page],
    );

    useEffect(() => {
        let isMounted = true;
        if (params.tab === 'returns' && canViewReturns) {
            void Promise.resolve().then(() => {
                if (isMounted) {
                    fetchReturnsData(returnsQueueFilter, returnsSearch);
                }
            });
        }
        return () => {
            isMounted = false;
        };
    }, [params.tab, canViewReturns, returnsQueueFilter, returnsSearch, fetchReturnsData]);

    const getNextActionText = (row: InventoryRequestRow): string => {
        switch (row.status) {
            case 'draft':
                return 'Ajukan Permintaan';
            case 'submitted':
                return 'Persetujuan Gudang';
            case 'approved_reserved':
                return 'Mulai Penyiapan Barang';
            case 'preparing':
                return 'Tandai Siap Diambil';
            case 'ready_for_pickup':
                return 'Serah Terima (Handover)';
            case 'issued':
                return 'Selesai (Deklarasi Pemakaian / Pengembalian)';
            case 'rejected':
            case 'cancelled':
                return 'Tidak Ada';
            default:
                return '—';
        }
    };

    const calculateAge = (dateStr?: string | null): string => {
        if (!dateStr) return '—';
        const diffMs = Date.now() - new Date(dateStr).getTime();
        const diffHrs = Math.floor(diffMs / (1000 * 60 * 60));
        if (diffHrs < 1) return '< 1 jam lalu';
        if (diffHrs < 24) return `${diffHrs} jam lalu`;
        const diffDays = Math.floor(diffHrs / 24);
        return `${diffDays} hari lalu`;
    };

    const columns: DataTableColumn<InventoryRequestRow>[] = useMemo(
        () => [
            {
                key: 'code',
                header: 'No. Permintaan',
                sortable: true,
                sortKey: 'code',
                cell: (row) => (
                    <div className="flex flex-col">
                        <Link
                            href={route('admin.inventory.requests.show', row.id)}
                            className="font-medium text-primary hover:underline"
                            aria-label={`Buka Permintaan ${row.code}`}
                        >
                            {row.code}
                        </Link>
                        <span className="text-xs text-muted-foreground">
                            {calculateAge(row.created_at)}
                        </span>
                    </div>
                ),
            },
            {
                key: 'status',
                header: 'Status',
                sortable: true,
                sortKey: 'status',
                cell: (row) => (
                    <StatusBadge
                        status={row.status_label || row.status}
                        variant={statusVariantMap[row.status] || 'neutral'}
                    />
                ),
            },
            {
                key: 'requester',
                header: 'Pemohon',
                cell: (row) => (
                    <div className="flex flex-col text-sm">
                        <span className="font-medium text-foreground">
                            {row.requester?.name ?? '—'}
                        </span>
                        {row.requester?.email && (
                            <span className="text-xs text-muted-foreground">
                                {row.requester.email}
                            </span>
                        )}
                    </div>
                ),
            },
            {
                key: 'warehouse_location',
                header: 'Lokasi Gudang',
                cell: (row) => row.warehouse_location?.name ?? 'Gudang Utama',
            },
            {
                key: 'items_summary',
                header: 'Barang & Jumlah',
                cell: (row) => {
                    const reqItems = row.items || [];
                    if (reqItems.length === 0)
                        return <span className="text-muted-foreground">—</span>;
                    const first = reqItems[0];
                    const remaining = reqItems.length - 1;
                    return (
                        <div className="text-sm">
                            <span className="font-medium">
                                {first.product?.name ?? 'Item'} ({first.quantity_requested}{' '}
                                {first.product?.unit ?? 'unit'})
                            </span>
                            {remaining > 0 && (
                                <span className="ml-1 text-xs text-muted-foreground">
                                    +{remaining} barang lain
                                </span>
                            )}
                        </div>
                    );
                },
            },
            {
                key: 'needed_at',
                header: 'Tgl Dibutuhkan',
                sortable: true,
                sortKey: 'needed_at',
                cell: (row) => (row.needed_at ? formatDate(row.needed_at) : '—'),
            },
            {
                key: 'source',
                header: 'Ref SPK / Tiket',
                cell: (row) => {
                    if (!row.source_type || !row.source_id) return '—';
                    return (
                        <span className="rounded bg-muted/60 px-2 py-0.5 font-mono text-xs text-foreground">
                            {row.source_type === 'work_order' ? 'SPK' : 'TIKET'} #{row.source_id}
                        </span>
                    );
                },
            },
            {
                key: 'next_action',
                header: 'Aksi Berikutnya',
                cell: (row) => (
                    <span className="text-primary-600 dark:text-primary-400 text-xs font-medium">
                        {getNextActionText(row)}
                    </span>
                ),
            },
            {
                key: 'actions',
                header: 'Aksi',
                cell: (row) => (
                    <Button variant="outline" size="sm" asChild>
                        <Link href={route('admin.inventory.requests.show', row.id)}>Detail</Link>
                    </Button>
                ),
            },
        ],
        [],
    );

    const handleTabChange = (newTab: string) => {
        setMany({ tab: newTab, page: params.page ?? 1 });
    };

    return (
        <AppLayout>
            <Head title="Permintaan Barang & Logistik Gudang" />
            <div className="space-y-6 p-4 md:p-6">
                <PageHeader
                    title="Permintaan Barang (Inventory Requests)"
                    description="Kelola pengajuan kebutuhan material teknisi, reservasi stok gudang, penyerahan barang, dan pelaporan sisa/pengembalian fisik."
                    actions={
                        (capabilities.create ?? can('inventory.request.create')) ? (
                            <Button asChild className="gap-2">
                                <Link href={route('admin.inventory.requests.create')}>
                                    <Plus className="h-4 w-4" />
                                    Buat Permintaan Baru
                                </Link>
                            </Button>
                        ) : undefined
                    }
                />

                {/* Job-based Tabs View */}
                <Tabs value={params.tab || 'my'} onValueChange={handleTabChange}>
                    <TabList className="mb-4 border-b border-border">
                        <Tab value="my" className="gap-2">
                            <Clock className="h-4 w-4" />
                            <span>Permintaan Saya</span>
                            {badgeCounts.my_requests !== null &&
                                badgeCounts.my_requests !== undefined &&
                                badgeCounts.my_requests > 0 && (
                                    <Badge variant="info" className="ml-1 px-1.5 py-0 text-xs">
                                        {badgeCounts.my_requests}
                                    </Badge>
                                )}
                        </Tab>

                        {canWarehouse && (
                            <Tab value="warehouse" className="gap-2">
                                <Package className="h-4 w-4" />
                                <span>Persiapan & Antrean Gudang</span>
                                {badgeCounts.warehouse_queue !== null &&
                                    badgeCounts.warehouse_queue !== undefined &&
                                    badgeCounts.warehouse_queue > 0 && (
                                        <Badge
                                            variant="warning"
                                            className="ml-1 px-1.5 py-0 text-xs"
                                        >
                                            {badgeCounts.warehouse_queue}
                                        </Badge>
                                    )}
                            </Tab>
                        )}

                        {canViewAll && (
                            <Tab value="all" className="gap-2">
                                <Truck className="h-4 w-4" />
                                <span>Semua Permintaan</span>
                            </Tab>
                        )}

                        {canViewReturns && (
                            <Tab value="returns" className="gap-2">
                                <RefreshCw className="h-4 w-4" />
                                <span>Antrean Pengembalian (Custody Return)</span>
                                {(() => {
                                    let count = 0;
                                    if (canReceiveReturn || canResolveReturn) {
                                        count += returnsBadges.warehouse_return_queue ?? 0;
                                    }
                                    if (
                                        canDeclareReturn &&
                                        !canReceiveReturn &&
                                        !canResolveReturn
                                    ) {
                                        count += returnsBadges.my_pending_returns ?? 0;
                                    }
                                    if (count > 0) {
                                        return (
                                            <Badge
                                                variant="warning"
                                                className="ml-1 px-1.5 py-0 text-xs"
                                            >
                                                {count}
                                            </Badge>
                                        );
                                    }
                                    return null;
                                })()}
                            </Tab>
                        )}
                    </TabList>

                    {/* Filter Bar for Requests */}
                    {params.tab !== 'returns' && (
                        <div className="mb-4 flex flex-col items-center gap-3 sm:flex-row">
                            <div className="w-full sm:w-72">
                                <Input
                                    aria-label="Cari permintaan"
                                    placeholder="Cari kode permintaan, pemohon, atau alasan..."
                                    value={params.search ?? ''}
                                    onChange={(e) => set('search', e.target.value)}
                                    className="h-9"
                                />
                            </div>
                            <div className="w-full sm:w-56">
                                <NativeSelect
                                    aria-label="Status permintaan"
                                    value={params.status ?? ''}
                                    onChange={(e) => set('status', e.target.value)}
                                    className="h-9 text-sm"
                                >
                                    <option value="">Semua Status</option>
                                    <option value="draft">Draf</option>
                                    <option value="submitted">Menunggu Persetujuan</option>
                                    <option value="approved_reserved">
                                        Disetujui / Direservasi
                                    </option>
                                    <option value="preparing">Sedang Disiapkan</option>
                                    <option value="ready_for_pickup">Siap Diambil</option>
                                    <option value="issued">Selesai Diserahkan (Issued)</option>
                                    <option value="rejected">Ditolak</option>
                                    <option value="cancelled">Dibatalkan</option>
                                </NativeSelect>
                            </div>
                            {(params.search || params.status) && (
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    onClick={() => setMany({ search: '', status: '' })}
                                >
                                    Reset Filter
                                </Button>
                            )}
                        </div>
                    )}

                    {/* Standard Requests Table (Desktop) & Cards (Mobile) */}
                    <TabPanel value="my">
                        <RequestsTableView
                            requests={requests}
                            columns={columns}
                            sortBy={sortBy}
                            sortDir={sortDir}
                            onSort={onSort}
                            onPageChange={(p) => set('page', p)}
                            onPerPageChange={(n) => set('per_page', n)}
                            getNextActionText={getNextActionText}
                            loading={processing}
                        />
                    </TabPanel>

                    {canWarehouse && (
                        <TabPanel value="warehouse">
                            <RequestsTableView
                                requests={requests}
                                columns={columns}
                                sortBy={sortBy}
                                sortDir={sortDir}
                                onSort={onSort}
                                onPageChange={(p) => set('page', p)}
                                onPerPageChange={(n) => set('per_page', n)}
                                getNextActionText={getNextActionText}
                                loading={processing}
                            />
                        </TabPanel>
                    )}

                    {canViewAll && (
                        <TabPanel value="all">
                            <RequestsTableView
                                requests={requests}
                                columns={columns}
                                sortBy={sortBy}
                                sortDir={sortDir}
                                onSort={onSort}
                                onPageChange={(p) => set('page', p)}
                                onPerPageChange={(n) => set('per_page', n)}
                                getNextActionText={getNextActionText}
                                loading={processing}
                            />
                        </TabPanel>
                    )}

                    {/* Material Return / Custody Queue Tab */}
                    {canViewReturns && (
                        <TabPanel value="returns">
                            <div className="space-y-4">
                                <div className="rounded-lg border border-border bg-card p-4 text-sm text-foreground">
                                    <div className="mb-1 flex items-center gap-2 font-medium text-brand-600 dark:text-brand-400">
                                        <AlertCircle className="h-4 w-4" />
                                        <span>Prinsip Keamanan Stok Material Return</span>
                                    </div>
                                    <p className="text-xs leading-relaxed text-muted-foreground">
                                        Deklarasi oleh teknisi{' '}
                                        <strong>tidak mengubah stok gudang</strong>. Stok hanya
                                        diperbarui secara atomik ketika petugas gudang memverifikasi
                                        fisik barang dan menyetujui penerimaan pada status{' '}
                                        <em>awaiting_receipt</em>. Kasus diskrepansi (
                                        <em>exception_open</em>) memerlukan resolusi atasan/resolver
                                        resmi sebelum status diselesaikan.
                                    </p>
                                </div>

                                {/* Queue Selector & Search Bar for Returns */}
                                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                    <div className="flex flex-wrap items-center gap-2">
                                        {(canReceiveReturn || canViewAllReturns) && (
                                            <Button
                                                variant={
                                                    returnsQueueFilter === 'awaiting_receipt'
                                                        ? 'primary'
                                                        : 'outline'
                                                }
                                                size="sm"
                                                onClick={() =>
                                                    setReturnsQueueFilter('awaiting_receipt')
                                                }
                                                className="gap-1.5 text-xs"
                                            >
                                                <Package className="h-3.5 w-3.5" />
                                                Antrean Penerimaan Gudang
                                                {returnsBadges.warehouse_return_queue !==
                                                    undefined &&
                                                    returnsBadges.warehouse_return_queue > 0 && (
                                                        <Badge
                                                            variant="warning"
                                                            className="ml-1 px-1 py-0 text-[10px]"
                                                        >
                                                            {returnsBadges.warehouse_return_queue}
                                                        </Badge>
                                                    )}
                                            </Button>
                                        )}

                                        {(canResolveReturn || canViewAllReturns) && (
                                            <Button
                                                variant={
                                                    returnsQueueFilter === 'exception_open'
                                                        ? 'primary'
                                                        : 'outline'
                                                }
                                                size="sm"
                                                onClick={() =>
                                                    setReturnsQueueFilter('exception_open')
                                                }
                                                className="gap-1.5 text-xs"
                                            >
                                                <ShieldAlert className="h-3.5 w-3.5" />
                                                Antrean Diskrepansi (Exception)
                                            </Button>
                                        )}

                                        {(canDeclareReturn ||
                                            canViewOwnReturns ||
                                            canViewAllReturns) && (
                                            <Button
                                                variant={
                                                    returnsQueueFilter === 'awaiting_declaration'
                                                        ? 'primary'
                                                        : 'outline'
                                                }
                                                size="sm"
                                                onClick={() =>
                                                    setReturnsQueueFilter('awaiting_declaration')
                                                }
                                                className="gap-1.5 text-xs"
                                            >
                                                <Clock className="h-3.5 w-3.5" />
                                                Antrean Deklarasi Teknisi
                                                {returnsBadges.my_pending_returns !== undefined &&
                                                    returnsBadges.my_pending_returns > 0 && (
                                                        <Badge
                                                            variant="info"
                                                            className="ml-1 px-1 py-0 text-[10px]"
                                                        >
                                                            {returnsBadges.my_pending_returns}
                                                        </Badge>
                                                    )}
                                            </Button>
                                        )}

                                        <Button
                                            variant={
                                                returnsQueueFilter === 'correction_required'
                                                    ? 'primary'
                                                    : 'outline'
                                            }
                                            size="sm"
                                            onClick={() =>
                                                setReturnsQueueFilter('correction_required')
                                            }
                                            className="text-xs"
                                        >
                                            Perlu Koreksi
                                        </Button>

                                        <Button
                                            variant={
                                                returnsQueueFilter === '' ? 'primary' : 'outline'
                                            }
                                            size="sm"
                                            onClick={() => setReturnsQueueFilter('')}
                                            className="text-xs"
                                        >
                                            Semua Status
                                        </Button>
                                    </div>
                                    <NativeSelect
                                        aria-label="Status pengembalian"
                                        value={returnsQueueFilter}
                                        onChange={(event) =>
                                            setReturnsQueueFilter(event.target.value)
                                        }
                                        className="w-full sm:w-64"
                                    >
                                        <option value="">Semua Status</option>
                                        {Object.entries(returnStatusLabelMap).map(
                                            ([value, label]) => (
                                                <option key={value} value={value}>
                                                    {label}
                                                </option>
                                            ),
                                        )}
                                    </NativeSelect>

                                    <div className="flex items-center gap-2">
                                        <Input
                                            aria-label="Cari pengembalian"
                                            placeholder="Cari SPK / teknisi..."
                                            value={returnsSearch}
                                            onChange={(e) => setReturn('search', e.target.value)}
                                            className="h-8 w-full text-xs sm:w-56"
                                        />
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={() =>
                                                fetchReturnsData(returnsQueueFilter, returnsSearch)
                                            }
                                            disabled={returnsLoading}
                                            className="h-8 px-2.5"
                                            aria-label="Segarkan antrean retur"
                                        >
                                            <RefreshCw
                                                className={`h-3.5 w-3.5 ${returnsLoading ? 'animate-spin' : ''}`}
                                            />
                                        </Button>
                                    </div>
                                </div>

                                {/* Accessible Error Alert State */}
                                {returnsError && (
                                    <div
                                        role="alert"
                                        aria-live="assertive"
                                        className="flex flex-col gap-2 rounded-lg border border-destructive/50 bg-destructive/10 p-4 text-sm text-destructive sm:flex-row sm:items-center sm:justify-between"
                                    >
                                        <div className="flex items-center gap-2">
                                            <AlertCircle className="h-5 w-5 shrink-0" />
                                            <span>{returnsError}</span>
                                        </div>
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={() =>
                                                fetchReturnsData(returnsQueueFilter, returnsSearch)
                                            }
                                            className="self-end border-destructive/30 text-xs text-destructive hover:bg-destructive/20 sm:self-auto"
                                        >
                                            Coba Lagi
                                        </Button>
                                    </div>
                                )}

                                <DataTable<MaterialReturnCaseRow>
                                    data={returnsList}
                                    meta={returnsMeta}
                                    loading={returnsLoading}
                                    sortBy={params.return_sort}
                                    sortDir={params.return_direction === 'asc' ? 'asc' : 'desc'}
                                    onSort={(sort, direction) =>
                                        setMany({
                                            page: params.page ?? 1,
                                            return_sort: sort,
                                            return_direction: direction ?? 'asc',
                                            return_page: 1,
                                        })
                                    }
                                    onPageChange={(page) => setReturn('page', page)}
                                    onPerPageChange={(perPage) => setReturn('per_page', perPage)}
                                    emptyTitle="Tidak ada berkas pengembalian material pada kriteria antrean ini."
                                    columns={[
                                        {
                                            key: 'id',
                                            header: 'No. Pengembalian',
                                            sortable: true,
                                            className: 'whitespace-normal',
                                            cell: (c) => (
                                                <Card
                                                    key={c.id}
                                                    className="transition-colors hover:border-primary/50"
                                                >
                                                    <CardContent className="space-y-3 p-4">
                                                        <div className="flex flex-wrap items-center justify-between gap-2">
                                                            <span className="font-semibold text-primary">
                                                                {c.inventory_request?.code ||
                                                                    `Return #${c.id}`}
                                                            </span>
                                                            <Badge
                                                                variant={
                                                                    returnStatusVariantMap[
                                                                        c.status
                                                                    ] || 'neutral'
                                                                }
                                                            >
                                                                {returnStatusLabelMap[c.status] ||
                                                                    c.status}
                                                            </Badge>
                                                        </div>

                                                        {/* Capability hints from server */}
                                                        {c.can && (
                                                            <div className="flex flex-wrap gap-1">
                                                                {c.can.resolve && (
                                                                    <Badge
                                                                        variant="warning"
                                                                        className="text-[10px]"
                                                                    >
                                                                        Eligible Resolve
                                                                    </Badge>
                                                                )}
                                                                {c.can.receive && (
                                                                    <Badge
                                                                        variant="info"
                                                                        className="text-[10px]"
                                                                    >
                                                                        Eligible Receive
                                                                    </Badge>
                                                                )}
                                                                {c.can.declare && (
                                                                    <Badge
                                                                        variant="neutral"
                                                                        className="text-[10px]"
                                                                    >
                                                                        Eligible Declare
                                                                    </Badge>
                                                                )}
                                                            </div>
                                                        )}

                                                        <div className="space-y-1 text-xs text-muted-foreground">
                                                            <div>
                                                                Teknisi:{' '}
                                                                <span className="font-medium text-foreground">
                                                                    {c.technician?.name ?? '—'}
                                                                </span>
                                                            </div>
                                                            <div>
                                                                Gudang Tujuan:{' '}
                                                                <span className="font-medium text-foreground">
                                                                    {c.warehouse_location?.name ??
                                                                        'Gudang'}
                                                                </span>
                                                            </div>
                                                            {c.work_order && (
                                                                <div>
                                                                    SPK:{' '}
                                                                    <span className="font-mono font-medium text-foreground">
                                                                        {c.work_order.code ??
                                                                            `#${c.work_order_id}`}
                                                                    </span>
                                                                </div>
                                                            )}
                                                            <div>
                                                                Tgl Deklarasi:{' '}
                                                                <span className="text-foreground">
                                                                    {c.declared_at
                                                                        ? formatDateTime(
                                                                              c.declared_at,
                                                                          )
                                                                        : '—'}
                                                                </span>
                                                            </div>
                                                        </div>

                                                        {/* Alert / Reason tags if rejected or exception */}
                                                        {c.rejection_reason && (
                                                            <div className="rounded bg-destructive/10 p-2 text-xs text-destructive">
                                                                <strong>Alasan Penolakan:</strong>{' '}
                                                                {c.rejection_reason}
                                                            </div>
                                                        )}
                                                        {c.resolution_note && (
                                                            <div className="text-success-800 dark:text-success-200 rounded bg-success/10 p-2 text-xs">
                                                                <strong>Catatan Resolusi:</strong>{' '}
                                                                {c.resolution_note}
                                                            </div>
                                                        )}

                                                        {c.lines && c.lines.length > 0 && (
                                                            <div className="border-t border-border pt-2 text-xs">
                                                                <span className="text-muted-foreground">
                                                                    Barang Dikembalikan:
                                                                </span>
                                                                <ul className="mt-1 space-y-1">
                                                                    {c.lines.map((l) => (
                                                                        <li
                                                                            key={l.id}
                                                                            className="flex justify-between"
                                                                        >
                                                                            <span className="truncate pr-2">
                                                                                {l.product?.name ??
                                                                                    'Barang'}
                                                                            </span>
                                                                            <span className="shrink-0 font-medium">
                                                                                Baik:{' '}
                                                                                {
                                                                                    l.declared_good_return_qty
                                                                                }{' '}
                                                                                | Sisa/Beda:{' '}
                                                                                {
                                                                                    l.declared_discrepancy_qty
                                                                                }
                                                                            </span>
                                                                        </li>
                                                                    ))}
                                                                </ul>
                                                            </div>
                                                        )}

                                                        <div className="pt-2">
                                                            {c.inventory_request_id ? (
                                                                <Button
                                                                    size="sm"
                                                                    className="w-full"
                                                                    asChild
                                                                >
                                                                    <Link
                                                                        href={route(
                                                                            'admin.inventory.requests.show',
                                                                            c.inventory_request_id,
                                                                        )}
                                                                    >
                                                                        {c.can?.resolve ||
                                                                        c.status ===
                                                                            'exception_open'
                                                                            ? 'Buka & Selesaikan Diskrepansi'
                                                                            : c.can?.receive ||
                                                                                c.status ===
                                                                                    'awaiting_receipt'
                                                                              ? 'Buka Permintaan & Terima Fisik'
                                                                              : c.can?.declare ||
                                                                                  c.status ===
                                                                                      'awaiting_declaration'
                                                                                ? 'Buka Permintaan & Buat Deklarasi'
                                                                                : 'Buka Permintaan'}
                                                                    </Link>
                                                                </Button>
                                                            ) : (
                                                                <Button
                                                                    size="sm"
                                                                    variant="outline"
                                                                    className="w-full"
                                                                    disabled
                                                                >
                                                                    Hanya Akses via Request
                                                                </Button>
                                                            )}
                                                        </div>
                                                    </CardContent>
                                                </Card>
                                            ),
                                        },
                                    ]}
                                />
                            </div>
                        </TabPanel>
                    )}
                </Tabs>
            </div>
        </AppLayout>
    );
}

interface RequestsTableViewProps {
    requests: PaginatedData<InventoryRequestRow>;
    columns: DataTableColumn<InventoryRequestRow>[];
    sortBy?: string;
    sortDir?: 'asc' | 'desc';
    onSort: (key: string, direction?: 'asc' | 'desc') => void;
    onPageChange: (page: number) => void;
    onPerPageChange: (perPage: number) => void;
    getNextActionText: (row: InventoryRequestRow) => string;
    loading: boolean;
}

function RequestsTableView({
    requests,
    columns,
    sortBy,
    sortDir,
    onSort,
    onPageChange,
    onPerPageChange,
    getNextActionText,
    loading,
}: RequestsTableViewProps) {
    const data = requests?.data ?? [];

    return (
        <div className="space-y-4">
            {/* Desktop / Tablet: Canonical DataTable */}
            <div className="hidden md:block">
                <DataTable<InventoryRequestRow>
                    columns={columns}
                    data={data}
                    meta={requests.meta}
                    sortBy={sortBy}
                    sortDir={sortDir}
                    onSort={onSort}
                    onPageChange={onPageChange}
                    onPerPageChange={onPerPageChange}
                    loading={loading}
                    emptyTitle="Tidak ada data permintaan barang"
                    emptyDescription="Belum ada permohonan inventaris pada tab atau kriteria filter ini."
                />
            </div>

            {/* Mobile Cards (Responsive & Scanable) */}
            <div className="space-y-3 md:hidden">
                <DataTable<InventoryRequestRow>
                    data={data}
                    meta={requests.meta}
                    sortBy={sortBy}
                    sortDir={sortDir}
                    onSort={onSort}
                    onPageChange={onPageChange}
                    onPerPageChange={onPerPageChange}
                    loading={loading}
                    emptyTitle="Tidak ada data permintaan barang pada tab ini."
                    columns={[
                        {
                            key: 'code',
                            header: 'No. Permintaan',
                            sortable: true,
                            className: 'whitespace-normal',
                            cell: (row) => (
                                <Card key={row.id} className="border border-border shadow-sm">
                                    <CardContent className="space-y-3 p-4">
                                        <div className="flex flex-wrap items-start justify-between gap-2">
                                            <div>
                                                <Link
                                                    href={route(
                                                        'admin.inventory.requests.show',
                                                        row.id,
                                                    )}
                                                    className="text-base font-semibold text-primary hover:underline"
                                                >
                                                    {row.code}
                                                </Link>
                                                <p className="mt-0.5 text-xs text-muted-foreground">
                                                    Pemohon: {row.requester?.name ?? '—'}
                                                </p>
                                            </div>
                                            <StatusBadge
                                                status={row.status_label || row.status}
                                                variant={statusVariantMap[row.status] || 'neutral'}
                                            />
                                        </div>

                                        <div className="space-y-1 text-xs text-muted-foreground">
                                            <div>
                                                Lokasi:{' '}
                                                <span className="font-medium text-foreground">
                                                    {row.warehouse_location?.name ?? 'Gudang Utama'}
                                                </span>
                                            </div>
                                            <div>
                                                Dibutuhkan:{' '}
                                                <span className="font-medium text-foreground">
                                                    {row.needed_at
                                                        ? formatDate(row.needed_at)
                                                        : '—'}
                                                </span>
                                            </div>
                                            {row.source_type && row.source_id && (
                                                <div>
                                                    Referensi:{' '}
                                                    <span className="font-mono text-foreground">
                                                        {row.source_type === 'work_order'
                                                            ? 'SPK'
                                                            : 'TIKET'}{' '}
                                                        #{row.source_id}
                                                    </span>
                                                </div>
                                            )}
                                        </div>

                                        {row.items && row.items.length > 0 && (
                                            <div className="rounded bg-muted/40 p-2 text-xs">
                                                <span className="mb-1 block text-muted-foreground">
                                                    Barang:
                                                </span>
                                                <div className="font-medium text-foreground">
                                                    {row.items[0].product?.name ?? 'Item'} (
                                                    {row.items[0].quantity_requested}{' '}
                                                    {row.items[0].product?.unit ?? 'unit'})
                                                    {row.items.length > 1 && (
                                                        <span className="ml-1 font-normal text-muted-foreground">
                                                            +{row.items.length - 1} barang lainnya
                                                        </span>
                                                    )}
                                                </div>
                                            </div>
                                        )}

                                        <div className="flex items-center justify-between border-t border-border pt-2">
                                            <span className="text-xs font-medium text-primary">
                                                {getNextActionText(row)}
                                            </span>
                                            <Button size="sm" variant="outline" asChild>
                                                <Link
                                                    href={route(
                                                        'admin.inventory.requests.show',
                                                        row.id,
                                                    )}
                                                >
                                                    Buka
                                                </Link>
                                            </Button>
                                        </div>
                                    </CardContent>
                                </Card>
                            ),
                        },
                    ]}
                />
            </div>
        </div>
    );
}
