import { useState, type FormEvent } from 'react';
import { router } from '@inertiajs/react';
import {
    ArrowTopRightOnSquareIcon,
    CheckCircleIcon,
    ExclamationTriangleIcon,
    PaperAirplaneIcon,
    PlayIcon,
    UserIcon,
    XCircleIcon,
} from '@heroicons/react/24/outline';
import { PageHeader } from '@/Components/composite';
import { DataTable, type DataTableColumn } from '@/Components/composite/DataTable';
import { ForbiddenState, LoadingState, ResourceEmpty } from '@/Components/composite/ResourceState';
import { Badge, Button, Card, CardContent } from '@/Components/ui';
import { Pagination } from '@/Components/ui/Pagination';
import { Tab, TabList, Tabs } from '@/Components/ui/Tabs';
import AdminLayout from '@/Layouts/AdminLayout';
import { useServerTable, type ServerTableFilters } from '@/hooks/useServerTable';
import { useToast } from '@/hooks/useToast';
import { toPagination } from '@/lib/pagination';
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
import { SummaryCards as MyWorkSummaryCards } from '../MyWork/components/SummaryCards';
import {
    formatAge as formatMyWorkAge,
    formatDate as formatMyWorkDate,
    getDomainLabel as getMyWorkDomainLabel,
    getDomainVariant as getMyWorkDomainVariant,
    getItemNextStatus,
    getPriorityLabel as getMyWorkPriorityLabel,
    getPriorityVariant as getMyWorkPriorityVariant,
    getStatusLabel as getMyWorkStatusLabel,
    getStatusVariant as getMyWorkStatusVariant,
} from '../MyWork/components/utils';
import { AssignDialog, type DispatchReloadResult } from './components/AssignDialog';
import { FilterBar as DispatchFilterBar } from './components/FilterBar';
import { MobileDispatchCard } from './components/MobileDispatchCard';
import { SummaryCards as DispatchSummaryCards } from './components/SummaryCards';
import {
    formatAge,
    formatDate,
    getDomainLabel,
    getDomainVariant,
    getPriorityLabel,
    getPriorityVariant,
} from './components/utils';

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

    if (!canDispatch && !canMyWork) {
        return (
            <AdminLayout title="Penugasan">
                <div className="space-y-6">
                    <PageHeader
                        title="Penugasan"
                        subtitle="Antrean penugasan tiket dan surat perintah kerja (SPK)"
                    />
                    <ForbiddenState
                        title="Akses Ditolak"
                        description="Anda tidak memiliki izin penugasan (dispatch) atau izin operasional pada domain apapun."
                        backHref="/admin/dashboard"
                        backLabel="Kembali ke Dashboard"
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

    const handleOpenAssign = (item: DispatchItem) => {
        setSelectedDispatchItem(item);
        setAssignOpen(true);
    };

    const handleReloadQueue = (): Promise<DispatchReloadResult> =>
        reloadDispatchQueue({
            selectedItem: selectedDispatchItem,
            setSelectedItem: setSelectedDispatchItem,
            setAssignOpen,
        });

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

    const handleStatusCardClick = (statusValue: string) => {
        setMany({
            status: statusValue,
            page: 1,
        });
    };

    // Columns for Dispatch view
    const dispatchColumns: DataTableColumn<DispatchItem>[] = [
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
            header: 'Judul / Kebutuhan',
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
                            <span>Tipe: {row.type ?? row.summary?.type ?? 'SPK'}</span>
                        )}
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
            key: 'requester',
            header: 'Pemohon / Pelanggan',
            cell: (row) => (
                <div className="space-y-0.5 text-xs">
                    <p className="font-medium text-foreground">{row.requester?.name ?? '—'}</p>
                    {row.requester?.code && (
                        <p className="text-muted-foreground">{row.requester.code}</p>
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
            key: 'assignee',
            header: 'Petugas Saat Ini',
            cell: (row) =>
                row.assignee ? (
                    <span className="text-xs font-medium text-foreground">{row.assignee.name}</span>
                ) : (
                    <Badge
                        variant="outline"
                        className="border-amber-300 text-[10px] text-amber-600 dark:text-amber-400"
                    >
                        Belum Ditugaskan
                    </Badge>
                ),
        },
        {
            key: 'actions',
            header: 'Tindakan',
            className: 'text-right',
            cell: (row) => (
                <div className="flex flex-wrap items-center justify-end gap-2 [&>button]:min-h-11">
                    {row.actions.view && row.detail_url && (
                        <a
                            href={row.detail_url}
                            className="inline-flex items-center text-xs font-medium text-primary hover:underline"
                        >
                            <ArrowTopRightOnSquareIcon className="mr-1 h-3.5 w-3.5" />
                            Detail
                        </a>
                    )}
                    {row.actions.assign && (
                        <Button
                            type="button"
                            size="sm"
                            variant="default"
                            onClick={() => handleOpenAssign(row)}
                        >
                            <UserIcon className="mr-1.5 h-3.5 w-3.5" />
                            {row.assigned_to ? 'Ganti Petugas' : 'Tugaskan'}
                        </Button>
                    )}
                </div>
            ),
        },
    ];

    // Columns for My Work view (when activeTab === 'my_work')
    const myWorkColumns: DataTableColumn<MyWorkItem>[] = [
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
                            variant={getMyWorkDomainVariant(row.domain)}
                            className="text-[10px] font-semibold uppercase"
                        >
                            {getMyWorkDomainLabel(row.domain)}
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
                        variant={getMyWorkPriorityVariant(row.priority)}
                        className="text-[10px] font-semibold uppercase"
                    >
                        {getMyWorkPriorityLabel(row.priority)}
                    </Badge>
                    <p className="text-xs text-muted-foreground">
                        {formatMyWorkAge(row.age_seconds)}
                    </p>
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
                    <Badge variant={getMyWorkStatusVariant(row.status)} className="text-[10px]">
                        {getMyWorkStatusLabel(row.status)}
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
                                {formatMyWorkDate(row.due_at ?? row.summary?.sla_deadline)}
                            </span>
                        </>
                    ) : (
                        <>
                            <span className="text-muted-foreground">Jadwal: </span>
                            <span className="font-medium text-foreground">
                                {formatMyWorkDate(
                                    row.scheduled_date ?? row.summary?.scheduled_date,
                                )}
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
        <AdminLayout title="Penugasan">
            <div className="space-y-6">
                <PageHeader
                    title="Penugasan"
                    subtitle="Pusat alokasi dan pemantauan penugasan operasional tiket dan SPK lapangan"
                />

                {/* Top Job-based Operational Tabs */}
                {canMyWork && (
                    <Tabs value={activeTab} onValueChange={handleTabChange}>
                        <TabList
                            className="border-b border-border"
                            aria-label="Pilihan Antrean Operasional"
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

                {/* View 1: Dispatch Queue */}
                {activeTab === 'dispatch' && (
                    <>
                        <DispatchSummaryCards
                            summary={summary}
                            activeStatus={String(params.status ?? 'unassigned')}
                            onSelectStatus={handleStatusCardClick}
                        />

                        <Card className="border-border bg-card shadow-xs">
                            <CardContent className="space-y-4 pt-6">
                                <DispatchFilterBar
                                    params={params}
                                    set={set}
                                    setMany={setMany}
                                    domains={domains}
                                    onSubmitSearch={submitSearch}
                                    disabled={visiting}
                                />

                                {/* Desktop Table */}
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
                                        emptyTitle="Tidak ada antrean penugasan"
                                        emptyDescription="Seluruh tiket dan SPK saat ini sudah ditugaskan kepada petugas pelaksana."
                                    />
                                </div>

                                {/* Mobile Stacked Cards */}
                                <div className="space-y-4 md:hidden">
                                    {processing && !hasItems && (
                                        <LoadingState variant="rows" rows={4} />
                                    )}

                                    {!processing && !hasItems && (
                                        <ResourceEmpty
                                            title="Tidak ada antrean penugasan"
                                            description="Seluruh tiket dan SPK saat ini sudah ditugaskan kepada petugas pelaksana."
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

                        {/* Assign Dialog */}
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

                {/* View 2: My Work (when tab=my_work) */}
                {activeTab === 'my_work' && myWorkSummary && (
                    <>
                        <MyWorkSummaryCards
                            summary={myWorkSummary}
                            activeStatus={String(params.status ?? '')}
                            onSelectStatus={handleStatusCardClick}
                        />

                        <Card className="border-border bg-card shadow-xs">
                            <CardContent className="space-y-4 pt-6">
                                <MyWorkFilterBar
                                    params={params}
                                    set={set}
                                    setMany={setMany}
                                    domains={domains}
                                    onSubmitSearch={submitSearch}
                                    disabled={visiting}
                                />

                                {/* Desktop Table */}
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
                                        emptyTitle="Tidak ada tugas aktif"
                                        emptyDescription="Anda tidak memiliki tiket atau SPK aktif yang sedang ditugaskan kepada Anda saat ini."
                                    />
                                </div>

                                {/* Mobile Stacked Cards */}
                                <div className="space-y-4 md:hidden">
                                    {processing && !hasItems && (
                                        <LoadingState variant="rows" rows={4} />
                                    )}

                                    {!processing && !hasItems && (
                                        <ResourceEmpty
                                            title="Tidak ada tugas aktif"
                                            description="Anda tidak memiliki tiket atau SPK aktif yang sedang ditugaskan kepada Anda saat ini."
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
