import { useMemo } from 'react';
import { Link, usePage } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import {
    DataTable,
    DataTableActions,
    type DataTableColumn,
    PageHeader,
    RoleBadge,
} from '@/Components/composite';
import { ExportMenu } from '@/Components/ui/ExportMenu';
import {
    Alert,
    AlertDescription,
    Button,
    Card,
    CardContent,
    EnumBadge,
    Input,
    NativeSelect,
} from '@/Components/ui';
import { useServerTable } from '@/hooks/useServerTable';
import { toPagination } from '@/lib/pagination';
import { ACTIVE_STATE, TWO_FACTOR_STATE, labelOf } from '@/lib/statuses';
import type { PageProps, Paginated } from '@/types';

interface UserRow {
    id: string;
    name: string;
    email: string;
    roles: string[];
    is_active: boolean;
    two_factor_enabled?: boolean;
    can?: {
        delete?: boolean;
    };
}

interface UsersProps {
    users: Paginated<UserRow>;
    filters: {
        search?: string;
        is_active?: string;
        sort?: string;
        direction?: string;
        per_page?: string | number;
    };
    can: { create: boolean; export: boolean };
}

// The server reads 1/0, true/false and active/inactive; the URL keeps the short form.
const STATUS_OPTIONS = [
    { value: '', label: 'Semua status' },
    { value: '1', label: labelOf(ACTIVE_STATE, true) },
    { value: '0', label: labelOf(ACTIVE_STATE, false) },
];

export default function Index({ users, filters, can }: UsersProps) {
    const { params, set, setMany, sortBy, sortDir, onSort, processing } = useServerTable({
        url: route('admin.users.index'),
        filters,
        only: ['users', 'filters', 'can'],
    });
    // A refused delete comes back as a validation error on the page, not on a form.
    const deleteError = (usePage<PageProps>().props.errors as Record<string, string | undefined>)
        .user;

    const hasFilters = Boolean(params.search || params.is_active);

    const createButton = can.create ? (
        <Button asChild variant="primary">
            <Link href={route('admin.users.create')}>Buat Pengguna</Link>
        </Button>
    ) : undefined;

    const columns: DataTableColumn<UserRow>[] = useMemo(
        () => [
            {
                key: 'name',
                header: 'Nama',
                sortable: true,
                sortKey: 'name',
                cell: (user) => (
                    <Link
                        href={route('admin.users.show', user.id)}
                        className="font-medium text-primary hover:underline"
                    >
                        {user.name}
                    </Link>
                ),
            },
            {
                key: 'email',
                header: 'Email',
                sortable: true,
                sortKey: 'email',
            },
            {
                key: 'roles',
                header: 'Peran',
                cell: (user) => (
                    <div className="flex flex-wrap gap-1">
                        {user.roles.map((role) => (
                            <RoleBadge key={role} role={role} />
                        ))}
                    </div>
                ),
            },
            {
                key: 'is_active',
                header: 'Status',
                sortable: true,
                sortKey: 'is_active',
                cell: (user) => <EnumBadge map={ACTIVE_STATE} value={user.is_active} />,
            },
            {
                key: 'two_factor_enabled',
                header: '2FA',
                cell: (user) => (
                    <EnumBadge
                        map={TWO_FACTOR_STATE}
                        value={user.two_factor_enabled ? 'enabled' : 'off'}
                    />
                ),
            },
            {
                key: 'actions',
                header: 'Aksi',
                cell: (user) => (
                    <DataTableActions
                        showHref={route('admin.users.show', user.id)}
                        editHref={route('admin.users.edit', user.id)}
                        deleteHref={
                            user.can?.delete ? route('admin.users.destroy', user.id) : undefined
                        }
                        deleteMessage={`Hapus ${user.name}?`}
                        deleteDescription="Pengguna yang tercatat pada pelanggan atau penyesuaian stok tidak bisa dihapus. Nonaktifkan akunnya."
                    />
                ),
            },
        ],
        [],
    );

    return (
        <AdminLayout title="Pengguna">
            <div className="space-y-6">
                <PageHeader
                    title="Pengguna"
                    subtitle="Akun pengguna perusahaan beserta peran dan status 2FA-nya."
                    actions={
                        <div className="flex flex-wrap gap-2">
                            {can.export ? (
                                <ExportMenu
                                    exportUrl={route('admin.users.export')}
                                    params={params}
                                    formats={['csv', 'pdf']}
                                    canExport={can.export}
                                />
                            ) : null}
                            {createButton}
                        </div>
                    }
                />

                {deleteError ? (
                    <Alert variant="danger">
                        <AlertDescription>{deleteError}</AlertDescription>
                    </Alert>
                ) : null}

                <Card>
                    <CardContent className="space-y-4 pt-6">
                        <div className="flex flex-col gap-3 md:flex-row md:items-end">
                            <div className="min-w-48 flex-1">
                                <Input
                                    label="Cari"
                                    value={params.search ?? ''}
                                    onChange={(event) => set('search', event.target.value)}
                                    placeholder="Nama atau email"
                                />
                            </div>
                            <div className="w-full md:w-40">
                                <NativeSelect
                                    label="Status"
                                    value={params.is_active ?? ''}
                                    onChange={(event) => set('is_active', event.target.value)}
                                    options={STATUS_OPTIONS}
                                />
                            </div>
                        </div>

                        <DataTable
                            columns={columns}
                            pagination={toPagination(users)}
                            sortBy={sortBy}
                            sortDir={sortDir}
                            onSort={onSort}
                            onPageChange={(page) => set('page', page)}
                            onPerPageChange={(perPage) => set('per_page', perPage)}
                            emptyTitle="Belum ada pengguna"
                            emptyDescription="Pengguna adalah akun yang bisa masuk ke aplikasi. Buat pengguna pertama untuk memulai."
                            emptyAction={createButton}
                            hasFilters={hasFilters}
                            onResetFilters={() => setMany({ search: '', is_active: '' })}
                            itemLabel="pengguna"
                            loading={processing}
                        />
                    </CardContent>
                </Card>
            </div>
        </AdminLayout>
    );
}
