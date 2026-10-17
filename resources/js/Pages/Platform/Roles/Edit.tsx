import { Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import PlatformLayout from '@/Layouts/PlatformLayout';
import { PageHeader, type PermissionOption } from '@/Components/composite';
import { Button } from '@/Components/ui';
import { formatNumber } from '@/lib/format';
import type { PlatformRole } from '@/types/platform';
import { RoleForm } from './components/RoleForm';

interface EditProps {
    role: { data: PlatformRole };
    permissions: { data: PermissionOption[] };
    protectedRoles: string[];
    seededWarning: string | null;
}

export default function Edit({ role, permissions, protectedRoles, seededWarning }: EditProps) {
    const item = role.data;

    return (
        <PlatformLayout title={`Ubah peran ${item.name}`}>
            <PageHeader
                title={`Ubah peran ${item.name}`}
                subtitle={`${formatNumber(item.users_count ?? 0)} pengguna di semua perusahaan memegang peran ini.`}
                breadcrumbs={[
                    { label: 'Peran & hak akses', href: route('platform.roles.index') },
                    { label: item.name },
                ]}
                actions={
                    <Button
                        asChild
                        variant="outline"
                        size="sm"
                        leftIcon={<ArrowLeft className="size-4" aria-hidden="true" />}
                    >
                        <Link href={route('platform.roles.index')}>Kembali</Link>
                    </Button>
                }
            />
            <RoleForm
                role={item}
                permissions={permissions.data}
                protectedRole={protectedRoles.includes(item.name)}
                seededWarning={seededWarning}
            />
        </PlatformLayout>
    );
}
