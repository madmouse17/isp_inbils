import { Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import PlatformLayout from '@/Layouts/PlatformLayout';
import { PageHeader, type PermissionOption } from '@/Components/composite';
import { Button } from '@/Components/ui';
import { RoleForm } from './components/RoleForm';

export default function Create({ permissions }: { permissions: { data: PermissionOption[] } }) {
    return (
        <PlatformLayout title="Buat peran">
            <PageHeader
                title="Buat peran"
                subtitle="Beri nama peran, lalu pilih hak akses yang berlaku di semua perusahaan."
                breadcrumbs={[
                    { label: 'Peran & hak akses', href: route('platform.roles.index') },
                    { label: 'Buat peran' },
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
            <RoleForm permissions={permissions.data} />
        </PlatformLayout>
    );
}
