import type { FormEvent } from 'react';
import { useForm } from '@inertiajs/react';
import { Button, Input, Modal } from '@/Components/ui';

interface ReserveAddressDialogProps {
    onClose: () => void;
    prefix: { id: number; cidr: string };
}

/** Mount it only while open, so each opening starts empty with no stale errors. */
export function ReserveAddressDialog({ onClose, prefix }: ReserveAddressDialogProps) {
    const { data, setData, post, errors, processing } = useForm({ address: '', note: '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post(route('admin.ipam.reservations.store', prefix.id), {
            preserveScroll: true,
            onSuccess: onClose,
        });
    };

    return (
        <Modal open onClose={onClose} title={`Reserve an address in ${prefix.cidr}`}>
            <form onSubmit={submit} className="space-y-4">
                <Input
                    id="reservation-address"
                    label="Address"
                    value={data.address}
                    onChange={(event) => setData('address', event.target.value)}
                    error={errors.address}
                    hint="A reserved address is never handed to a customer."
                    autoComplete="off"
                    spellCheck={false}
                    required
                />
                <Input
                    id="reservation-note"
                    label="Note"
                    value={data.note}
                    onChange={(event) => setData('note', event.target.value)}
                    error={errors.note}
                    placeholder="Gateway, router loopback…"
                    maxLength={255}
                />
                <div className="flex justify-end gap-2">
                    <Button type="button" variant="secondary" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button type="submit" variant="success" loading={processing}>
                        Reserve address
                    </Button>
                </div>
            </form>
        </Modal>
    );
}
