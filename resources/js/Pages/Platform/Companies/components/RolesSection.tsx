import { useMemo, useState } from 'react';
import { Link } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { DataTable, type DataTableColumn } from '@/Components/composite';
import {
    Badge,
    Button,
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
    NativeSelect,
} from '@/Components/ui';
import { formatNumber } from '@/lib/format';
import type {
    PlatformCompanyRoleRow,
    PlatformCompanyRoles,
    PlatformRoleTemplateOption,
    PlatformTenantProfile,
} from '@/types/platform';

interface RolesSectionProps {
    tenant: PlatformTenantProfile;
    companyRoles: PlatformCompanyRoles[];
    templates: PlatformRoleTemplateOption[];
}

interface AddFromTemplateProps {
    tenant: PlatformTenantProfile;
    companyId: string;
    companyCode: string;
    templates: PlatformRoleTemplateOption[];
}

function AddFromTemplate({ tenant, companyId, companyCode, templates }: AddFromTemplateProps) {
    const [templateKey, setTemplateKey] = useState(templates[0]?.key ?? '');

    return (
        <div className="flex flex-col gap-2 sm:flex-row sm:items-end">
            <div className="w-full sm:w-64">
                <NativeSelect
                    id={`role-template-${companyId}`}
                    label={`Templat untuk ${companyCode}`}
                    value={templateKey}
                    options={templates.map((template) => ({
                        value: template.key,
                        label: template.name,
                    }))}
                    onChange={(event) => setTemplateKey(event.target.value)}
                />
            </div>
            <Button
                asChild
                variant="outline"
                leftIcon={<Plus className="size-4" aria-hidden="true" />}
            >
                <Link
                    href={route('platform.companies.roles.create', {
                        company: tenant.id,
                        target: companyId,
                        template: templateKey,
                    })}
                >
                    Tambah dari templat
                </Link>
            </Button>
        </div>
    );
}

export function RolesSection({ tenant, companyRoles, templates }: RolesSectionProps) {
    const templateNames = useMemo(
        () => Object.fromEntries(templates.map((template) => [template.key, template.name])),
        [templates],
    );
    const columns = useMemo<DataTableColumn<PlatformCompanyRoleRow>[]>(
        () => [
            {
                key: 'name',
                header: 'Nama',
                cell: (role) => <span className="font-medium">{role.name}</span>,
            },
            {
                key: 'template_key',
                header: 'Templat',
                cell: (role) =>
                    role.template_key ? (
                        <Badge variant="neutral">
                            Dari templat {templateNames[role.template_key] ?? role.template_key}
                        </Badge>
                    ) : (
                        <span className="text-muted-foreground">Dibuat perusahaan</span>
                    ),
            },
            {
                key: 'permissions_count',
                header: 'Hak akses',
                className: 'text-right tabular-nums',
                cell: (role) => formatNumber(role.permissions_count),
            },
            {
                key: 'users_count',
                header: 'Pengguna',
                className: 'text-right tabular-nums',
                cell: (role) => formatNumber(role.users_count),
            },
            {
                key: 'actions',
                header: 'Aksi',
                cell: (role) => (
                    <Button asChild variant="outline" size="sm">
                        <Link
                            href={route('platform.companies.roles.edit', {
                                company: tenant.id,
                                role: role.id,
                            })}
                            aria-label={`Perbaiki peran ${role.name}`}
                        >
                            Perbaiki
                        </Link>
                    </Button>
                ),
            },
        ],
        [tenant.id, templateNames],
    );

    return (
        <Card>
            <CardHeader>
                <CardTitle>Peran</CardTitle>
                <CardDescription>
                    Setiap perusahaan mengelola perannya sendiri. Perbaikan oleh operator tercatat
                    di audit perusahaan (support).
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-8">
                {companyRoles.map(({ company, roles }) => (
                    <section
                        key={company.id}
                        aria-labelledby={`roles-${company.id}`}
                        className="space-y-3"
                    >
                        <div className="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                            <div>
                                <h3 id={`roles-${company.id}`} className="text-base font-semibold">
                                    {company.name}
                                </h3>
                                <p className="text-sm text-muted-foreground">Kode {company.code}</p>
                            </div>
                            {templates.length > 0 ? (
                                <AddFromTemplate
                                    tenant={tenant}
                                    companyId={company.id}
                                    companyCode={company.code}
                                    templates={templates}
                                />
                            ) : null}
                        </div>
                        <DataTable
                            columns={columns}
                            rows={roles}
                            emptyTitle="Belum ada peran"
                            emptyDescription={
                                templates.length > 0
                                    ? 'Tambah peran dari templat agar pengguna perusahaan ini punya hak akses.'
                                    : 'Buat templat peran lebih dulu, lalu salin ke perusahaan ini.'
                            }
                            itemLabel="peran"
                        />
                    </section>
                ))}
                {companyRoles.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        Perusahaan ini belum mempunyai data peran.
                    </p>
                ) : null}
            </CardContent>
        </Card>
    );
}
