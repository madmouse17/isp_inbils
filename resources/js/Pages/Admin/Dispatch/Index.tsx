import { useState, type FormEvent } from 'react';
import { Link, router } from '@inertiajs/react';
import { AlertTriangle, User } from 'lucide-react';
import { PageHeader } from '@/Components/composite';
import { DataTable, type DataTableColumn } from '@/Components/composite/DataTable';
import { ForbiddenState, LoadingState, ResourceEmpty } from '@/Components/composite/ResourceState';
import { Badge, Button, Card, CardContent, EnumBadge } from '@/Components/ui';
import { Pagination } from '@/Components/ui/Pagination';
import { Tab, TabList, Tabs } from '@/Components/ui/Tabs';
import AdminLayout from '@/Layouts/AdminLayout';
import { useCompany } from '@/hooks/useCompany';
import { useServerTable, type ServerTableFilters } from '@/hooks/useServerTable';
import { useToast } from '@/hooks/useToast';
import { formatDate, formatDateTime } from '@/lib/format';
import { toPagination } from '@/lib/pagination';
import { labelOf, PRIORITY, WORK_ITEM_DOMAIN, WORK_ORDER_TYPE } from '@/lib/statuses';
import type {
    DispatchItem,
    DispatchSummary,
    MyWorkItem,
    MyWorkSummary,
    PaginationMeta,
    QueueDomainOption,
    QueueUserOption,
} from '@/types';
import {
    ActionConfirmDialog,
    type ActionType,
    type MyWorkReloadResult,
} from '../MyWork/components/ActionConfirmDialog';
import { FilterBar as MyWorkFilterBar } from '../MyWork/components/FilterBar';
import { MobileMyWorkCard } from '../MyWork/components/MobileMyWorkCard';
import { ResolveDialog } from '../MyWork/components/ResolveDialog';
import { SummaryChips, type SummaryChip } from '../MyWork/components/SummaryChips';
import { useMyWorkColumns } from '../MyWork/components/useMyWorkColumns';
import { formatAge, MY_WORK_STATUS_FILTER } from '../MyWork/components/utils';
import { AssignDialog, type DispatchReloadResult } from './components/AssignDialog';
import { FilterBar as DispatchFilterBar } from './components/FilterBar';
import { MobileDispatchCard } from './components/MobileDispatchCard';

interface DispatchIndexProps extends Record<string, unknown> {
    items: {
        data: (DispatchItem | MyWorkItem)[];
        meta: PaginationMeta;
    };
    summary: DispatchSummary;
    myWorkSummary?: MyWorkSummary | null;
    activeTab: 'dispatch' | 'my_work';
    filters: ServerTableFilters;
    domains: QueueDomainOption[];
    handlers: QueueUserOption[];
    technicians: QueueUserOption[];
    canDispatch: boolean;
    canMyWork: boolean;
}

interface ReloadDispatchQueueParams {
    selectedItem: DispatchItem | null;
    setSelectedItem: (item: DispatchItem | null) => void;
    setAssignOpen: (open: boolean) => void;
    onStart?: () => void;
    onFinish?: () => void;
}

export function reloadDispatchQueue({
    selectedItem,
    setSelectedItem,
    setAssignOpen,
    onStart,
    onFinish,
}: ReloadDispatchQueueParams): Promise<DispatchReloadResult> {
    return new Promise((resolve) => {
        let settled = false;
        const settle = (result: DispatchReloadResult) => {
            if (settled) return;
            settled = true;
            resolve(result);
        };

        try {
            router.reload({
                onStart: () => onStart?.(),
                only: [
                    'items',
                    'summary',
                    'myWorkSummary',
                    'filters',
                    'domains',
                    'handlers',
                    'technicians',
                    'activeTab',
                ],
                onSuccess: (page) => {
                    const freshItems =
                        (page.props.items as { data?: DispatchItem[] } | undefined)?.data ?? [];
                    const fresh = selectedItem
                        ? (freshItems.find(
                              (candidate) => candidate.composite_id === selectedItem.composite_id,
                          ) ?? null)
                        : null;

                    if (!fresh || !fresh.actions?.assign) {
                        setSelectedItem(null);
                        setAssignOpen(false);
                        settle({ success: true, freshItem: null });
                        return;
                    }

                    setSelectedItem(fresh);
                    settle({ success: true, freshItem: fresh });
                },
                onError: () => {
                    settle({
                        success: false,
                        errorMessage:
                            'Gagal memuat ulang antrean. Status antrean belum dapat diperbarui.',
                    });
                },
                onCancel: () => {
                    settle({
                        success: false,
                        errorMessage:
                            'Pemuatan ulang antrean dibatalkan. Status antrean belum dapat diperbarui.',
                    });
                },
                onFinish: () => {
                    onFinish?.();
                    settle({
                        success: false,
                        errorMessage:
                            'Gagal memuat ulang antrean. Terjadi gangguan koneksi atau respons tidak valid.',
                    });
                },
            });
        } catch {
            settle({
                success: false,
                errorMessage: 'Gagal memuat ulang antrean. Terjadi kesalahan jaringan.',
            });
        }
    });
}

export default function DispatchIndex({
    items,
    summary,
    myWorkSummary,
    activeTab = 'dispatch',
    filters,
    domains,
    handlers,
    technicians,
    canDispatch,
    canMyWork,
}: DispatchIndexProps) {
    useToast();
    const company = useCompany();

    const { params, set, setMany, sortBy, sortDir, onSort, setPage, processing, visiting } =
        useServerTable({
            url: route('admin.dispatch.index'),
            filters,
            only: [
                'items',
                'summary',
                'myWorkSummary',
                'filters',
                'domains',
                'handlers',
                'technicians',
                'activeTab',
            ],
        });

    // Dispatch assignment dialog state
    const [selectedDispatchItem, setSelectedDispatchItem] = useState<DispatchItem | null>(null);
    const [assignOpen, setAssignOpen] = useState(false);

    // My Work actions state
    const [resolveItem, setResolveItem] = useState<MyWorkItem | null>(null);
    const [resolveOpen, setResolveOpen] = useState(false);
    const [confirmItem, setConfirmItem] = useState<MyWorkItem | null>(null);
    const [confirmAction, setConfirmAction] = useState<ActionType | null>(null);
    const [confirmOpen, setConfirmOpen] = useState(false);

    const myWorkColumns = useMyWorkColumns({
        onConfirm: (item, action) => handleOpenConfirm(item, action),
        onResolve: (item) => handleOpenResolve(item),
    });

    if (!canDispatch && !canMyWork) {
        return (
            <AdminLayout title="Penugasan">
                <div className="space-y-6">
                    <PageHeader title="Penugasan" subtitle="Antrean penugasan tiket dan SPK." />
                    <ForbiddenState
                        title="Akses ditolak"
                        description="Anda tidak punya izin penugasan atau izin operasional untuk jenis pekerjaan apa pun."
                        backHref="/admin/dashboard"
                        backLabel="Kembali ke Dasbor"
                    />
                </div>
            </AdminLayout>
        );
    }

    const handleTabChange = (nextTab: string) => {
        router.get(
            route('admin.dispatch.index'),
            { tab: nextTab },
            {
                preserveState: false,
                preserveScroll: true,
            },
        );
    };

    const submitSearch = (event: FormEvent) => {
        event.preventDefault();
        set('search', params.search ?? '');
    };

    function handleOpenAssign(item: DispatchItem) {
        setSelectedDispatchItem(item);
        setAssignOpen(true);
    }

    const handleReloadQueue = (): Promise<DispatchReloadResult> =>
        reloadDispatchQueue({
            selectedItem: selectedDispatchItem,
            setSelectedItem: setSelectedDispatchItem,
            setAssignOpen,
        });

    function handleOpenResolve(item: MyWorkItem) {
        setResolveItem(item);
        setResolveOpen(true);
    }

    function handleOpenConfirm(item: MyWorkItem, action: ActionType) {
        setConfirmItem(item);
        setConfirmAction(action);
        setConfirmOpen(true);
    }

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
                    only: [
                        'items',
                        'summary',
                        'myWorkSummary',
                        'filters',
                        'domains',
                        'handlers',
                        'technicians',
                        'activeTab',
                    ],
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

    const dispatchColumns: DataTableColumn<DispatchItem>[] = [
        {
            key: 'code',
            header: 'Kode',
            sortable: true,
            sortKey: 'code',
            cell: (row) => (
                <div className="space-y-1">
                    {row.actions.view && row.detail_url ? (
                        <Link
                            href={row.detail_url}
                            className="font-semibold text-primary hover:underline focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-hidden"
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
            header: 'Judul dan kebutuhan',
            sortable: true,
            sortKey: 'title',
            cell: (row) => (
                <div className="space-y-1 max-w-xs">
                    <span className="line-clamp-2 text-sm font-medium text-foreground">
                        {row.title}
                    </span>
                    <div className="text-xs text-muted-foreground">
                        {row.domain === 'ticket' ? (
                            <span>Kategori: {row.summary?.category ?? 'Umum'}</span>
                        ) : (
                            <span>
                                Jenis: {labelOf(WORK_ORDER_TYPE, row.type ?? row.summary?.type)}
                            </span>
                        )}
                    </div>
                    {row.sla_breached && (
                        <div className="flex items-center gap-1 text-xs font-semibold text-destructive-text">
                            <AlertTriangle className="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                            <span>SLA terlampaui</span>
                        </div>
                    )}
                </div>
            ),
        },
        {
            key: 'requester',
            header: 'Pemohon atau pelanggan',
            cell: (row) => (
                <div className="space-y-0.5 text-xs">
                    <p className="font-medium text-foreground">{row.requester?.name ?? '-'}</p>
                    {row.requester?.code && (
                        <p className="text-muted-foreground">{row.requester.code}</p>
                    )}
                </div>
            ),
        },
        {
            key: 'priority',
            header: 'Prioritas dan umur',
            sortable: true,
            sortKey: 'priority',
            cell: (row) => (
                <div className="space-y-1">
                    <EnumBadge map={PRIORITY} value={row.priority} />
                    <p className="text-xs text-muted-foreground">{formatAge(row.age_seconds)}</p>
                </div>
            ),
        },
        {
            key: 'due_at',
            header: 'Batas atau jadwal',
            cell: (row) => (
                <div className="space-y-0.5 text-xs">
                    {row.domain === 'ticket' ? (
                        <>
                            <span className="text-muted-foreground">SLA: </span>
                            <span className="font-medium text-foreground">
                                {formatDateTime(row.due_at ?? row.summary?.sla_deadline, company)}
                            </span>
                        </>
                    ) : (
                        <>
                            <span className="text-muted-foreground">Jadwal: </span>
                            <span className="font-medium text-foreground">
                                {formatDate(
                                    row.scheduled_date ?? row.summary?.scheduled_date,
                                    company,
                                )}
                            </span>
                        </>
                    )}
                </div>
            ),
        },
        {
            key: 'assignee',
            header: 'Petugas saat ini',
            cell: (row) =>
                row.assignee ? (
                    <span className="text-xs font-medium text-foreground">{row.assignee.name}</span>
                ) : (
                    <span className="text-xs font-medium text-amber-700 dark:text-amber-400">
                        Belum ditugaskan
                    </span>
                ),
        },
        {
            key: 'actions',
            header: 'Tindakan',
            className: 'text-right',
            cell: (row) =>
                row.actions.assign && (
                    <Button
                        type="button"
                        size="sm"
                        variant="primary"
                        leftIcon={<User className="h-3.5 w-3.5" aria-hidden="true" />}
                        onClick={() => handleOpenAssign(row)}
                    >
                        {row.assigned_to ? 'Ganti petugas' : 'Tugaskan'}
                    </Button>
                ),
        },
    ];

    const hasItems = items.data && items.data.length > 0;
    const hasFilters = Boolean(
        params.search ||
        params.domain ||
        (params.status && params.status !== 'unassigned') ||
        params.priority ||
        params.date_from,
    );
    const resetFilters = () =>
        setMany({
            search: '',
            domain: '',
            status: activeTab === 'dispatch' ? 'unassigned' : '',
            priority: '',
            date_from: '',
            date_to: '',
            page: 1,
        });

    const status = String(params.status ?? 'unassigned');
    const domain = String(params.domain ?? '');
    const hasTicket = domains.some((option) => option.value === 'ticket');
    const hasSpk = domains.some((option) => option.value === 'spk');
    const dispatchChips: SummaryChip[] = [
        {
            key: 'unassigned',
            label: 'Belum ditugaskan',
            count: summary.unassigned,
            active: status === 'unassigned' && domain === '',
            onSelect: () => setMany({ status: 'unassigned', domain: '', page: 1 }),
        },
        ...(hasTicket
            ? [
                  {
                      key: 'ticket',
                      label: 'Tiket belum ditugaskan',
                      count: summary.ticket_unassigned,
                      active: status === 'unassigned' && domain === 'ticket',
                      onSelect: () => setMany({ status: 'unassigned', domain: 'ticket', page: 1 }),
                  },
              ]
            : []),
        ...(hasSpk
            ? [
                  {
                      key: 'spk',
                      label: 'SPK belum ditugaskan',
                      count: summary.spk_unassigned,
                      active: status === 'unassigned' && domain === 'spk',
                      onSelect: () => setMany({ status: 'unassigned', domain: 'spk', page: 1 }),
                  },
              ]
            : []),
        {
            key: 'assigned',
            label: 'Sudah ditugaskan',
            count: summary.assigned,
            active: status === 'assigned' && domain === '',
            onSelect: () => setMany({ status: 'assigned', domain: '', page: 1 }),
        },
    ];

    const myWorkStatus = String(params.status ?? '');
    const myWorkCounts = myWorkSummary
        ? {
              assigned: myWorkSummary.assigned,
              in_progress: myWorkSummary.in_progress,
              resolved: myWorkSummary.waiting_action,
          }
        : null;
    const myWorkChips: SummaryChip[] =
        myWorkSummary && myWorkCounts
            ? [
                  {
                      key: 'all',
                      label: 'Semua tugas',
                      count: myWorkSummary.total,
                      active: myWorkStatus === '',
                      onSelect: () => setMany({ status: '', page: 1 }),
                  },
                  ...MY_WORK_STATUS_FILTER.map(({ value, label }) => ({
                      key: value,
                      label,
                      count: myWorkCounts[value],
                      active: myWorkStatus === value,
                      onSelect: () => setMany({ status: value, page: 1 }),
                  })),
              ]
            : [];

    return (
        <AdminLayout title="Penugasan">
            <div className="space-y-6">
                <PageHeader
                    title="Penugasan"
                    subtitle="Tiket dan SPK yang menunggu petugas, dan siapa yang sedang mengerjakannya."
                />

                {canMyWork && (
                    <Tabs value={activeTab} onValueChange={handleTabChange}>
                        <TabList
                            className="border-b border-border"
                            aria-label="Antrean operasional"
                        >
                            <Tab value="dispatch" className="gap-2">
                                <span>Antrean Penugasan</span>
                                {summary.unassigned > 0 && (
                                    <Badge variant="secondary" className="ml-1 px-1.5 py-0 text-xs">
                                        {summary.unassigned}
                                    </Badge>
                                )}
                            </Tab>
                            <Tab value="my_work" className="gap-2">
                                <span>Pekerjaan Saya</span>
                                {myWorkSummary && myWorkSummary.total > 0 && (
                                    <Badge variant="secondary" className="ml-1 px-1.5 py-0 text-xs">
                                        {myWorkSummary.total}
                                    </Badge>
                                )}
                            </Tab>
                        </TabList>
                    </Tabs>
                )}

                {activeTab === 'dispatch' && (
                    <>
                        <SummaryChips label="Ringkasan antrean penugasan" chips={dispatchChips} />

                        <Card>
                            <CardContent className="space-y-4 pt-6">
                                <DispatchFilterBar
                                    params={params}
                                    set={set}
                                    setMany={setMany}
                                    domains={domains}
                                    onSubmitSearch={submitSearch}
                                    disabled={visiting}
                                />

                                <div className="hidden md:block">
                                    <DataTable
                                        columns={dispatchColumns}
                                        data={items.data as DispatchItem[]}
                                        pagination={toPagination(
                                            items as unknown as {
                                                data: DispatchItem[];
                                                meta: PaginationMeta;
                                            },
                                        )}
                                        sortBy={sortBy}
                                        sortDir={sortDir}
                                        onSort={onSort}
                                        onPageChange={setPage}
                                        onPerPageChange={(perPage) => set('per_page', perPage)}
                                        loading={processing}
                                        hasFilters={hasFilters}
                                        onResetFilters={resetFilters}
                                        itemLabel="antrean"
                                        emptyTitle="Tidak ada antrean penugasan"
                                        emptyDescription="Semua tiket dan SPK sudah punya petugas."
                                    />
                                </div>

                                <div className="space-y-4 md:hidden">
                                    {processing && !hasItems && (
                                        <LoadingState variant="rows" rows={4} />
                                    )}

                                    {!processing && !hasItems && (
                                        <ResourceEmpty
                                            title="Tidak ada antrean penugasan"
                                            description="Semua tiket dan SPK sudah punya petugas."
                                            className="my-4"
                                        />
                                    )}

                                    {hasItems && (
                                        <div className="space-y-3">
                                            {(items.data as DispatchItem[]).map((item) => (
                                                <MobileDispatchCard
                                                    key={item.composite_id}
                                                    item={item}
                                                    onAssign={handleOpenAssign}
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

                        <AssignDialog
                            open={assignOpen}
                            onOpenChange={setAssignOpen}
                            item={selectedDispatchItem}
                            handlers={handlers}
                            technicians={technicians}
                            onReloadQueue={handleReloadQueue}
                            onSuccess={() => {
                                router.reload({
                                    only: ['items', 'summary', 'handlers', 'technicians'],
                                });
                            }}
                        />
                    </>
                )}

                {activeTab === 'my_work' && myWorkSummary && (
                    <>
                        <SummaryChips label="Ringkasan pekerjaan saya" chips={myWorkChips} />

                        <Card>
                            <CardContent className="space-y-4 pt-6">
                                <MyWorkFilterBar
                                    params={params}
                                    set={set}
                                    setMany={setMany}
                                    domains={domains}
                                    onSubmitSearch={submitSearch}
                                    disabled={visiting}
                                />

                                <div className="hidden md:block">
                                    <DataTable
                                        columns={myWorkColumns}
                                        data={items.data as MyWorkItem[]}
                                        pagination={toPagination(
                                            items as unknown as {
                                                data: MyWorkItem[];
                                                meta: PaginationMeta;
                                            },
                                        )}
                                        sortBy={sortBy}
                                        sortDir={sortDir}
                                        onSort={onSort}
                                        onPageChange={setPage}
                                        onPerPageChange={(perPage) => set('per_page', perPage)}
                                        loading={processing}
                                        hasFilters={hasFilters}
                                        onResetFilters={resetFilters}
                                        itemLabel="tugas"
                                        emptyTitle="Belum ada tugas untuk Anda"
                                        emptyDescription="Tiket atau SPK yang ditugaskan kepada Anda akan muncul di sini."
                                    />
                                </div>

                                <div className="space-y-4 md:hidden">
                                    {processing && !hasItems && (
                                        <LoadingState variant="rows" rows={4} />
                                    )}

                                    {!processing && !hasItems && (
                                        <ResourceEmpty
                                            title="Belum ada tugas untuk Anda"
                                            description="Tiket atau SPK yang ditugaskan kepada Anda akan muncul di sini."
                                            className="my-4"
                                        />
                                    )}

                                    {hasItems && (
                                        <div className="space-y-3">
                                            {(items.data as MyWorkItem[]).map((item) => (
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
                                router.reload({ only: ['items', 'myWorkSummary', 'summary'] });
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
                                    (fresh) =>
                                        Boolean(confirmAction && fresh.actions[confirmAction]),
                                )
                            }
                            onSuccess={() => {
                                router.reload({ only: ['items', 'myWorkSummary', 'summary'] });
                            }}
                        />
                    </>
                )}
            </div>
        </AdminLayout>
    );
}
