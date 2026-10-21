import { Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import { PageHeader, type PermissionOption } from '@/Components/composite';
import { Button } from '@/Components/ui';
import { formatNumber } from '@/lib/format';
import { RoleForm, type TenantRole } from './components/RoleForm';

interface EditProps {
    role: { data: TenantRole };
    permissions: { data: PermissionOption[] };
    templateName: string | null;
}

export default function Edit({ role, permissions, templateName }: EditProps) {
    const item = role.data;

    return (
        <AdminLayout title={`Ubah peran ${item.name}`}>
            <div className="space-y-6">
                <PageHeader
                    title={`Ubah peran ${item.name}`}
                    subtitle={`${formatNumber(item.users_count ?? 0)} pengguna di perusahaan ini memegang peran ini.`}
                    breadcrumbs={[
                        { label: 'Peran', href: route('admin.roles.index') },
                        { label: item.name, href: route('admin.roles.show', item.id) },
                        { label: 'Ubah' },
                    ]}
                    actions={
                        <Button
                            asChild
                            variant="outline"
                            size="sm"
                            leftIcon={<ArrowLeft className="size-4" aria-hidden="true" />}
                        >
                            <Link href={route('admin.roles.show', item.id)}>Kembali</Link>
                        </Button>
                    }
                />
                <RoleForm role={item} permissions={permissions.data} templateName={templateName} />
            </div>
        </AdminLayout>
    );
}
