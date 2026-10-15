import { useState, type FormEvent } from 'react';
import { router } from '@inertiajs/react';
import { PageHeader } from '@/Components/composite';
import { DataTable } from '@/Components/composite/DataTable';
import { LoadingState, ResourceEmpty } from '@/Components/composite/ResourceState';
import { Badge, Card, CardContent } from '@/Components/ui';
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
import { SummaryChips, type SummaryChip } from './components/SummaryChips';
import { useMyWorkColumns } from './components/useMyWorkColumns';
import { MY_WORK_STATUS_FILTER } from './components/utils';

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

    const columns = useMyWorkColumns({
        onConfirm: handleOpenConfirm,
        onResolve: handleOpenResolve,
    });

    const counts = {
        assigned: summary.assigned,
        in_progress: summary.in_progress,
        resolved: summary.waiting_action,
    };
    const status = String(params.status ?? '');
    const chips: SummaryChip[] = [
        {
            key: 'all',
            label: 'Semua tugas',
            count: summary.total,
            active: status === '',
            onSelect: () => setMany({ status: '', page: 1 }),
        },
        ...MY_WORK_STATUS_FILTER.map(({ value, label }) => ({
            key: value,
            label,
            count: counts[value],
            active: status === value,
            onSelect: () => setMany({ status: value, page: 1 }),
        })),
    ];

    const hasItems = items.data && items.data.length > 0;
    const hasFilters = Boolean(
        params.search || params.domain || params.status || params.priority || params.date_from,
    );
    const resetFilters = () =>
        setMany({
            search: '',
            domain: '',
            status: '',
            priority: '',
            date_from: '',
            date_to: '',
            page: 1,
        });

    return (
        <AdminLayout title="Pekerjaan Saya">
            <div className="space-y-6">
                <PageHeader
                    title="Pekerjaan Saya"
                    subtitle="Tiket dan SPK yang ditugaskan kepada Anda, dari belum dimulai sampai selesai."
                />

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
                            aria-label="Antrean operasional"
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

                <SummaryChips label="Ringkasan pekerjaan saya" chips={chips} />

                <Card>
                    <CardContent className="space-y-4 pt-6">
                        <FilterBar
                            params={params}
                            set={set}
                            setMany={setMany}
                            domains={domains}
                            onSubmitSearch={submitSearch}
                            disabled={visiting}
                        />

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
                                hasFilters={hasFilters}
                                onResetFilters={resetFilters}
                                itemLabel="tugas"
                                emptyTitle="Belum ada tugas untuk Anda"
                                emptyDescription="Tiket atau SPK yang ditugaskan kepada Anda akan muncul di sini."
                            />
                        </div>

                        <div className="space-y-4 md:hidden">
                            {processing && !hasItems && <LoadingState variant="rows" rows={4} />}

                            {!processing && !hasItems && (
                                <ResourceEmpty
                                    title="Belum ada tugas untuk Anda"
                                    description="Tiket atau SPK yang ditugaskan kepada Anda akan muncul di sini."
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
