import { Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import PlatformLayout from '@/Layouts/PlatformLayout';
import { PageHeader, type PermissionOption } from '@/Components/composite';
import { Button } from '@/Components/ui';
import type { PlatformRoleTemplate } from '@/types/platform';
import { TemplateForm } from './components/TemplateForm';

interface EditProps {
    template: { data: PlatformRoleTemplate };
    permissions: { data: PermissionOption[] };
}

export default function Edit({ template, permissions }: EditProps) {
    const item = template.data;

    return (
        <PlatformLayout title={`Ubah templat ${item.name}`}>
            <PageHeader
                title={`Ubah templat ${item.name}`}
                subtitle={`Kunci ${item.key}. Perusahaan yang sudah ada tidak ikut berubah.`}
                breadcrumbs={[
                    { label: 'Templat peran', href: route('platform.role-templates.index') },
                    { label: item.name },
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
            <TemplateForm template={item} permissions={permissions.data} />
        </PlatformLayout>
    );
}
