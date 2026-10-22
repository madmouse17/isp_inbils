import type { FormEvent } from 'react';
import { useForm } from '@inertiajs/react';
import { Alert, AlertDescription, Button, Checkbox, Input, Modal } from '@/Components/ui';
import { suggestCode } from '@/lib/suggestCode';
import type {
    PlatformCompanyUser,
    PlatformSubsidiaryRequest,
    PlatformTenantProfile,
} from '@/types/platform';

interface SubsidiaryDialogProps {
    tenant: PlatformTenantProfile;
    admins: PlatformCompanyUser[];
    /** The tenant's request this subsidiary answers: its name and code fill the form, and creating the subsidiary closes it. */
    request?: PlatformSubsidiaryRequest;
    onClose: () => void;
}

export function SubsidiaryDialog({ tenant, admins, request, onClose }: SubsidiaryDialogProps) {
    const form = useForm({
        name: request?.name ?? '',
        code: request?.code ?? (request ? (suggestCode(request.name) ?? '') : ''),
        member_ids: [] as string[],
        subsidiary_request_id: request?.id ?? '',
    });
    const formErrors = form.errors as Record<string, string | undefined>;
    const refusal = formErrors.lifecycle ?? formErrors.subsidiary_request_id;
    const memberError =
        form.errors.member_ids ??
        Object.entries(form.errors).find(([key]) => key.startsWith('member_ids.'))?.[1];

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(route('platform.companies.subsidiaries.store', tenant.id), {
            preserveScroll: true,
            onSuccess: onClose,
        });
    };

    return (
        <Modal
            open
            onClose={onClose}
            title={request ? 'Buat anak perusahaan dari permintaan' : 'Tambah anak perusahaan'}
            size="lg"
        >
            <form onSubmit={submit} className="space-y-5">
                {request ? (
                    <div className="rounded-md border border-border bg-muted/40 p-3 text-sm">
                        <p className="font-medium">
                            Permintaan dari {request.requester?.name ?? 'admin perusahaan'}
                        </p>
                        {request.note ? (
                            <p className="mt-1 break-words whitespace-pre-line text-muted-foreground">
                                {request.note}
                            </p>
                        ) : null}
                        <p className="mt-1 text-muted-foreground">
                            Permintaan ini ditutup begitu anak perusahaannya dibuat. Nama dan kode
                            boleh diubah.
                        </p>
                    </div>
                ) : null}
                <p className="text-sm text-muted-foreground">
                    Mata uang dan zona waktu mengikuti perusahaan induk.
                </p>
                {refusal ? (
                    <Alert variant="danger">
                        <AlertDescription>{refusal}</AlertDescription>
                    </Alert>
                ) : null}
                <div className="grid gap-4 sm:grid-cols-2">
                    <Input
                        id="subsidiary-name"
                        label="Nama"
                        value={form.data.name}
                        maxLength={255}
                        error={form.errors.name}
                        onChange={(event) => form.setData('name', event.target.value)}
                        required
                    />
                    <Input
                        id="subsidiary-code"
                        label="Kode"
                        value={form.data.code}
                        maxLength={50}
                        pattern="[A-Za-z0-9][A-Za-z0-9_\-]*"
                        hint="Huruf, angka, strip, atau garis bawah."
                        error={form.errors.code}
                        onChange={(event) => form.setData('code', event.target.value)}
                        required
                    />
                </div>
                {admins.length > 0 ? (
                    <fieldset className="space-y-3 rounded-lg border border-border p-4">
                        <legend className="px-1 text-sm font-semibold">Akses admin</legend>
                        <p className="text-sm text-muted-foreground">
                            Admin yang dipilih dapat berpindah ke anak perusahaan baru.
                        </p>
                        <div className="grid gap-2 sm:grid-cols-2">
                            {admins.map((admin) => (
                                <Checkbox
                                    key={admin.id}
                                    id={`subsidiary-member-${admin.id}`}
                                    label={admin.name}
                                    description={`${admin.email} · ${admin.company.code}`}
                                    checked={form.data.member_ids.includes(admin.id)}
                                    onCheckedChange={(checked) =>
                                        form.setData(
                                            'member_ids',
                                            checked === true
                                                ? [...form.data.member_ids, admin.id]
                                                : form.data.member_ids.filter(
                                                      (id) => id !== admin.id,
                                                  ),
                                        )
                                    }
                                />
                            ))}
                        </div>
                        {memberError ? (
                            <p className="text-sm text-destructive-text">{memberError}</p>
                        ) : null}
                    </fieldset>
                ) : null}
                <div className="flex justify-end gap-2">
                    <Button type="button" variant="outline" onClick={onClose}>
                        Batal
                    </Button>
                    <Button type="submit" loading={form.processing}>
                        {request ? 'Buat anak perusahaan' : 'Tambah anak perusahaan'}
                    </Button>
                </div>
            </form>
        </Modal>
    );
}
