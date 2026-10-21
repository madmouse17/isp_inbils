import { useEffect, useState } from 'react';
import type { FormEvent } from 'react';
import { Link, useForm, usePage } from '@inertiajs/react';
import {
    Alert,
    AlertDescription,
    AlertTitle,
    Button,
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
    Input,
    NativeSelect,
} from '@/Components/ui';
import {
    ConflictBanner,
    PermissionGroupSelector,
    type PermissionOption,
} from '@/Components/composite';
import { splitGrantable } from '@/Components/composite/permissionGroups';
import { formatNumber } from '@/lib/format';
import type { PageProps } from '@/types';

export interface TenantRole {
    id: string;
    name: string;
    template_key: string | null;
    permissions: string[];
    users_count?: number;
    /** `OptimisticLock::version`: sent back so a save over a newer one is refused. */
    version: string;
}

export interface RoleTemplateSeed {
    key: string;
    name: string;
    permissions: string[];
}

interface RoleFormProps {
    permissions: PermissionOption[];
    /** The role being edited; absent when a role is created. */
    role?: TenantRole;
    /** Create only: the templates a role can start from. */
    templates?: RoleTemplateSeed[];
    /** Edit only: the name of the template the role was made from. */
    templateName?: string | null;
}

interface RoleFormData {
    name: string;
    permissions: string[];
    expected_updated_at: string;
}

const NO_TEMPLATE = '';

/**
 * One form for both pages. The server's refusals reach the form by key: `name` and `permissions` sit under their
 * fields; `roles` (the company would lose its last administrator) and `role` come back as banners, because no
 * field owns them; a stale edit comes back as `conflict`.
 */
export function RoleForm({ permissions, role, templates = [], templateName }: RoleFormProps) {
    const form = useForm<RoleFormData>({
        name: role?.name ?? '',
        permissions: role?.permissions ?? [],
        expected_updated_at: role?.version ?? '',
    });
    const [templateKey, setTemplateKey] = useState(NO_TEMPLATE);
    const [templateNote, setTemplateNote] = useState<string | null>(null);
    const pageErrors = usePage<PageProps>().props.errors as Record<string, string | undefined>;
    const conflict = pageErrors.conflict;
    // A conflict reply carries the newer row: keeping the old version makes the retry conflict again until the page is reloaded.
    useEffect(() => {
        if (role && !conflict) form.setData('expected_updated_at', role.version);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [role?.version]);

    const permissionError =
        form.errors.permissions ??
        Object.entries(form.errors).find(([key]) => key.startsWith('permissions.'))?.[1];
    const guardError = pageErrors.roles ?? pageErrors.role;
    const hasFieldErrors = Boolean(form.errors.name || permissionError);
    const holdsAdministration = form.data.permissions.includes('roles.manage');
    const holdsPortal = form.data.permissions.includes('portal.access');
    const submitLabel = role ? 'Simpan peran' : 'Buat peran';

    const startFromTemplate = (key: string) => {
        setTemplateKey(key);
        const template = templates.find((candidate) => candidate.key === key);

        if (!template) {
            setTemplateNote(null);
            return;
        }

        const { granted, skipped } = splitGrantable(template.permissions, permissions);

        form.setData((data) => ({
            ...data,
            name: data.name.trim() === '' ? template.name : data.name,
            permissions: granted,
        }));
        setTemplateNote(
            skipped.length > 0
                ? `${formatNumber(skipped.length)} hak akses tidak Anda miliki dan dilewati.`
                : `${formatNumber(granted.length)} hak akses dari templat ${template.name} dimuat.`,
        );
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (form.processing) return;

        if (role) {
            form.put(route('admin.roles.update', role.id), { preserveScroll: true });

            return;
        }

        form.transform(({ name, permissions: granted }) => ({ name, permissions: granted }));
        form.post(route('admin.roles.store'), { preserveScroll: true });
    };

    return (
        <form onSubmit={submit} className="space-y-6" noValidate>
            <ConflictBanner />

            {guardError ? (
                <Alert variant="danger">
                    <AlertTitle>Peran belum dapat disimpan</AlertTitle>
                    <AlertDescription>{guardError}</AlertDescription>
                </Alert>
            ) : null}

            {hasFieldErrors ? (
                <Alert variant="danger">
                    <AlertTitle>Peran belum dapat disimpan</AlertTitle>
                    <AlertDescription>
                        Periksa nama dan hak akses yang ditandai di bawah.
                    </AlertDescription>
                </Alert>
            ) : null}

            <Card>
                <CardHeader>
                    <CardTitle>Data peran</CardTitle>
                    <CardDescription>
                        {role && templateName
                            ? `Dibuat dari templat ${templateName}. Perubahan templat tidak mengubah peran ini.`
                            : 'Peran ini hanya berlaku di perusahaan ini.'}
                    </CardDescription>
                </CardHeader>
                <CardContent className="space-y-6">
                    {role || templates.length === 0 ? null : (
                        <div className="space-y-2 max-w-xl">
                            <NativeSelect
                                label="Mulai dari templat"
                                value={templateKey}
                                onChange={(event) => startFromTemplate(event.target.value)}
                                options={[
                                    { value: NO_TEMPLATE, label: 'Tanpa templat' },
                                    ...templates.map((template) => ({
                                        value: template.key,
                                        label: template.name,
                                    })),
                                ]}
                                hint="Opsional. Mengisi nama dan hak akses; keduanya bisa Anda ubah."
                            />
                            {templateNote ? (
                                <p role="status" className="text-sm text-muted-foreground">
                                    {templateNote}
                                </p>
                            ) : null}
                        </div>
                    )}
                    <div className="max-w-xl">
                        <Input
                            id="role-name"
                            label="Nama"
                            value={form.data.name}
                            maxLength={255}
                            hint="Nama harus unik di perusahaan ini."
                            error={form.errors.name}
                            onChange={(event) => form.setData('name', event.target.value)}
                            required
                        />
                    </div>
                    <div className="space-y-2">
                        <p className="text-sm text-muted-foreground">
                            Peran tanpa hak mengelola peran tidak boleh menggabungkan persetujuan
                            dan pelaksanaan tugas yang saling mengawasi.
                        </p>
                        <PermissionGroupSelector
                            permissions={permissions}
                            value={form.data.permissions}
                            onChange={(selected) => form.setData('permissions', selected)}
                            error={permissionError}
                        />
                    </div>
                    {holdsAdministration ? (
                        <Alert variant="warning" role="status">
                            <AlertDescription>
                                Pemegang peran ini dapat mengubah semua peran perusahaan.
                            </AlertDescription>
                        </Alert>
                    ) : null}
                    {holdsPortal ? (
                        <Alert variant="info" role="status">
                            <AlertDescription>
                                Peran akun pelanggan: berlaku di aplikasi Pelanggan.
                            </AlertDescription>
                        </Alert>
                    ) : null}
                </CardContent>
            </Card>

            <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <Button asChild variant="outline">
                    <Link href={route('admin.roles.index')}>Batal</Link>
                </Button>
                <Button type="submit" loading={form.processing}>
                    {submitLabel}
                </Button>
            </div>
        </form>
    );
}
