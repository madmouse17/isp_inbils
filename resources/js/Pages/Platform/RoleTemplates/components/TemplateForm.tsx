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
import type { PlatformRoleTemplate } from '@/types/platform';

interface TemplateFormProps {
    template?: PlatformRoleTemplate;
    permissions: PermissionOption[];
}

export function TemplateForm({ template, permissions }: TemplateFormProps) {
    const [confirming, setConfirming] = useState(false);
    const [processing, setProcessing] = useState(false);
    const inFlight = useRef(false);
    const form = useForm({
        name: template?.name ?? '',
        permissions: template?.permissions ?? ([] as string[]),
    });
    const permissionError =
        form.errors.permissions ??
        Object.entries(form.errors).find(([key]) => key.startsWith('permissions.'))?.[1];
    const submitLabel = template ? 'Simpan templat' : 'Buat templat';

    const openConfirmation = (event: FormEvent) => {
        event.preventDefault();
        form.clearErrors();
        setConfirming(true);
    };

    const submitTemplate = () => {
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

        if (template) {
            form.put(route('platform.role-templates.update', template.id), options);
        } else {
            form.post(route('platform.role-templates.store'), options);
        }
    };

    return (
        <>
            <form onSubmit={openConfirmation} className="space-y-6">
                <Alert variant="info">
                    <AlertTitle>Berlaku untuk perusahaan baru</AlertTitle>
                    <AlertDescription>
                        Perubahan templat hanya berlaku untuk perusahaan baru; peran perusahaan yang
                        sudah ada tidak berubah.
                    </AlertDescription>
                </Alert>

                {Object.keys(form.errors).length > 0 ? (
                    <Alert variant="danger">
                        <AlertTitle>Templat belum dapat disimpan</AlertTitle>
                        <AlertDescription>
                            Periksa nama dan kombinasi hak akses yang ditandai.
                        </AlertDescription>
                    </Alert>
                ) : null}

                <Card>
                    <CardHeader>
                        <CardTitle>Data templat</CardTitle>
                        <CardDescription>
                            Nama ini tampil sebagai peran di setiap perusahaan baru.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-6">
                        <div className="max-w-xl">
                            <Input
                                id="platform-template-name"
                                label="Nama"
                                value={form.data.name}
                                maxLength={255}
                                hint="Gunakan nama yang jelas bagi admin perusahaan."
                                error={form.errors.name}
                                onChange={(event) => form.setData('name', event.target.value)}
                                required
                            />
                        </div>
                        <div className="space-y-2">
                            <p className="text-sm text-muted-foreground">
                                Templat tanpa hak mengelola peran tidak boleh menggabungkan
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
                        <Link href={route('platform.role-templates.index')}>Batal</Link>
                    </Button>
                    <Button type="submit" loading={processing}>
                        {submitLabel}
                    </Button>
                </div>
            </form>

            {confirming ? (
                <ConfirmActionDialog
                    title="Konfirmasi perubahan templat"
                    description="Templat dipakai untuk perusahaan baru. Masukkan kata sandi operator untuk melanjutkan."
                    confirmLabel={submitLabel}
                    processing={processing}
                    errors={{}}
                    onConfirmed={submitTemplate}
                    onClose={() => setConfirming(false)}
                />
            ) : null}
        </>
    );
}
