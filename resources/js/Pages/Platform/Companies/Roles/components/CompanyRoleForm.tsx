import { useMemo, useRef, useState } from 'react';
import type { FormEvent } from 'react';
import { Link, router, useForm } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
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
    SearchSelect,
} from '@/Components/ui';
import {
    ConflictBanner,
    PermissionGroupSelector,
    type PermissionOption,
} from '@/Components/composite';
import { ConfirmActionDialog } from '@/Components/composite/platform/ConfirmActionDialog';
import { formatNumber } from '@/lib/format';
import type {
    PlatformCompanyRole,
    PlatformRoleTemplateSeed,
    PlatformTenantOption,
} from '@/types/platform';

interface CompanyRoleFormProps {
    tenant: PlatformTenantOption;
    company: PlatformTenantOption;
    permissions: PermissionOption[];
    role?: PlatformCompanyRole;
    template?: PlatformRoleTemplateSeed | null;
}

export function CompanyRoleForm({
    tenant,
    company,
    permissions,
    role,
    template,
}: CompanyRoleFormProps) {
    const [confirming, setConfirming] = useState(false);
    const [processing, setProcessing] = useState(false);
    const inFlight = useRef(false);
    const form = useForm({
        company_id: company.id,
        name: role?.name ?? template?.name ?? '',
        permissions: role?.permissions ?? template?.permissions ?? ([] as string[]),
        expected_updated_at: role?.version ?? '',
    });
    const permissionError =
        form.errors.permissions ??
        Object.entries(form.errors).find(([key]) => key.startsWith('permissions.'))?.[1];
    const submitLabel = role ? 'Simpan peran' : 'Tambah peran';
    const backUrl = route('platform.companies.show', tenant.id);

    const openConfirmation = (event: FormEvent) => {
        event.preventDefault();
        form.clearErrors();
        setConfirming(true);
    };

    const submitRole = () => {
        if (inFlight.current) return;

        inFlight.current = true;
        setProcessing(true);
        const options = {
            preserveScroll: true,
            preserveState: true,
            onError: (errors: Record<string, string>) => {
                form.setError(errors);
                setConfirming(false);
            },
            onFinish: () => {
                inFlight.current = false;
                setProcessing(false);
            },
        };

        if (role) {
            form.put(
                route('platform.companies.roles.update', { company: tenant.id, role: role.id }),
                options,
            );
        } else {
            form.transform(({ company_id, name, permissions: granted }) => ({
                company_id,
                name,
                permissions: granted,
            }));
            form.post(route('platform.companies.roles.store', tenant.id), options);
        }
    };

    return (
        <>
            <form onSubmit={openConfirmation} className="space-y-6">
                <Alert variant="info">
                    <AlertTitle>Perbaikan oleh operator</AlertTitle>
                    <AlertDescription>
                        Perbaikan oleh operator tercatat di audit perusahaan (support).
                    </AlertDescription>
                </Alert>

                <ConflictBanner />

                {Object.keys(form.errors).filter((key) => key !== 'conflict').length > 0 ? (
                    <Alert variant="danger">
                        <AlertTitle>Peran belum dapat disimpan</AlertTitle>
                        <AlertDescription>
                            Periksa nama dan kombinasi hak akses yang ditandai.
                        </AlertDescription>
                    </Alert>
                ) : null}

                <Card>
                    <CardHeader>
                        <CardTitle>Data peran</CardTitle>
                        <CardDescription>
                            Peran ini hanya berlaku di {company.name} ({company.code}).
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-6">
                        <div className="max-w-xl">
                            <Input
                                id="platform-company-role-name"
                                label="Nama"
                                value={form.data.name}
                                maxLength={255}
                                hint="Nama harus unik di perusahaan ini."
                                error={form.errors.name ?? form.errors.company_id}
                                onChange={(event) => form.setData('name', event.target.value)}
                                required
                            />
                        </div>
                        <div className="space-y-2">
                            <p className="text-sm text-muted-foreground">
                                Peran tanpa hak mengelola peran tidak boleh menggabungkan
                                persetujuan dan pelaksanaan tugas yang saling mengawasi.
                            </p>
                            <PermissionGroupSelector
                                permissions={permissions}
                                value={form.data.permissions}
                                onChange={(selected) => form.setData('permissions', selected)}
                                error={permissionError}
                            />
                        </div>
                    </CardContent>
                </Card>

                <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                    <Button asChild variant="outline">
                        <Link href={backUrl}>Batal</Link>
                    </Button>
                    <Button type="submit" loading={processing}>
                        {submitLabel}
                    </Button>
                </div>
            </form>

            {confirming ? (
                <ConfirmActionDialog
                    title="Konfirmasi perbaikan peran"
                    description={`Perubahan ini langsung berlaku bagi pengguna ${company.name}. Masukkan kata sandi operator untuk melanjutkan.`}
                    confirmLabel={submitLabel}
                    processing={processing}
                    errors={{}}
                    onConfirmed={submitRole}
                    onClose={() => setConfirming(false)}
                />
            ) : null}
        </>
    );
}

interface DeleteCompanyRoleProps {
    tenant: PlatformTenantOption;
    company: PlatformTenantOption;
    role: PlatformCompanyRole;
    moveTargets: { id: string; name: string }[];
}

/** Deleting a role that people hold needs a role to move them to, chosen before the step-up. */
export function DeleteCompanyRole({ tenant, company, role, moveTargets }: DeleteCompanyRoleProps) {
    const [confirming, setConfirming] = useState(false);
    const [moveTo, setMoveTo] = useState('');
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<Record<string, string | undefined>>({});
    const inFlight = useRef(false);
    const needsTarget = role.users_count > 0;
    const options = useMemo(
        () => moveTargets.map((target) => ({ value: target.id, label: target.name })),
        [moveTargets],
    );
    const stuck = needsTarget && options.length === 0;

    const remove = () => {
        if (inFlight.current) return;

        inFlight.current = true;
        setProcessing(true);
        setErrors({});
        router.delete(
            route('platform.companies.roles.destroy', { company: tenant.id, role: role.id }),
            {
                data: needsTarget ? { move_to: moveTo } : {},
                preserveScroll: true,
                onError: (visitErrors) => {
                    setErrors(visitErrors);
                    if (visitErrors.move_to) setConfirming(false);
                },
                onFinish: () => {
                    inFlight.current = false;
                    setProcessing(false);
                },
            },
        );
    };

    return (
        <>
            <Card>
                <CardHeader>
                    <CardTitle>Hapus peran</CardTitle>
                    <CardDescription>
                        {needsTarget
                            ? `${formatNumber(role.users_count)} pengguna memegang peran ini. Pilih peran pengganti di ${company.code} sebelum menghapus.`
                            : 'Tidak ada pengguna yang memegang peran ini.'}
                    </CardDescription>
                </CardHeader>
                <CardContent className="space-y-4">
                    {needsTarget ? (
                        <div className="max-w-xl">
                            <SearchSelect
                                id="platform-company-role-move-to"
                                label={`Pindahkan ${formatNumber(role.users_count)} pengguna ke`}
                                value={moveTo}
                                onChange={setMoveTo}
                                options={options}
                                placeholder="Cari peran pengganti"
                                emptyText="Perusahaan ini tidak punya peran lain."
                                error={errors.move_to}
                                hint={
                                    stuck
                                        ? 'Tambah peran lain lebih dulu agar pengguna dapat dipindahkan.'
                                        : undefined
                                }
                                required
                            />
                        </div>
                    ) : null}
                    <Button
                        type="button"
                        variant="destructive-outline"
                        leftIcon={<Trash2 className="size-4" aria-hidden="true" />}
                        disabled={needsTarget && moveTo === ''}
                        onClick={() => setConfirming(true)}
                    >
                        Hapus peran
                    </Button>
                </CardContent>
            </Card>

            {confirming ? (
                <ConfirmActionDialog
                    title={`Hapus peran ${role.name}?`}
                    description={
                        needsTarget
                            ? `Pengguna dipindahkan ke ${options.find((option) => option.value === moveTo)?.label ?? 'peran pengganti'}.`
                            : `Peran ini dihapus dari ${company.name}.`
                    }
                    confirmLabel="Hapus peran"
                    tone="danger"
                    processing={processing}
                    errors={errors}
                    onConfirmed={remove}
                    onClose={() => setConfirming(false)}
                />
            ) : null}
        </>
    );
}
