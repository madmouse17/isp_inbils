import { useState, type FormEvent } from 'react';
import { router } from '@inertiajs/react';
import { PageHeader } from '@/Components/composite';
import { DataTable, type DataTableColumn } from '@/Components/composite/DataTable';
import { ForbiddenState, LoadingState, ResourceEmpty } from '@/Components/composite/ResourceState';
import { Badge, Button, Card, CardContent } from '@/Components/ui';
import { Pagination } from '@/Components/ui/Pagination';
import { Tabs, TabList, Tab } from '@/Components/ui/Tabs';
import AdminLayout from '@/Layouts/AdminLayout';
import { usePermission } from '@/hooks/usePermission';
import { useServerTable, type ServerTableFilters } from '@/hooks/useServerTable';
import { useToast } from '@/hooks/useToast';
import { toPagination } from '@/lib/pagination';
import type { ApprovalInboxItem, ApprovalInboxSummary, PaginationMeta } from '@/types';
import { FilterBar } from './components/FilterBar';
import { MobileItemCard } from './components/MobileItemCard';
import { ReviewSheet, type RefreshQueueResult } from './components/ReviewSheet';
import { SummaryCards } from './components/SummaryCards';
import {
    formatAge,
    getDomainLabel,
    getDomainVariant,
    getItemCompositeId,
    getPriorityLabel,
    getPriorityVariant,
    getStatusLabel,
    getStatusVariant,
} from './components/utils';

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
    requesters: { id: number; name: string }[];
}

export default function ApprovalInboxIndex({
    items,
    summary,
    filters,
    types,
    requesters,
}: ApprovalInboxProps) {
    useToast();
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
                        subtitle="Antrean peninjauan dan persetujuan yang membutuhkan tindakan Anda"
                    />
                    <ForbiddenState
                        title="Akses Ditolak"
                        description="Anda tidak memiliki izin untuk melihat atau memproses antrean persetujuan (SPK atau Permintaan Barang)."
                        backHref="/admin/dashboard"
                        backLabel="Kembali ke Dashboard"
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
                        <a
                            href={row.detail_url}
                            className="font-semibold text-primary hover:underline focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-hidden"
                        >
                            {row.code}
                        </a>
                    ) : (
                        <span className="font-semibold text-foreground">{row.code}</span>
                    )}
                    <div>
                        <Badge
                            variant={getDomainVariant(row.domain)}
                            className="text-[10px] font-semibold uppercase"
                        >
                            {getDomainLabel(row.domain)}
                        </Badge>
                    </div>
                </div>
            ),
        },
        {
            key: 'title',
            header: 'Judul / Alasan',
            sortable: true,
            sortKey: 'title',
            cell: (row) => (
                <span className="line-clamp-2 text-sm font-medium text-foreground">
                    {row.title}
                </span>
            ),
        },
        {
            key: 'type',
            header: 'Tipe',
            sortable: true,
            sortKey: 'type',
            cell: (row) => <Badge variant="outline">{row.type}</Badge>,
        },
        {
            key: 'status',
            header: 'Status',
            cell: (row) => (
                <Badge variant={getStatusVariant(row.status)}>{getStatusLabel(row.status)}</Badge>
            ),
        },
        {
            key: 'priority',
            header: 'Prioritas',
            sortable: true,
            sortKey: 'priority',
            cell: (row) => (
                <Badge variant={getPriorityVariant(row.priority)}>
                    {getPriorityLabel(row.priority)}
                </Badge>
            ),
        },
        {
            key: 'requester',
            header: 'Pemohon',
            cell: (row) => (
                <span className="text-sm font-medium text-foreground">
                    {row.requester?.name ?? `#${row.requester?.id ?? '—'}`}
                </span>
            ),
        },
        {
            key: 'summary',
            header: 'Dampak Material',
            cell: (row) => (
                <div className="space-y-0.5 text-xs">
                    <p className="line-clamp-1 font-medium text-foreground">{row.summary.label}</p>
                    <p className="text-muted-foreground">
                        {row.summary.count} jenis barang &middot; {row.summary.quantity} unit total
                    </p>
                </div>
            ),
        },
        {
            key: 'submitted_at',
            header: 'Waktu & Jadwal',
            sortable: true,
            sortKey: 'created_at',
            cell: (row) => (
                <div className="space-y-1 text-xs">
                    <div>
                        <span className="text-muted-foreground">Diajukan: </span>
                        <span className="font-medium text-foreground">
                            {row.submitted_at
                                ? new Date(row.submitted_at).toLocaleDateString('id-ID')
                                : '—'}
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
                            {row.scheduled_date || '—'}
                        </span>
                    </div>
                </div>
            ),
        },
        {
            key: 'actions',
            header: 'Aksi',
            cell: (row) => (
                <div className="flex items-center gap-2">
                    {row.actions.approve || row.actions.reject ? (
                        <Button
                            type="button"
                            size="sm"
                            variant="default"
                            onClick={() => handleOpenReview(row)}
                        >
                            Tinjau
                        </Button>
                    ) : (
                        <span className="rounded border border-muted bg-muted/30 px-2 py-0.5 text-[11px] text-muted-foreground italic">
                            Maker-checker
                        </span>
                    )}
                    {row.actions?.view && row.detail_url && (
                        <Button asChild size="sm" variant="outline">
                            <a href={row.detail_url}>Lihat detail</a>
                        </Button>
                    )}
                </div>
            ),
        },
    ];

    const hasItems = items.data.length > 0;
    const showDomainTabs = canSpk && canInventory;

    return (
        <AdminLayout title="Persetujuan">
            <div className="space-y-6">
                <PageHeader
                    title="Persetujuan"
                    subtitle="Antrean peninjauan dan persetujuan yang membutuhkan tindakan Anda"
                />

                {/* Summary Metrics Cards */}
                <SummaryCards summary={summary} domain={String(params.domain ?? '')} />

                {/* Domain Selector Tabs (when user has permissions for both SPK & Inventory) */}
                {showDomainTabs && (
                    <Tabs
                        value={String(params.domain ?? '')}
                        onValueChange={handleDomainChange}
                        className="space-y-2"
                    >
                        <TabList
                            className="border-b border-border"
                            aria-label="Pilihan Antrean Approval"
                        >
                            <Tab value="" className="gap-2">
                                <span>Semua Antrean</span>
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
                                <span>Permintaan Barang</span>
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

                {/* Main Content Area: Filter and Items */}
                <Card className="border-border bg-card shadow-xs">
                    <CardContent className="space-y-4 pt-6">
                        {/* Server-driven Filter Bar */}
                        <FilterBar
                            params={params}
                            set={set}
                            setMany={setMany}
                            types={types}
                            requesters={requesters}
                            onSubmitSearch={submitSearch}
                            disabled={visiting}
                        />

                        {/* Desktop Table View (md and above) */}
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
                                emptyTitle="Tidak ada persetujuan yang menunggu"
                                emptyDescription="Semua antrean telah diproses atau tidak ada yang memerlukan peninjauan Anda saat ini."
                            />
                        </div>

                        {/* Mobile Stacked Cards View (below md) */}
                        <div className="space-y-4 md:hidden">
                            {processing && !hasItems && <LoadingState variant="rows" rows={4} />}

                            {!processing && !hasItems && (
                                <ResourceEmpty
                                    title="Tidak ada persetujuan yang menunggu"
                                    description="Semua antrean telah diproses atau tidak ada yang memerlukan peninjauan Anda saat ini."
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

                {/* Review Slide-over Sheet */}
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
