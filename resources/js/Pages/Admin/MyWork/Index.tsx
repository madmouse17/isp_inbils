import { useState, type FormEvent } from 'react';
import { router } from '@inertiajs/react';
import {
    CheckCircleIcon,
    ExclamationTriangleIcon,
    PaperAirplaneIcon,
    PlayIcon,
    XCircleIcon,
} from '@heroicons/react/24/outline';
import { PageHeader } from '@/Components/composite';
import { DataTable, type DataTableColumn } from '@/Components/composite/DataTable';
import { LoadingState, ResourceEmpty } from '@/Components/composite/ResourceState';
import { Badge, Button, Card, CardContent } from '@/Components/ui';
import { Pagination } from '@/Components/ui/Pagination';
import { Tab, TabList, Tabs } from '@/Components/ui/Tabs';
import AdminLayout from '@/Layouts/AdminLayout';
import { useServerTable, type ServerTableFilters } from '@/hooks/useServerTable';
import { useToast } from '@/hooks/useToast';
import { toPagination } from '@/lib/pagination';
import type {
    DispatchSummary,
    MyWorkItem,
    MyWorkSummary,
    PaginationMeta,
    QueueDomainOption,
} from '@/types';
import {
    ActionConfirmDialog,
    type ActionType,
    type MyWorkReloadResult,
} from './components/ActionConfirmDialog';
import { FilterBar } from './components/FilterBar';
import { MobileMyWorkCard } from './components/MobileMyWorkCard';
import { ResolveDialog } from './components/ResolveDialog';
import { SummaryCards } from './components/SummaryCards';
import {
    formatAge,
    formatDate,
    getDomainLabel,
    getDomainVariant,
    getItemNextStatus,
    getPriorityLabel,
    getPriorityVariant,
    getStatusLabel,
    getStatusVariant,
} from './components/utils';

interface MyWorkIndexProps extends Record<string, unknown> {
    items: {
        data: MyWorkItem[];
        meta: PaginationMeta;
    };
    summary: MyWorkSummary;
    filters: ServerTableFilters;
    domains: QueueDomainOption[];
    canDispatch: boolean;
    dispatchSummary?: DispatchSummary | null;
}

export default function MyWorkIndex({
    items,
    summary,
    filters,
    domains,
    canDispatch,
    dispatchSummary,
}: MyWorkIndexProps) {
    useToast();

    const { params, set, setMany, sortBy, sortDir, onSort, setPage, processing, visiting } =
        useServerTable({
            url: route('admin.my-work.index'),
            filters,
            only: ['items', 'summary', 'filters', 'domains', 'dispatchSummary'],
        });

    // Operational action modal states
    const [resolveItem, setResolveItem] = useState<MyWorkItem | null>(null);
    const [resolveOpen, setResolveOpen] = useState(false);
    const [confirmItem, setConfirmItem] = useState<MyWorkItem | null>(null);
    const [confirmAction, setConfirmAction] = useState<ActionType | null>(null);
    const [confirmOpen, setConfirmOpen] = useState(false);

    const submitSearch = (event: FormEvent) => {
        event.preventDefault();
        set('search', params.search ?? '');
    };

    const handleOpenResolve = (item: MyWorkItem) => {
        setResolveItem(item);
        setResolveOpen(true);
    };

    const handleOpenConfirm = (item: MyWorkItem, action: ActionType) => {
        setConfirmItem(item);
        setConfirmAction(action);
        setConfirmOpen(true);
    };

    const reloadMyWork = (
        selectedItem: MyWorkItem | null,
        setSelectedItem: (item: MyWorkItem | null) => void,
        closeDialog: () => void,
        isActionable: (item: MyWorkItem) => boolean,
    ): Promise<MyWorkReloadResult> =>
        new Promise((resolve) => {
            let settled = false;
            const settle = (result: MyWorkReloadResult) => {
                if (settled) return;
                settled = true;
                resolve(result);
            };

            try {
                router.reload({
                    only: ['items', 'summary', 'filters', 'domains', 'dispatchSummary'],
                    onStart: () => undefined,
                    onSuccess: (page) => {
                        const freshItems =
                            (page.props.items as { data?: MyWorkItem[] } | undefined)?.data ?? [];
                        const fresh = selectedItem
                            ? (freshItems.find(
                                  (candidate) =>
                                      candidate.composite_id === selectedItem.composite_id,
                              ) ?? null)
                            : null;

                        if (!fresh || !isActionable(fresh)) {
                            setSelectedItem(null);
                            closeDialog();
                            settle({ success: true, freshItem: null });
                            return;
                        }

                        setSelectedItem(fresh);
                        settle({ success: true, freshItem: fresh });
                    },
                    onError: () =>
                        settle({
                            success: false,
                            errorMessage:
                                'Gagal memuat ulang pekerjaan. Status item belum dapat diperbarui.',
                        }),
                    onCancel: () =>
                        settle({
                            success: false,
                            errorMessage:
                                'Pemuatan ulang pekerjaan dibatalkan. Status item belum dapat diperbarui.',
                        }),
                    onFinish: () =>
                        settle({
                            success: false,
                            errorMessage:
                                'Gagal memuat ulang pekerjaan. Terjadi gangguan koneksi atau respons tidak valid.',
                        }),
                });
            } catch {
                settle({
                    success: false,
                    errorMessage: 'Gagal memuat ulang pekerjaan. Terjadi kesalahan jaringan.',
                });
            }
        });

    const handleStatusCardClick = (statusValue: string) => {
        setMany({
            status: statusValue,
            page: 1,
        });
    };

    const columns: DataTableColumn<MyWorkItem>[] = [
        {
            key: 'code',
            header: 'Kode & Domain',
            sortable: true,
            sortKey: 'code',
            cell: (row) => (
                <div className="space-y-1">
                    {row.actions.view && row.detail_url ? (
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
            header: 'Judul & Pelanggan',
            sortable: true,
            sortKey: 'title',
            cell: (row) => (
                <div className="space-y-1 max-w-xs">
                    <span className="line-clamp-2 text-sm font-medium text-foreground">
                        {row.title}
                    </span>
                    <div className="text-xs text-muted-foreground">
                        Pelanggan:{' '}
                        <span className="font-medium text-foreground">
                            {row.customer?.name ?? '—'}
                        </span>
                    </div>
                    {row.sla_breached && (
                        <div className="flex items-center gap-1 text-[11px] font-semibold text-destructive">
                            <ExclamationTriangleIcon className="h-3.5 w-3.5 shrink-0" />
                            <span>SLA Terlampaui</span>
                        </div>
                    )}
                </div>
            ),
        },
        {
            key: 'priority',
            header: 'Prioritas & Umur',
            sortable: true,
            sortKey: 'priority',
            cell: (row) => (
                <div className="space-y-1">
                    <Badge
                        variant={getPriorityVariant(row.priority)}
                        className="text-[10px] font-semibold uppercase"
                    >
                        {getPriorityLabel(row.priority)}
                    </Badge>
                    <p className="text-xs text-muted-foreground">{formatAge(row.age_seconds)}</p>
                </div>
            ),
        },
        {
            key: 'status',
            header: 'Status & Alur',
            sortable: true,
            sortKey: 'status',
            cell: (row) => (
                <div className="space-y-1">
                    <Badge variant={getStatusVariant(row.status)} className="text-[10px]">
                        {getStatusLabel(row.status)}
                    </Badge>
                    <p className="text-[11px] text-muted-foreground">{getItemNextStatus(row)}</p>
                </div>
            ),
        },
        {
            key: 'due_at',
            header: 'Batas / Jadwal',
            cell: (row) => (
                <div className="space-y-0.5 text-xs">
                    {row.domain === 'ticket' ? (
                        <>
                            <span className="text-muted-foreground">SLA: </span>
                            <span className="font-medium text-foreground">
                                {formatDate(row.due_at ?? row.summary?.sla_deadline)}
                            </span>
                        </>
                    ) : (
                        <>
                            <span className="text-muted-foreground">Jadwal: </span>
                            <span className="font-medium text-foreground">
                                {formatDate(row.scheduled_date ?? row.summary?.scheduled_date)}
                            </span>
                        </>
                    )}
                </div>
            ),
        },
        {
            key: 'actions',
            header: 'Tindakan Operasional',
            className: 'text-right',
            cell: (row) => (
                <div className="flex flex-wrap items-center justify-end gap-1.5 [&>button]:min-h-11">
                    {row.actions.view && row.detail_url && (
                        <a
                            href={row.detail_url}
                            className="mr-1 inline-flex items-center text-xs font-medium text-primary hover:underline"
                        >
                            Detail
                        </a>
                    )}
                    {row.actions.start && (
                        <Button
                            type="button"
                            size="sm"
                            variant="default"
                            onClick={() => handleOpenConfirm(row, 'start')}
                        >
                            <PlayIcon className="mr-1 h-3.5 w-3.5" />
                            Mulai
                        </Button>
                    )}
                    {row.actions.resolve && (
                        <Button
                            type="button"
                            size="sm"
                            variant="default"
                            onClick={() => handleOpenResolve(row)}
                        >
                            <CheckCircleIcon className="mr-1 h-3.5 w-3.5" />
                            Selesaikan
                        </Button>
                    )}
                    {row.actions.submit && (
                        <Button
                            type="button"
                            size="sm"
                            variant="default"
                            onClick={() => handleOpenConfirm(row, 'submit')}
                        >
                            <PaperAirplaneIcon className="mr-1 h-3.5 w-3.5" />
                            Ajukan Review
                        </Button>
                    )}
                    {row.actions.close && (
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            onClick={() => handleOpenConfirm(row, 'close')}
                        >
                            <XCircleIcon className="mr-1 h-3.5 w-3.5" />
                            Tutup
                        </Button>
                    )}
                </div>
            ),
        },
    ];

    const hasItems = items.data && items.data.length > 0;

    return (
        <AdminLayout title="Pekerjaan Saya">
            <div className="space-y-6">
                <PageHeader
                    title="Pekerjaan Saya"
                    subtitle="Pusat pelaksanaan dan tindak lanjut tugas tiket & SPK yang dialokasikan kepada Anda"
                />

                {/* Optional Job-based Tabs if user also can dispatch */}
                {canDispatch && (
                    <Tabs
                        value="my_work"
                        onValueChange={(val: string) => {
                            if (val === 'dispatch') {
                                router.get(route('admin.dispatch.index'));
                            }
                        }}
                    >
                        <TabList
                            className="border-b border-border"
                            aria-label="Navigasi Operasional"
                        >
                            <Tab value="my_work" className="gap-2">
                                <span>Pekerjaan Saya</span>
                                {summary.total > 0 && (
                                    <Badge variant="secondary" className="ml-1 px-1.5 py-0 text-xs">
                                        {summary.total}
                                    </Badge>
                                )}
                            </Tab>
                            <Tab value="dispatch" className="gap-2">
                                <span>Antrean Penugasan</span>
                                {dispatchSummary && dispatchSummary.unassigned > 0 && (
                                    <Badge variant="secondary" className="ml-1 px-1.5 py-0 text-xs">
                                        {dispatchSummary.unassigned}
                                    </Badge>
                                )}
                            </Tab>
                        </TabList>
                    </Tabs>
                )}

                {/* Summary Cards */}
                <SummaryCards
                    summary={summary}
                    activeStatus={String(params.status ?? '')}
                    onSelectStatus={handleStatusCardClick}
                />

                {/* Main Content Area: Filter and Table */}
                <Card className="border-border bg-card shadow-xs">
                    <CardContent className="space-y-4 pt-6">
                        <FilterBar
                            params={params}
                            set={set}
                            setMany={setMany}
                            domains={domains}
                            onSubmitSearch={submitSearch}
                            disabled={visiting}
                        />

                        {/* Desktop Table View */}
                        <div className="hidden md:block">
                            <DataTable
                                columns={columns}
                                data={items.data}
                                pagination={toPagination(items)}
                                sortBy={sortBy}
                                sortDir={sortDir}
                                onSort={onSort}
                                onPageChange={setPage}
                                onPerPageChange={(perPage) => set('per_page', perPage)}
                                loading={processing}
                                emptyTitle="Tidak ada tugas aktif"
                                emptyDescription="Anda tidak memiliki tiket atau SPK aktif yang sedang ditugaskan kepada Anda saat ini."
                            />
                        </div>

                        {/* Mobile Stacked Cards View */}
                        <div className="space-y-4 md:hidden">
                            {processing && !hasItems && <LoadingState variant="rows" rows={4} />}

                            {!processing && !hasItems && (
                                <ResourceEmpty
                                    title="Tidak ada tugas aktif"
                                    description="Anda tidak memiliki tiket atau SPK aktif yang sedang ditugaskan kepada Anda saat ini."
                                    className="my-4"
                                />
                            )}

                            {hasItems && (
                                <div className="space-y-3">
                                    {items.data.map((item) => (
                                        <MobileMyWorkCard
                                            key={item.composite_id}
                                            item={item}
                                            onActionConfirm={handleOpenConfirm}
                                            onResolve={handleOpenResolve}
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

                {/* Operational Action Modals */}
                <ResolveDialog
                    open={resolveOpen}
                    onOpenChange={setResolveOpen}
                    item={resolveItem}
                    onReload={() =>
                        reloadMyWork(
                            resolveItem,
                            setResolveItem,
                            () => setResolveOpen(false),
                            (fresh) => Boolean(fresh.actions.resolve),
                        )
                    }
                    onSuccess={() => {
                        router.reload({ only: ['items', 'summary'] });
                    }}
                />

                <ActionConfirmDialog
                    open={confirmOpen}
                    onOpenChange={setConfirmOpen}
                    item={confirmItem}
                    actionType={confirmAction}
                    onReload={() =>
                        reloadMyWork(
                            confirmItem,
                            setConfirmItem,
                            () => setConfirmOpen(false),
                            (fresh) => Boolean(confirmAction && fresh.actions[confirmAction]),
                        )
                    }
                    onSuccess={() => {
                        router.reload({ only: ['items', 'summary'] });
                    }}
                />
            </div>
        </AdminLayout>
    );
}
