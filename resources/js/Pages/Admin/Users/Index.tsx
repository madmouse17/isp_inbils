import { useMemo, useState } from 'react';
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
    Badge,
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
import { AssignRolesDialog } from './components/AssignRolesDialog';
import type { RoleOption } from './components/RoleChecklist';

interface UserRow {
    id: string;
    name: string;
    email: string;
    roles: string[];
    is_active: boolean;
    /** Homed in another company: listed here for his roles, his login fields are edited in his home company. */
    is_member_only: boolean;
    two_factor_enabled?: boolean;
    can?: {
        delete?: boolean;
        assign_roles?: boolean;
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
    roleOptions?: RoleOption[];
    can: { create: boolean; export: boolean };
}

// The server reads 1/0, true/false and active/inactive; the URL keeps the short form.
const STATUS_OPTIONS = [
    { value: '', label: 'Semua status' },
    { value: '1', label: labelOf(ACTIVE_STATE, true) },
    { value: '0', label: labelOf(ACTIVE_STATE, false) },
];

export default function Index({ users, filters, roleOptions = [], can }: UsersProps) {
    const { params, set, setMany, sortBy, sortDir, onSort, processing } = useServerTable({
        url: route('admin.users.index'),
        filters,
        only: ['users', 'filters', 'can'],
    });
    const [assigning, setAssigning] = useState<UserRow | null>(null);
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
                cell: (user) =>
                    user.is_member_only ? (
                        <div className="flex flex-wrap items-center gap-2">
                            <span className="font-medium text-foreground">{user.name}</span>
                            <Badge variant="info">Anggota</Badge>
                        </div>
                    ) : (
                        <Link
                            href={route('admin.users.show', user.id)}
                            className="font-medium text-primary-text hover:underline"
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
                cell: (user) =>
                    user.roles.length === 0 ? (
                        <span className="text-muted-foreground">Belum ada peran</span>
                    ) : (
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
                    <div className="flex items-center gap-1">
                        {user.can?.assign_roles ? (
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                aria-label={`Atur peran ${user.name}`}
                                onClick={() => setAssigning(user)}
                            >
                                Atur peran
                            </Button>
                        ) : null}
                        {user.is_member_only ? null : (
                            <DataTableActions
                                showHref={route('admin.users.show', user.id)}
                                editHref={route('admin.users.edit', user.id)}
                                deleteHref={
                                    user.can?.delete
                                        ? route('admin.users.destroy', user.id)
                                        : undefined
                                }
                                deleteMessage={`Hapus ${user.name}?`}
                                deleteDescription="Pengguna yang tercatat pada pelanggan atau penyesuaian stok tidak bisa dihapus. Nonaktifkan akunnya."
                            />
                        )}
                    </div>
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
                    subtitle="Anggota perusahaan beserta peran dan status 2FA-nya. Anggota dari perusahaan lain hanya diatur perannya di sini."
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

            {assigning ? (
                <AssignRolesDialog
                    key={assigning.id}
                    user={assigning}
                    roleOptions={roleOptions}
                    onClose={() => setAssigning(null)}
                />
            ) : null}
        </AdminLayout>
    );
}
