import { Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import PlatformLayout from '@/Layouts/PlatformLayout';
import { PageHeader, type PermissionOption } from '@/Components/composite';
import { Button } from '@/Components/ui';
import type { PlatformRoleTemplateSeed, PlatformTenantOption } from '@/types/platform';
import { CompanyRoleForm } from './components/CompanyRoleForm';

interface CreateProps {
    tenant: PlatformTenantOption;
    company: PlatformTenantOption;
    permissions: { data: PermissionOption[] };
    template: PlatformRoleTemplateSeed | null;
}

export default function Create({ tenant, company, permissions, template }: CreateProps) {
    const backUrl = route('platform.companies.show', tenant.id);

    return (
        <PlatformLayout title={`Tambah peran ${company.code}`}>
            <PageHeader
                title="Tambah peran"
                subtitle={
                    template
                        ? `Untuk ${company.name} (${company.code}), mulai dari templat ${template.name}.`
                        : `Untuk ${company.name} (${company.code}).`
                }
                breadcrumbs={[
                    { label: 'Dasbor', href: route('platform.dashboard') },
                    { label: tenant.name, href: backUrl },
                    { label: 'Tambah peran' },
                ]}
                actions={
                    <Button
                        asChild
                        variant="outline"
                        size="sm"
                        leftIcon={<ArrowLeft className="size-4" aria-hidden="true" />}
                    >
                        <Link href={backUrl}>Kembali</Link>
                    </Button>
                }
            />
            <CompanyRoleForm
                tenant={tenant}
                company={company}
                permissions={permissions.data}
                template={template}
            />
        </PlatformLayout>
    );
}
