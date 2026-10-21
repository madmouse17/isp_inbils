import { Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import PlatformLayout from '@/Layouts/PlatformLayout';
import { PageHeader, type PermissionOption } from '@/Components/composite';
import { Button } from '@/Components/ui';
import { formatNumber } from '@/lib/format';
import type { PlatformCompanyRole, PlatformTenantOption } from '@/types/platform';
import { CompanyRoleForm, DeleteCompanyRole } from './components/CompanyRoleForm';

interface EditProps {
    tenant: PlatformTenantOption;
    company: PlatformTenantOption;
    role: { data: PlatformCompanyRole };
    permissions: { data: PermissionOption[] };
    moveTargets: { id: string; name: string }[];
}

export default function Edit({ tenant, company, role, permissions, moveTargets }: EditProps) {
    const item = role.data;
    const backUrl = route('platform.companies.show', tenant.id);

    return (
        <PlatformLayout title={`Perbaiki peran ${item.name}`}>
            <PageHeader
                title={`Perbaiki peran ${item.name}`}
                subtitle={`${company.name} (${company.code}). ${formatNumber(item.users_count)} pengguna memegang peran ini.`}
                breadcrumbs={[
                    { label: 'Dasbor', href: route('platform.dashboard') },
                    { label: tenant.name, href: backUrl },
                    { label: item.name },
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
                role={item}
            />
            <DeleteCompanyRole
                tenant={tenant}
                company={company}
                role={item}
                moveTargets={moveTargets}
            />
        </PlatformLayout>
    );
}
