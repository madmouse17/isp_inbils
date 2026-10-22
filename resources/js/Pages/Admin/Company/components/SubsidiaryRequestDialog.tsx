import { useRef } from 'react';
import type { FormEvent } from 'react';
import { useForm } from '@inertiajs/react';
import { Alert, AlertDescription, Button, Input, Modal, Textarea } from '@/Components/ui';
import { suggestCode } from '@/lib/suggestCode';

interface SubsidiaryRequestDialogProps {
    onClose: () => void;
}

/** The form of "Minta anak perusahaan": a name, a suggested code and a note for the operator who creates the company. */
export function SubsidiaryRequestDialog({ onClose }: SubsidiaryRequestDialogProps) {
    const form = useForm({ name: '', code: '', note: '' });
    // The code follows the name until the admin types one of their own; then it stays theirs.
    const codeTyped = useRef(false);
    const pendingError = (form.errors as Record<string, string | undefined>).pending;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(route('admin.company.subsidiary-requests.store'), {
            preserveScroll: true,
            onSuccess: onClose,
        });
    };

    return (
        <Modal open onClose={onClose} title="Minta anak perusahaan" size="lg">
            <form onSubmit={submit} className="space-y-5">
                <p className="text-sm text-muted-foreground">
                    Operator platform yang membuat anak perusahaan. Kirim nama dan kode yang Anda
                    inginkan, operator akan menindaklanjutinya.
                </p>
                {pendingError ? (
                    <Alert variant="danger">
                        <AlertDescription>{pendingError}</AlertDescription>
                    </Alert>
                ) : null}
                <div className="grid gap-4 sm:grid-cols-2">
                    <Input
                        id="subsidiary-request-name"
                        label="Nama"
                        value={form.data.name}
                        minLength={3}
                        maxLength={100}
                        error={form.errors.name}
                        onChange={(event) => {
                            const name = event.target.value;
                            form.setData((data) => ({
                                ...data,
                                name,
                                code: codeTyped.current ? data.code : (suggestCode(name) ?? ''),
                            }));
                        }}
                        required
                    />
                    <Input
                        id="subsidiary-request-code"
                        label="Kode usulan"
                        value={form.data.code}
                        maxLength={50}
                        pattern="[A-Za-z0-9][A-Za-z0-9_\-]*"
                        hint="Opsional. Operator bisa mengubahnya bila kode sudah dipakai."
                        error={form.errors.code}
                        onChange={(event) => {
                            codeTyped.current = event.target.value !== '';
                            form.setData('code', event.target.value);
                        }}
                    />
                </div>
                <Textarea
                    id="subsidiary-request-note"
                    label="Catatan"
                    value={form.data.note}
                    maxLength={500}
                    hint={`${form.data.note.length}/500 karakter. Tulis cabang atau wilayah yang dimaksud.`}
                    error={form.errors.note}
                    onChange={(event) => form.setData('note', event.target.value)}
                />
                <div className="flex justify-end gap-2">
                    <Button type="button" variant="outline" onClick={onClose}>
                        Batal
                    </Button>
                    <Button type="submit" loading={form.processing}>
                        Kirim permintaan
                    </Button>
                </div>
            </form>
        </Modal>
    );
}
