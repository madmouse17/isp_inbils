import { Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import { PageHeader, type PermissionOption } from '@/Components/composite';
import { Button } from '@/Components/ui';
import { RoleForm, type RoleTemplateSeed } from './components/RoleForm';

interface CreateProps {
    permissions: { data: PermissionOption[] };
    templates: RoleTemplateSeed[];
}

export default function Create({ permissions, templates }: CreateProps) {
    return (
        <AdminLayout title="Buat peran">
            <div className="space-y-6">
                <PageHeader
                    title="Buat peran"
                    subtitle="Kumpulkan hak akses menjadi satu peran, lalu berikan ke pengguna lewat menu Pengguna."
                    breadcrumbs={[
                        { label: 'Peran', href: route('admin.roles.index') },
                        { label: 'Buat peran' },
                    ]}
                    actions={
                        <Button
                            asChild
                            variant="outline"
                            size="sm"
                            leftIcon={<ArrowLeft className="size-4" aria-hidden="true" />}
                        >
                            <Link href={route('admin.roles.index')}>Kembali</Link>
                        </Button>
                    }
                />
                <RoleForm permissions={permissions.data} templates={templates} />
            </div>
        </AdminLayout>
    );
}
