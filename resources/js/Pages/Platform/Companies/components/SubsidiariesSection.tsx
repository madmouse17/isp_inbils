import { useMemo, useState } from 'react';
import { Plus } from 'lucide-react';
import { DataTable, type DataTableColumn } from '@/Components/composite';
import {
    Button,
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
    EnumBadge,
} from '@/Components/ui';
import { ACTIVE_STATE } from '@/lib/statuses';
import { formatNumber } from '@/lib/format';
import type {
    PlatformCompanyUser,
    PlatformSubsidiary,
    PlatformTenantProfile,
} from '@/types/platform';
import { SubsidiaryDialog } from './SubsidiaryDialog';

interface SubsidiariesSectionProps {
    tenant: PlatformTenantProfile;
    admins: PlatformCompanyUser[];
    subsidiaries: PlatformSubsidiary[];
}

export function SubsidiariesSection({ tenant, admins, subsidiaries }: SubsidiariesSectionProps) {
    const [adding, setAdding] = useState(false);
    const columns = useMemo<DataTableColumn<PlatformSubsidiary>[]>(
        () => [
            {
                key: 'name',
                header: 'Nama',
                cell: (subsidiary) => (
                    <div>
                        <p className="font-medium">{subsidiary.name}</p>
                        <p className="text-xs text-muted-foreground">{subsidiary.code}</p>
                    </div>
                ),
            },
            {
                key: 'is_active',
                header: 'Status',
                cell: (subsidiary) => <EnumBadge map={ACTIVE_STATE} value={subsidiary.is_active} />,
            },
            { key: 'currency', header: 'Mata uang' },
            { key: 'timezone', header: 'Zona waktu' },
            {
                key: 'users_count',
                header: 'Pengguna',
                className: 'text-right tabular-nums',
                cell: (subsidiary) => formatNumber(subsidiary.users_count),
            },
        ],
        [],
    );

    return (
        <>
            <Card>
                <CardHeader className="gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <CardTitle>Anak perusahaan</CardTitle>
                        <CardDescription>
                            Unit di bawah perusahaan induk dengan data yang tetap terpisah.
                        </CardDescription>
                    </div>
                    <div className="shrink-0">
                        <Button
                            type="button"
                            size="sm"
                            leftIcon={<Plus className="size-4" aria-hidden="true" />}
                            disabled={tenant.status !== 'active'}
                            onClick={() => setAdding(true)}
                        >
                            Tambah anak perusahaan
                        </Button>
                    </div>
                </CardHeader>
                <CardContent>
                    {tenant.status !== 'active' ? (
                        <p className="mb-4 text-sm text-muted-foreground">
                            Aktifkan kembali perusahaan sebelum menambah anak perusahaan.
                        </p>
                    ) : null}
                    <DataTable
                        columns={columns}
                        rows={subsidiaries}
                        emptyTitle="Belum ada anak perusahaan"
                        emptyDescription="Anak perusahaan yang dibuat untuk tenant ini akan tercatat di sini."
                        itemLabel="anak perusahaan"
                    />
                </CardContent>
            </Card>
            {adding ? (
                <SubsidiaryDialog
                    tenant={tenant}
                    admins={admins}
                    onClose={() => setAdding(false)}
                />
            ) : null}
        </>
    );
}
