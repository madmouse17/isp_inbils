import { useState, type FormEvent } from 'react';
import { Link, router } from '@inertiajs/react';
import { PageHeader } from '@/Components/composite';
import { DataTable, type DataTableColumn } from '@/Components/composite/DataTable';
import { ForbiddenState, LoadingState, ResourceEmpty } from '@/Components/composite/ResourceState';
import { Badge, Button, Card, CardContent, EnumBadge } from '@/Components/ui';
import { Pagination } from '@/Components/ui/Pagination';
import { Tabs, TabList, Tab } from '@/Components/ui/Tabs';
import AdminLayout from '@/Layouts/AdminLayout';
import { useCompany } from '@/hooks/useCompany';
import { usePermission } from '@/hooks/usePermission';
import { useServerTable, type ServerTableFilters } from '@/hooks/useServerTable';
import { useToast } from '@/hooks/useToast';
import { formatDate } from '@/lib/format';
import { toPagination } from '@/lib/pagination';
import {
    APPROVAL_TYPE,
    labelOf,
    PRIORITY,
    WORK_ITEM_DOMAIN,
    WORK_ITEM_STATUS,
} from '@/lib/statuses';
import type { ApprovalInboxItem, ApprovalInboxSummary, PaginationMeta } from '@/types';
import { SummaryChips } from '../MyWork/components/SummaryChips';
import { formatAge } from '../MyWork/components/utils';
import { FilterBar } from './components/FilterBar';
import { MobileItemCard } from './components/MobileItemCard';
import { ReviewSheet, type RefreshQueueResult } from './components/ReviewSheet';
import { getItemCompositeId } from './components/utils';

export type { ApprovalInboxItem };

export interface ReloadQueueParams {
    router: {
        reload: (options: {
            only?: string[];
            onSuccess?: (page: { props: Record<string, unknown> }) => void;
            onError?: (errors: Record<string, unknown>) => void;
            onCancel?: () => void;
            onFinish?: (visit?: unknown) => void;
        }) => void;
    };
    selectedItem: ApprovalInboxItem | null;
    setSelectedItem: (item: ApprovalInboxItem | null) => void;
    setReviewOpen: (open: boolean) => void;
}

export function reloadQueue({
    router,
    selectedItem,
    setSelectedItem,
    setReviewOpen,
}: ReloadQueueParams): Promise<RefreshQueueResult> {
    return new Promise((resolve) => {
        let settled = false;
        const settle = (result: RefreshQueueResult) => {
            if (settled) return;
            settled = true;
            resolve(result);
        };

        try {
            router.reload({
                only: ['items', 'summary', 'filters', 'types', 'requesters'],
                onSuccess: (page) => {
                    const freshItems =
                        (
                            page.props.items as
                                | {
                                      data?: ApprovalInboxItem[];
                                  }
                                | undefined
                        )?.data ?? [];

                    const currentCompositeId = selectedItem
                        ? getItemCompositeId(selectedItem)
                        : null;
                    const fresh = currentCompositeId
                        ? (freshItems.find((it) => getItemCompositeId(it) === currentCompositeId) ??
                          null)
                        : null;

                    if (!fresh || (!fresh.actions?.approve && !fresh.actions?.reject)) {
                        setSelectedItem(null);
                        setReviewOpen(false);
                        settle({ success: true, freshItem: null });
                    } else {
                        setSelectedItem(fresh);
                        settle({ success: true, freshItem: fresh });
                    }
                },
                onError: () => {
                    settle({
                        success: false,
                        freshItem: null,
                        errorMessage:
                            'Gagal memuat ulang antrean. Status antrean belum dapat diperbarui.',
                    });
                },
                onCancel: () => {
                    settle({
                        success: false,
                        freshItem: null,
                        errorMessage:
                            'Pemuatan ulang antrean dibatalkan. Status antrean belum dapat diperbarui.',
                    });
                },
                onFinish: () => {
                    settle({
                        success: false,
                        freshItem: null,
                        errorMessage:
                            'Gagal memuat ulang antrean. Terjadi gangguan koneksi atau respons tidak valid.',
                    });
                },
            });
        } catch {
            settle({
                success: false,
                freshItem: null,
                errorMessage:
                    'Gagal memuat ulang antrean. Terjadi kesalahan jaringan atau eksekusi.',
            });
        }
    });
}

interface ApprovalInboxProps extends Record<string, unknown> {
    items: {
        data: ApprovalInboxItem[];
        meta: PaginationMeta;
    };
    summary: ApprovalInboxSummary;
    filters: ServerTableFilters;
    types: string[];
    requesters: { id: string; name: string }[];
}

export default function ApprovalInboxIndex({
    items,
    summary,
    filters,
    types,
    requesters,
}: ApprovalInboxProps) {
    useToast();
    const company = useCompany();
    const { canAny } = usePermission();
    const canSpk = canAny(['spk.approve', 'spk.reject']);
    const canInventory = canAny(['inventory.request.approve', 'inventory.request.reject']);
    const isEligible = canSpk || canInventory;

    const { params, set, setMany, sortBy, sortDir, onSort, setPage, processing, visiting } =
        useServerTable({
            url: route('admin.approvals.index'),
            filters,
            only: ['items', 'summary', 'filters', 'types', 'requesters'],
        });

    const [selectedItem, setSelectedItem] = useState<ApprovalInboxItem | null>(null);
    const [reviewOpen, setReviewOpen] = useState(false);

    if (!isEligible) {
        return (
            <AdminLayout title="Persetujuan">
                <div className="space-y-6">
                    <PageHeader
                        title="Persetujuan"
                        subtitle="Antrean SPK dan permintaan barang yang menunggu persetujuan Anda."
                    />
                    <ForbiddenState
                        title="Akses ditolak"
                        description="Anda tidak punya izin untuk melihat atau memproses antrean persetujuan SPK atau permintaan barang."
                        backHref="/admin/dashboard"
                        backLabel="Kembali ke Dasbor"
                    />
                </div>
            </AdminLayout>
        );
    }

    const handleOpenReview = (item: ApprovalInboxItem) => {
        setSelectedItem(item);
        setReviewOpen(true);
    };

    const handleOpenChange = (open: boolean) => {
        setReviewOpen(open);
        if (!open) {
            setSelectedItem(null);
        }
    };

    const handleRefreshQueue = (): Promise<RefreshQueueResult> => {
        return reloadQueue({
            router,
            selectedItem,
            setSelectedItem,
            setReviewOpen,
        });
    };

    const handleDomainChange = (val: string) => {
        setMany({
            domain: val,
            type: '',
            page: 1,
        });
    };

    const submitSearch = (event: FormEvent) => {
        event.preventDefault();
        set('search', params.search ?? '');
    };

    const columns: DataTableColumn<ApprovalInboxItem>[] = [
        {
            key: 'code',
            header: 'Kode',
            sortable: true,
            sortKey: 'code',
            cell: (row) => (
                <div className="space-y-1">
                    {row.actions?.view && row.detail_url ? (
                        <Link
                            href={row.detail_url}
                            className="font-semibold text-primary-text hover:underline focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-hidden"
                        >
                            {row.code}
                        </Link>
                    ) : (
                        <span className="font-semibold text-foreground">{row.code}</span>
                    )}
                    <div>
                        <EnumBadge map={WORK_ITEM_DOMAIN} value={row.domain} />
                    </div>
                </div>
            ),
        },
        {
            key: 'title',
            header: 'Judul dan dampak',
            sortable: true,
            sortKey: 'title',
            cell: (row) => (
                <div className="space-y-0.5 max-w-xs">
                    <span className="line-clamp-2 text-sm font-medium text-foreground">
                        {row.title}
                    </span>
                    <p className="text-xs text-muted-foreground">
                        {labelOf(APPROVAL_TYPE, row.type)}
                        {' | '}
                        {row.summary.label}: {row.summary.count} jenis, {row.summary.quantity} unit
                    </p>
                </div>
            ),
        },
        {
            key: 'status',
            header: 'Status dan prioritas',
            sortable: true,
            sortKey: 'priority',
            cell: (row) => (
                <div className="flex flex-col items-start gap-1">
                    <EnumBadge map={WORK_ITEM_STATUS} value={row.status} />
                    <EnumBadge map={PRIORITY} value={row.priority} />
                </div>
            ),
        },
        {
            key: 'requester',
            header: 'Pemohon',
            cell: (row) => (
                <span className="text-sm font-medium text-foreground">
                    {row.requester?.name ?? '-'}
                </span>
            ),
        },
        {
            key: 'submitted_at',
            header: 'Waktu dan jadwal',
            sortable: true,
            sortKey: 'created_at',
            cell: (row) => (
                <div className="space-y-1 text-xs">
                    <div>
                        <span className="text-muted-foreground">Diajukan: </span>
                        <span className="font-medium text-foreground">
                            {formatDate(row.submitted_at, company)}
                        </span>
                        <span className="text-muted-foreground">
                            {' '}
                            ({formatAge(row.age_seconds)})
                        </span>
                    </div>
                    <div>
                        <span className="text-muted-foreground">
                            {row.domain === 'inventory_request' ? 'Dibutuhkan: ' : 'Jadwal: '}
                        </span>
                        <span className="font-medium text-foreground">
                            {formatDate(row.scheduled_date, company)}
                        </span>
                    </div>
                </div>
            ),
        },
        {
            key: 'actions',
            header: 'Tindakan',
            cell: (row) =>
                row.actions.approve || row.actions.reject ? (
                    <Button
                        type="button"
                        size="sm"
                        variant="primary"
                        onClick={() => handleOpenReview(row)}
                    >
                        Tinjau
                    </Button>
                ) : (
                    <span className="text-xs text-muted-foreground">Perlu penyetuju lain</span>
                ),
        },
    ];

    const hasItems = items.data.length > 0;
    const showDomainTabs = canSpk && canInventory;
    const hasFilters = Boolean(params.search || params.type || params.creator || params.priority);
    const resetFilters = () => setMany({ search: '', type: '', creator: '', priority: '' });

    return (
        <AdminLayout title="Persetujuan">
            <div className="space-y-6">
                <PageHeader
                    title="Persetujuan"
                    subtitle="SPK dan permintaan barang yang menunggu persetujuan Anda."
                />

                <SummaryChips
                    label="Ringkasan antrean persetujuan"
                    chips={[
                        { key: 'waiting', label: 'Menunggu saya', count: summary.waiting_for_me },
                        { key: 'processed', label: 'Sudah diproses', count: summary.processed },
                    ]}
                />

                {showDomainTabs && (
                    <Tabs
                        value={String(params.domain ?? '')}
                        onValueChange={handleDomainChange}
                        className="space-y-2"
                    >
                        <TabList className="border-b border-border" aria-label="Pilihan antrean">
                            <Tab value="" className="gap-2">
                                <span>Semua antrean</span>
                                {!params.domain && summary.waiting_for_me > 0 && (
                                    <Badge variant="secondary" className="ml-1 px-1.5 py-0 text-xs">
                                        {summary.waiting_for_me}
                                    </Badge>
                                )}
                            </Tab>
                            <Tab value="spk" className="gap-2">
                                <span>SPK</span>
                                {params.domain === 'spk' && summary.waiting_for_me > 0 && (
                                    <Badge variant="secondary" className="ml-1 px-1.5 py-0 text-xs">
                                        {summary.waiting_for_me}
                                    </Badge>
                                )}
                            </Tab>
                            <Tab value="inventory_request" className="gap-2">
                                <span>Permintaan barang</span>
                                {params.domain === 'inventory_request' &&
                                    summary.waiting_for_me > 0 && (
                                        <Badge
                                            variant="secondary"
                                            className="ml-1 px-1.5 py-0 text-xs"
                                        >
                                            {summary.waiting_for_me}
                                        </Badge>
                                    )}
                            </Tab>
                        </TabList>
                    </Tabs>
                )}

                <Card>
                    <CardContent className="space-y-4 pt-6">
                        <FilterBar
                            params={params}
                            set={set}
                            setMany={setMany}
                            types={types}
                            requesters={requesters}
                            onSubmitSearch={submitSearch}
                            disabled={visiting}
                        />

                        <div className="hidden md:block">
                            <DataTable
                                columns={columns}
                                pagination={toPagination(items)}
                                sortBy={sortBy}
                                sortDir={sortDir}
                                onSort={onSort}
                                onPageChange={setPage}
                                onPerPageChange={(perPage) => set('per_page', perPage)}
                                loading={processing}
                                getRowId={getItemCompositeId}
                                rowKey="composite_id"
                                hasFilters={hasFilters}
                                onResetFilters={resetFilters}
                                itemLabel="persetujuan"
                                emptyTitle="Tidak ada persetujuan yang menunggu"
                                emptyDescription="Semua antrean sudah diproses. Item baru muncul di sini setelah diajukan."
                            />
                        </div>

                        <div className="space-y-4 md:hidden">
                            {processing && !hasItems && <LoadingState variant="rows" rows={4} />}

                            {!processing && !hasItems && (
                                <ResourceEmpty
                                    title="Tidak ada persetujuan yang menunggu"
                                    description="Semua antrean sudah diproses. Item baru muncul di sini setelah diajukan."
                                    className="my-4"
                                />
                            )}

                            {hasItems && (
                                <div className="space-y-3">
                                    {items.data.map((item) => (
                                        <MobileItemCard
                                            key={getItemCompositeId(item)}
                                            item={item}
                                            onReview={handleOpenReview}
                                        />
                                    ))}

                                    <div className="pt-2">
                                        <Pagination
                                            meta={items.meta}
                                            onPageChange={setPage}
                                            className="pt-2"
                                        />
                                    </div>
                                </div>
                            )}
                        </div>
                    </CardContent>
                </Card>

                <ReviewSheet
                    open={reviewOpen}
                    onOpenChange={handleOpenChange}
                    item={selectedItem}
                    onRefreshQueue={handleRefreshQueue}
                />
            </div>
        </AdminLayout>
    );
}
