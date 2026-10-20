import { useMemo } from 'react';
import { DataTable, RoleBadge, type DataTableColumn } from '@/Components/composite';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
    EnumBadge,
    Input,
    NativeSelect,
} from '@/Components/ui';
import { useServerTable } from '@/hooks/useServerTable';
import { toPagination } from '@/lib/pagination';
import { TWO_FACTOR_STATE } from '@/lib/statuses';
import type { Paginated } from '@/types';
import type {
    PlatformCompanyUser,
    PlatformTenantProfile,
    PlatformUserFilters,
} from '@/types/platform';
import { UserActions } from './UserActions';
import { AccountStatusCell, LastLoginCell } from './UserSignInState';

interface UsersSectionProps {
    tenant: PlatformTenantProfile;
    users: Paginated<PlatformCompanyUser>;
    filters: PlatformUserFilters;
}

const STATUS_OPTIONS = [
    { value: '', label: 'Semua status' },
    { value: 'active', label: 'Aktif' },
    { value: 'inactive', label: 'Nonaktif' },
];

export function UsersSection({ tenant, users, filters }: UsersSectionProps) {
    const { params, set, setMany, processing } = useServerTable({
        url: route('platform.companies.show', tenant.id),
        filters,
        only: ['users', 'userFilters'],
    });
    const hasFilters = Boolean(params.search || params.status);
    const columns = useMemo<DataTableColumn<PlatformCompanyUser>[]>(
        () => [
            {
                key: 'name',
                header: 'Pengguna',
                cell: (user) => (
                    <div>
                        <p className="font-medium">{user.name}</p>
                        <p className="text-xs text-muted-foreground">{user.email}</p>
                    </div>
                ),
            },
            {
                key: 'company',
                header: 'Perusahaan',
                cell: (user) => (
                    <div>
                        <p>{user.company.name}</p>
                        <p className="text-xs text-muted-foreground">{user.company.code}</p>
                    </div>
                ),
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
                cell: (user) => <AccountStatusCell user={user} />,
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
                key: 'last_login_at',
                header: 'Masuk terakhir',
                cell: (user) => <LastLoginCell user={user} />,
            },
            {
                key: 'actions',
                header: 'Aksi',
                cell: (user) => <UserActions tenant={tenant} user={user} />,
            },
        ],
        [tenant],
    );

    return (
        <Card>
            <CardHeader>
                <CardTitle>Pengguna</CardTitle>
                <CardDescription>
                    Akun pada perusahaan induk dan seluruh anak perusahaannya.
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
                <div className="flex flex-col gap-3 md:flex-row md:items-end">
                    <div className="min-w-48 flex-1">
                        <Input
                            label="Cari"
                            placeholder="Nama atau email"
                            value={params.search ?? ''}
                            maxLength={100}
                            onChange={(event) => set('search', event.target.value)}
                        />
                    </div>
                    <div className="w-full md:w-44">
                        <NativeSelect
                            label="Status"
                            value={params.status ?? ''}
                            options={STATUS_OPTIONS}
                            onChange={(event) => set('status', event.target.value)}
                        />
                    </div>
                </div>
                <DataTable
                    columns={columns}
                    pagination={toPagination(users)}
                    onPageChange={(page) => set('page', page)}
                    onPerPageChange={(perPage) => set('per_page', perPage)}
                    emptyTitle="Belum ada pengguna"
                    emptyDescription="Pengguna perusahaan ini akan tercatat di sini."
                    hasFilters={hasFilters}
                    onResetFilters={() => setMany({ search: '', status: '' })}
                    itemLabel="pengguna"
                    loading={processing}
                />
            </CardContent>
        </Card>
    );
}
