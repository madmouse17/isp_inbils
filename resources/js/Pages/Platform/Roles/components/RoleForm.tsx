import { useRef, useState } from 'react';
import type { FormEvent } from 'react';
import { Link, useForm } from '@inertiajs/react';
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
} from '@/Components/ui';
import { PermissionGroupSelector, type PermissionOption } from '@/Components/composite';
import { ConfirmActionDialog } from '@/Components/composite/platform/ConfirmActionDialog';
import type { PlatformRole } from '@/types/platform';

interface RoleFormProps {
    role?: PlatformRole;
    permissions: PermissionOption[];
    protectedRole?: boolean;
    seededWarning?: string | null;
}

export function RoleForm({
    role,
    permissions,
    protectedRole = false,
    seededWarning,
}: RoleFormProps) {
    const [confirming, setConfirming] = useState(false);
    const [processing, setProcessing] = useState(false);
    const inFlight = useRef(false);
    const form = useForm({
        name: role?.name ?? '',
        permissions: role?.permissions ?? ([] as string[]),
    });
    const permissionError =
        form.errors.permissions ??
        Object.entries(form.errors).find(([key]) => key.startsWith('permissions.'))?.[1];
    const submitLabel = role ? 'Simpan peran' : 'Buat peran';

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
            form.put(route('platform.roles.update', role.id), options);
        } else {
            form.post(route('platform.roles.store'), options);
        }
    };

    return (
        <>
            <form onSubmit={openConfirmation} className="space-y-6">
                {seededWarning ? (
                    <Alert variant="warning">
                        <AlertTitle>Peran bawaan</AlertTitle>
                        <AlertDescription>{seededWarning}</AlertDescription>
                    </Alert>
                ) : null}

                {Object.keys(form.errors).length > 0 ? (
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
                            Perubahan berlaku untuk pengguna di semua perusahaan.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-6">
                        <div className="max-w-xl">
                            <Input
                                id="platform-role-name"
                                label="Nama"
                                value={form.data.name}
                                maxLength={255}
                                readOnly={protectedRole}
                                hint={
                                    protectedRole
                                        ? 'Nama peran bawaan tidak dapat diubah.'
                                        : 'Gunakan nama yang jelas bagi operator dan admin perusahaan.'
                                }
                                error={form.errors.name}
                                onChange={(event) => form.setData('name', event.target.value)}
                                required
                            />
                        </div>
                        <div className="space-y-2">
                            <p className="text-sm text-muted-foreground">
                                Peran non-admin tidak boleh menggabungkan persetujuan dan
                                pelaksanaan tugas yang saling mengawasi.
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
                        <Link href={route('platform.roles.index')}>Batal</Link>
                    </Button>
                    <Button type="submit" loading={processing}>
                        {submitLabel}
                    </Button>
                </div>
            </form>

            {confirming ? (
                <ConfirmActionDialog
                    title="Konfirmasi perubahan peran"
                    description="Definisi peran berlaku di semua perusahaan. Masukkan kata sandi operator untuk melanjutkan."
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
