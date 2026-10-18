import { useMemo, useState } from 'react';
import { ImageOff } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import { DataTable, NativeSelect, PageHeader } from '@/Components/composite';
import type { DataTableColumn } from '@/Components/composite';
import { Button, EmptyCell, EnumBadge, Input, Tab, TabList, TabPanel, Tabs } from '@/Components/ui';
import { useCompany } from '@/hooks/useCompany';
import { useServerTable } from '@/hooks/useServerTable';
import { phpToDateFns } from '@/lib/dateFormat';
import { formatDayTime } from '@/lib/dateValue';
import { formatNumber } from '@/lib/format';
import { toPagination } from '@/lib/pagination';
import {
    ANNOUNCEMENT_AUDIENCE,
    ANNOUNCEMENT_KIND,
    ANNOUNCEMENT_STATUS,
    BANNER_APP_ROUTE,
    labelOf,
    optionsOf,
} from '@/lib/statuses';
import { ActionDialog } from './components/ActionDialog';
import type { ActionRequest } from './components/ActionDialog';
import { AnnouncementDialog } from './components/AnnouncementDialog';
import { BannerDialog } from './components/BannerDialog';
import type { AnnouncementRow, BannerRow, IndexProps, Target } from './types';

/** `row` is the one being edited; no row is a new one. */
type Editor =
    { kind: 'announcement'; row?: AnnouncementRow } | { kind: 'banner'; row?: BannerRow } | null;

type Confirmations = Record<'publish' | 'deactivate' | 'remove', ActionRequest>;

const STATUS_OPTIONS = [{ value: '', label: 'Semua status' }, ...optionsOf(ANNOUNCEMENT_STATUS)];

/** The three confirmations of a row: publish it, deactivate it, delete the draft. `publishNote` says what publishing does. */
function confirmations(
    resource: 'announcements' | 'promo-banners',
    noun: string,
    row: { id: string; title: string },
    publishNote: string,
): Confirmations {
    return {
        publish: {
            title: `Terbitkan ${noun} ${row.title}?`,
            description: `${publishNote} Setelah terbit, ${noun} tidak bisa dihapus, hanya dinonaktifkan.`,
            confirmLabel: 'Terbitkan',
            href: route(`admin.${resource}.publish`, row.id),
            method: 'post',
        },
        deactivate: {
            title: `Nonaktifkan ${noun} ${row.title}?`,
            description: `Tidak tampil lagi di aplikasi pelanggan. Riwayatnya tetap tersimpan, tetapi ${noun} ini tidak bisa diaktifkan lagi. Buat ${noun} baru bila perlu.`,
            confirmLabel: 'Nonaktifkan',
            href: route(`admin.${resource}.deactivate`, row.id),
            method: 'post',
            destructive: true,
        },
        remove: {
            title: `Hapus draf ${noun} ${row.title}?`,
            description: 'Draf dihapus permanen.',
            confirmLabel: 'Hapus draf',
            href: route(`admin.${resource}.destroy`, row.id),
            method: 'delete',
            destructive: true,
        },
    };
}

export default function Index({
    tab,
    announcements,
    banners,
    counts,
    filters,
    kinds,
    audiences,
    appRoutes,
    packageCategories,
    locationOptions,
    preview,
    can,
}: IndexProps) {
    const company = useCompany();
    const dateFormat = phpToDateFns(company?.settings?.date_format);
    const [editor, setEditor] = useState<Editor>(null);
    const [action, setAction] = useState<ActionRequest | null>(null);
    const { params, search, setSearch, set, setMany, setPage, processing } = useServerTable({
        url: route('admin.announcements.index'),
        filters,
        only: ['tab', 'announcements', 'banners', 'counts', 'filters'],
    });
    const hasFilters = Boolean(params.search || params.status);
    const resetFilters = () => setMany({ search: '', status: '' });
    const choices = { audiences, categories: packageCategories, locationOptions };

    const announcementColumns: DataTableColumn<AnnouncementRow>[] = useMemo(() => {
        const base: DataTableColumn<AnnouncementRow>[] = [
            {
                key: 'title',
                header: 'Pengumuman',
                cell: (row) => (
                    <div className="space-y-0.5 max-w-md">
                        <p className="font-medium wrap-break-word">{row.title}</p>
                        <p className="line-clamp-2 text-xs wrap-break-word text-muted-foreground">
                            {row.body}
                        </p>
                    </div>
                ),
            },
            {
                key: 'kind',
                header: 'Jenis',
                cell: (row) => <EnumBadge map={ANNOUNCEMENT_KIND} value={row.kind} />,
            },
            {
                key: 'status',
                header: 'Status',
                cell: (row) => <EnumBadge map={ANNOUNCEMENT_STATUS} value={row.status} />,
            },
            {
                key: 'period',
                header: 'Periode',
                cell: (row) => (
                    <PeriodCell
                        startsAt={row.starts_at}
                        endsAt={row.ends_at}
                        dateFormat={dateFormat}
                    />
                ),
            },
            { key: 'audience', header: 'Sasaran', cell: (row) => <TargetSummary row={row} /> },
        ];

        if (!can.manage) return base;

        return [
            ...base,
            {
                key: 'actions',
                header: 'Aksi',
                cell: (row) => {
                    const ask = confirmations(
                        'announcements',
                        'pengumuman',
                        row,
                        announcementPublishNote(row.kind),
                    );

                    return (
                        <RowActions
                            noun="pengumuman"
                            title={row.title}
                            status={row.status}
                            onEdit={() => setEditor({ kind: 'announcement', row })}
                            onPublish={() => setAction(ask.publish)}
                            onDeactivate={() => setAction(ask.deactivate)}
                            onDelete={() => setAction(ask.remove)}
                        />
                    );
                },
            },
        ];
    }, [can.manage, dateFormat]);

    const bannerColumns: DataTableColumn<BannerRow>[] = useMemo(() => {
        const base: DataTableColumn<BannerRow>[] = [
            {
                key: 'title',
                header: 'Banner',
                cell: (row) => (
                    <div className="flex items-center gap-3">
                        {row.image_url ? (
                            <img
                                src={row.image_url}
                                alt={row.alt_text}
                                loading="lazy"
                                className="aspect-2/1 w-24 shrink-0 rounded-md border border-border object-cover"
                            />
                        ) : (
                            <div className="flex aspect-2/1 w-24 shrink-0 items-center justify-center rounded-md border border-border text-muted-foreground">
                                <ImageOff className="size-5" aria-hidden="true" />
                                <span className="sr-only">Tidak ada gambar</span>
                            </div>
                        )}
                        <div className="space-y-0.5 min-w-0">
                            <p className="font-medium wrap-break-word">{row.title}</p>
                            <p className="text-xs wrap-break-word text-muted-foreground">
                                {linkSummary(row)}
                            </p>
                        </div>
                    </div>
                ),
            },
            {
                key: 'status',
                header: 'Status',
                cell: (row) => <EnumBadge map={ANNOUNCEMENT_STATUS} value={row.status} />,
            },
            {
                key: 'period',
                header: 'Periode',
                cell: (row) => (
                    <PeriodCell
                        startsAt={row.starts_at}
                        endsAt={row.ends_at}
                        dateFormat={dateFormat}
                    />
                ),
            },
            { key: 'audience', header: 'Sasaran', cell: (row) => <TargetSummary row={row} /> },
            {
                key: 'sort_order',
                header: 'Urutan',
                className: 'text-right tabular-nums',
                headerClassName: 'text-right',
                cell: (row) => row.sort_order,
            },
            {
                key: 'tap_count',
                header: 'Ketukan',
                className: 'text-right tabular-nums',
                headerClassName: 'text-right',
                cell: (row) => formatNumber(row.tap_count),
            },
        ];

        if (!can.manage) return base;

        return [
            ...base,
            {
                key: 'actions',
                header: 'Aksi',
                cell: (row) => {
                    const ask = confirmations(
                        'promo-banners',
                        'banner',
                        row,
                        'Banner tampil di beranda aplikasi pelanggan sasaran sesuai jadwal.',
                    );

                    return (
                        <RowActions
                            noun="banner"
                            title={row.title}
                            status={row.status}
                            onEdit={() => setEditor({ kind: 'banner', row })}
                            onPublish={() => setAction(ask.publish)}
                            onDeactivate={() => setAction(ask.deactivate)}
                            onDelete={() => setAction(ask.remove)}
                        />
                    );
                },
            },
        ];
    }, [can.manage, dateFormat]);

    const newButton = can.manage ? (
        <Button
            type="button"
            variant="primary"
            size="sm"
            onClick={() => setEditor({ kind: tab === 'banners' ? 'banner' : 'announcement' })}
        >
            {tab === 'banners' ? 'Buat Banner Promo' : 'Buat Pengumuman'}
        </Button>
    ) : undefined;

    const toolbar = (
        <div className="flex flex-wrap items-end gap-3">
            <div className="min-w-56 flex-1">
                <Input
                    id="announcement-search"
                    label="Cari"
                    value={search}
                    onChange={(event) => setSearch(event.target.value)}
                    placeholder="Judul"
                />
            </div>
            <NativeSelect
                id="announcement-status"
                label="Status"
                value={params.status ?? ''}
                onChange={(event) => set('status', event.target.value)}
                options={STATUS_OPTIONS}
                wrapperClassName="sm:w-44"
            />
            {hasFilters ? (
                <Button type="button" variant="outline" onClick={resetFilters}>
                    Reset filter
                </Button>
            ) : null}
        </div>
    );

    return (
        <AdminLayout title="Pengumuman & Promo">
            <div className="space-y-4">
                <PageHeader
                    title="Pengumuman & Promo"
                    subtitle="Pengumuman gangguan, pemeliharaan, dan informasi, serta banner promo yang tampil di beranda aplikasi pelanggan."
                    actions={newButton}
                />

                <Tabs
                    value={tab}
                    onValueChange={(next) => {
                        if (next !== tab) setMany({ tab: next, search: '', status: '' });
                    }}
                >
                    <TabList aria-label="Jenis isi">
                        <Tab
                            value="announcements"
                            className="min-h-11 sm:min-h-0 sm:pointer-coarse:min-h-11"
                        >
                            Pengumuman ({counts.announcements})
                        </Tab>
                        <Tab
                            value="banners"
                            className="min-h-11 sm:min-h-0 sm:pointer-coarse:min-h-11"
                        >
                            Banner promo ({counts.banners})
                        </Tab>
                    </TabList>

                    <TabPanel value="announcements" className="space-y-4">
                        {announcements ? (
                            <>
                                {toolbar}
                                <DataTable
                                    columns={announcementColumns}
                                    pagination={toPagination(announcements)}
                                    onPageChange={setPage}
                                    loading={processing}
                                    itemLabel="pengumuman"
                                    hasFilters={hasFilters}
                                    onResetFilters={resetFilters}
                                    emptyTitle="Belum ada pengumuman"
                                    emptyDescription="Tulis pengumuman gangguan, pemeliharaan, atau informasi untuk pelanggan."
                                    emptyAction={newButton}
                                />
                            </>
                        ) : null}
                    </TabPanel>
                    <TabPanel value="banners" className="space-y-4">
                        {banners ? (
                            <>
                                {toolbar}
                                <DataTable
                                    columns={bannerColumns}
                                    pagination={toPagination(banners)}
                                    onPageChange={setPage}
                                    loading={processing}
                                    itemLabel="banner"
                                    hasFilters={hasFilters}
                                    onResetFilters={resetFilters}
                                    emptyTitle="Belum ada banner promo"
                                    emptyDescription="Tambahkan banner agar promosi tampil di beranda aplikasi pelanggan."
                                    emptyAction={newButton}
                                />
                            </>
                        ) : null}
                    </TabPanel>
                </Tabs>

                {editor?.kind === 'announcement' ? (
                    <AnnouncementDialog
                        key={editor.row?.id ?? 'new'}
                        announcement={editor.row}
                        kinds={kinds}
                        choices={choices}
                        onClose={() => setEditor(null)}
                    />
                ) : null}
                {editor?.kind === 'banner' ? (
                    <BannerDialog
                        key={editor.row?.id ?? 'new'}
                        banner={editor.row}
                        appRoutes={appRoutes}
                        choices={choices}
                        preview={preview}
                        onClose={() => setEditor(null)}
                    />
                ) : null}
                {action ? (
                    <ActionDialog
                        key={action.href}
                        request={action}
                        onClose={() => setAction(null)}
                    />
                ) : null}
            </div>
        </AdminLayout>
    );
}

function announcementPublishNote(kind: string): string {
    return kind === 'outage' || kind === 'maintenance'
        ? 'Pengumuman tampil paling atas di beranda aplikasi pelanggan sasaran, dan setiap pelanggan sasaran menerima satu notifikasi saat jadwal mulai tiba.'
        : 'Pengumuman tampil di beranda aplikasi pelanggan sasaran sesuai jadwal. Pengumuman informasi tidak mengirim notifikasi.';
}

function linkSummary(row: BannerRow): string {
    if (row.link_kind === 'app') {
        return `Membuka halaman ${labelOf(BANNER_APP_ROUTE, row.link_target)}`;
    }

    return row.link_kind === 'url' ? `Membuka ${row.link_target ?? ''}` : 'Tanpa tautan';
}

/** `Lokasi: A, B dan 2 lainnya`; null for an empty list. */
function listTargets(label: string, items: Target[]): string | null {
    if (items.length === 0) return null;

    const shown = items
        .slice(0, 2)
        .map((item) => item.name)
        .join(', ');

    return `${label}: ${shown}${items.length > 2 ? ` dan ${items.length - 2} lainnya` : ''}`;
}

function TargetSummary({
    row,
}: {
    row: Pick<AnnouncementRow, 'audience' | 'location_targets' | 'package_category_targets'>;
}) {
    if (row.audience !== 'targeted') {
        return <span className="text-sm">{labelOf(ANNOUNCEMENT_AUDIENCE, row.audience)}</span>;
    }

    const lines = [
        listTargets('Lokasi', row.location_targets),
        listTargets('Kategori', row.package_category_targets),
    ].filter((line) => line !== null);

    if (lines.length === 0) {
        return (
            <span className="text-sm text-muted-foreground">
                Sasaran kosong, tidak tampil ke siapa pun.
            </span>
        );
    }

    return (
        <div className="space-y-0.5 max-w-xs text-sm">
            {lines.map((line) => (
                <p key={line} className="wrap-break-word">
                    {line}
                </p>
            ))}
        </div>
    );
}

function PeriodCell({
    startsAt,
    endsAt,
    dateFormat,
}: {
    startsAt: string;
    endsAt: string | null;
    dateFormat: string;
}) {
    const start = formatDayTime(startsAt, dateFormat);
    if (!start) return <EmptyCell />;

    return (
        <div className="space-y-0.5 text-sm whitespace-nowrap">
            <p>{start}</p>
            <p className="text-xs text-muted-foreground">
                {endsAt ? `sampai ${formatDayTime(endsAt, dateFormat)}` : 'tanpa batas akhir'}
            </p>
        </div>
    );
}

interface RowActionsProps {
    /** `pengumuman` or `banner`, for the names of the buttons. */
    noun: string;
    title: string;
    status: string;
    onEdit: () => void;
    onPublish: () => void;
    onDeactivate: () => void;
    onDelete: () => void;
}

/** A draft can be published or deleted; a published row can only be deactivated, and only while it is scheduled or live. */
function RowActions({
    noun,
    title,
    status,
    onEdit,
    onPublish,
    onDeactivate,
    onDelete,
}: RowActionsProps) {
    return (
        <div className="flex items-center gap-1">
            <Button
                type="button"
                variant="outline"
                size="sm"
                aria-label={`Ubah ${noun} ${title}`}
                onClick={onEdit}
            >
                Ubah
            </Button>
            {status === 'draft' ? (
                <>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        aria-label={`Terbitkan ${noun} ${title}`}
                        onClick={onPublish}
                    >
                        Terbitkan
                    </Button>
                    <Button
                        type="button"
                        variant="destructive-outline"
                        size="sm"
                        aria-label={`Hapus draf ${noun} ${title}`}
                        onClick={onDelete}
                    >
                        Hapus
                    </Button>
                </>
            ) : null}
            {status === 'scheduled' || status === 'live' ? (
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    aria-label={`Nonaktifkan ${noun} ${title}`}
                    onClick={onDeactivate}
                >
                    Nonaktifkan
                </Button>
            ) : null}
        </div>
    );
}
