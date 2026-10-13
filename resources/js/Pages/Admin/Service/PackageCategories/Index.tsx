import { useMemo, useState } from 'react';
import type { FormEvent } from 'react';
import { Link, useForm } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import { DataTable, DataTableActions, PageHeader } from '@/Components/composite';
import type { DataTableColumn } from '@/Components/composite';
import {
    ConfirmRequestDialog,
    type ConfirmRequest,
} from '@/Components/composite/ConfirmRequestDialog';
import { Button, Input, Modal, Switch } from '@/Components/ui';
import { EnumBadge } from '@/Components/ui/EnumBadge';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/Components/ui/Select';
import { useServerTable } from '@/hooks/useServerTable';
import { toPagination } from '@/lib/pagination';
import { ACTIVE_STATE } from '@/lib/statuses';
import type { Paginated } from '@/types';

interface CategoryRow {
    id: number;
    code: string;
    name: string;
    sort_order: number;
    is_active: boolean;
    packages_count: number;
}

interface IndexProps extends Record<string, unknown> {
    packageCategories: Paginated<CategoryRow>;
    filters: {
        is_active?: string;
        search?: string;
        sort?: string;
        direction?: string;
        per_page?: string | number;
    };
    can: { create: boolean; update: boolean; delete: boolean };
}

export default function Index({ packageCategories, filters, can }: IndexProps) {
    /** `'new'` adds a category, a row edits it. */
    const [dialog, setDialog] = useState<'new' | CategoryRow | null>(null);
    const [confirm, setConfirm] = useState<ConfirmRequest | null>(null);
    const { params, set, sortBy, sortDir, onSort, processing } = useServerTable({
        url: route('admin.service-package-categories.index'),
        filters,
        only: ['packageCategories', 'filters', 'can'],
    });

    const columns: DataTableColumn<CategoryRow>[] = useMemo(() => {
        const base: DataTableColumn<CategoryRow>[] = [
            {
                key: 'code',
                header: 'Kode',
                sortable: true,
                sortKey: 'code',
                className: 'font-mono',
                cell: (row) => row.code,
            },
            {
                key: 'name',
                header: 'Nama',
                sortable: true,
                sortKey: 'name',
                cell: (row) => <span className="font-medium">{row.name}</span>,
            },
            {
                key: 'sort_order',
                header: 'Urutan',
                sortable: true,
                sortKey: 'sort_order',
                className: 'text-right tabular-nums',
                cell: (row) => row.sort_order,
            },
            {
                key: 'packages_count',
                header: 'Paket',
                className: 'text-right tabular-nums',
                cell: (row) =>
                    row.packages_count > 0 ? (
                        <Link
                            href={route('admin.service-packages.index', {
                                service_package_category_id: row.id,
                            })}
                            className="font-medium text-primary hover:underline"
                        >
                            {row.packages_count}
                        </Link>
                    ) : (
                        0
                    ),
            },
            {
                key: 'is_active',
                header: 'Status',
                sortable: true,
                sortKey: 'is_active',
                cell: (row) => <EnumBadge map={ACTIVE_STATE} value={row.is_active} />,
            },
        ];

        if (!can.update && !can.delete) return base;

        return [
            ...base,
            {
                key: 'actions',
                header: 'Aksi',
                cell: (row) => (
                    <div className="flex items-center gap-1">
                        {can.update ? <DataTableActions onEdit={() => setDialog(row)} /> : null}
                        {can.delete ? (
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                aria-label={`Hapus kategori ${row.name}`}
                                onClick={() =>
                                    setConfirm({
                                        title: `Hapus kategori ${row.name}?`,
                                        description:
                                            'Kategori hanya bisa dihapus selama tidak ada paket di dalamnya. Nonaktifkan kategori jika sudah dipakai.',
                                        confirmLabel: 'Hapus kategori',
                                        href: route(
                                            'admin.service-package-categories.destroy',
                                            row.id,
                                        ),
                                    })
                                }
                            >
                                Hapus
                            </Button>
                        ) : null}
                    </div>
                ),
            },
        ];
    }, [can.update, can.delete]);

    const newCategory = can.create ? (
        <Button type="button" variant="primary" size="sm" onClick={() => setDialog('new')}>
            <Plus className="size-4" aria-hidden="true" />
            Kategori baru
        </Button>
    ) : undefined;

    return (
        <AdminLayout title="Kategori Paket">
            <div className="space-y-4">
                <PageHeader
                    title="Kategori Paket"
                    description="Golongan paket layanan, misalnya Rumahan, Bisnis, dan Korporat. Dipakai untuk mengelompokkan pilihan paket dan laporan."
                    actions={newCategory}
                />

                <div className="flex flex-col gap-3 rounded-lg border bg-card p-3 md:flex-row md:flex-wrap md:items-end">
                    <div className="space-y-1 min-w-48 flex-1">
                        <label
                            className="text-xs font-medium text-muted-foreground"
                            htmlFor="pkg-cat-search"
                        >
                            Cari
                        </label>
                        <Input
                            id="pkg-cat-search"
                            value={params.search ?? ''}
                            onChange={(event) => set('search', event.target.value)}
                            placeholder="Kode atau nama..."
                        />
                    </div>
                    <div className="space-y-1">
                        <label
                            htmlFor="pkg-cat-active"
                            className="text-xs font-medium text-muted-foreground"
                        >
                            Status
                        </label>
                        <Select
                            value={params.is_active || '__all__'}
                            onValueChange={(value) =>
                                set('is_active', value === '__all__' ? '' : value)
                            }
                        >
                            <SelectTrigger id="pkg-cat-active" className="w-36">
                                <SelectValue placeholder="Semua" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="__all__">Semua</SelectItem>
                                <SelectItem value="1">Aktif</SelectItem>
                                <SelectItem value="0">Nonaktif</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                </div>

                <DataTable
                    columns={columns}
                    pagination={toPagination(packageCategories)}
                    sortBy={sortBy}
                    sortDir={sortDir}
                    onSort={onSort}
                    onPageChange={(page) => set('page', page)}
                    onPerPageChange={(perPage) => set('per_page', perPage)}
                    loading={processing}
                    emptyTitle="Belum ada kategori paket"
                    emptyDescription="Tidak ada kategori yang cocok dengan filter. Tambahkan kategori agar paket bisa dikelompokkan."
                    emptyAction={newCategory}
                />

                {dialog && (
                    <CategoryDialog
                        onClose={() => setDialog(null)}
                        category={dialog === 'new' ? undefined : dialog}
                    />
                )}
                {confirm && (
                    <ConfirmRequestDialog
                        key={confirm.href}
                        request={confirm}
                        onClose={() => setConfirm(null)}
                    />
                )}
            </div>
        </AdminLayout>
    );
}

interface CategoryDialogProps {
    onClose: () => void;
    /** The category being edited. Omit to add a new one. */
    category?: CategoryRow;
}

/** Mounted only while open, so each opening starts from the category (or an empty form) with no stale errors. */
function CategoryDialog({ onClose, category }: CategoryDialogProps) {
    const form = useForm({
        code: category?.code ?? '',
        name: category?.name ?? '',
        sort_order: category ? String(category.sort_order) : '0',
        is_active: category?.is_active ?? true,
    });
    const { data, setData, errors, processing } = form;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: onClose };

        if (category) {
            form.put(route('admin.service-package-categories.update', category.id), options);
        } else {
            form.post(route('admin.service-package-categories.store'), options);
        }
    };

    return (
        <Modal
            open
            onClose={onClose}
            title={category ? `Ubah kategori ${category.name}` : 'Kategori baru'}
        >
            <form onSubmit={submit} className="space-y-4">
                <Input
                    id="pkg-cat-code"
                    label="Kode"
                    value={data.code}
                    onChange={(event) => setData('code', event.target.value)}
                    error={errors.code}
                    hint={
                        category
                            ? 'Kode tidak bisa diubah setelah kategori dibuat.'
                            : 'Huruf besar, angka, _ atau -. Tidak bisa diubah nanti.'
                    }
                    maxLength={30}
                    disabled={category !== undefined}
                    required
                />
                <Input
                    id="pkg-cat-name"
                    label="Nama"
                    value={data.name}
                    onChange={(event) => setData('name', event.target.value)}
                    error={errors.name}
                    maxLength={100}
                    required
                />
                <Input
                    id="pkg-cat-sort"
                    label="Urutan"
                    type="number"
                    min={0}
                    max={65535}
                    value={data.sort_order}
                    onChange={(event) => setData('sort_order', event.target.value)}
                    error={errors.sort_order}
                    hint="Angka kecil tampil lebih dulu di pilihan paket."
                />
                <div className="space-y-1">
                    <Switch
                        label="Aktif"
                        checked={data.is_active}
                        onCheckedChange={(checked) => setData('is_active', checked)}
                    />
                    {errors.is_active ? (
                        <p className="text-sm text-destructive-text">{errors.is_active}</p>
                    ) : (
                        <p className="text-sm text-muted-foreground">
                            Kategori nonaktif tidak muncul di pilihan paket baru.
                        </p>
                    )}
                </div>
                <div className="flex justify-end gap-2">
                    <Button type="button" variant="secondary" onClick={onClose}>
                        Batal
                    </Button>
                    <Button type="submit" variant="primary" loading={processing}>
                        {category ? 'Simpan' : 'Tambah kategori'}
                    </Button>
                </div>
            </form>
        </Modal>
    );
}
