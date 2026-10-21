import { Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import PlatformLayout from '@/Layouts/PlatformLayout';
import { PageHeader, type PermissionOption } from '@/Components/composite';
import { Button } from '@/Components/ui';
import { TemplateForm } from './components/TemplateForm';

export default function Create({ permissions }: { permissions: { data: PermissionOption[] } }) {
    return (
        <PlatformLayout title="Buat templat peran">
            <PageHeader
                title="Buat templat peran"
                subtitle="Beri nama templat, lalu pilih hak akses yang disalin ke perusahaan baru."
                breadcrumbs={[
                    { label: 'Templat peran', href: route('platform.role-templates.index') },
                    { label: 'Buat templat' },
                ]}
                actions={
                    <Button
                        asChild
                        variant="outline"
                        size="sm"
                        leftIcon={<ArrowLeft className="size-4" aria-hidden="true" />}
                    >
                        <Link href={route('platform.role-templates.index')}>Kembali</Link>
                    </Button>
                }
            />
            <TemplateForm permissions={permissions.data} />
        </PlatformLayout>
    );
}
