import type { FormEvent } from 'react';
import { useForm } from '@inertiajs/react';
import { Alert, AlertDescription, Button, Input, Modal } from '@/Components/ui';

interface PonPortsDialogProps {
    onClose: () => void;
    /** The OLT that gets the ports. */
    olt: { id: number; code: string };
}

/** Mount it only while open, so each opening starts with the default count and no stale errors. */
export function PonPortsDialog({ onClose, olt }: PonPortsDialogProps) {
    const { data, setData, post, errors, processing } = useForm({ count: '1' });
    // The service refuses a host that is not an OLT under `asset`, which is not a field of this form.
    const assetError = (errors as Record<string, string | undefined>).asset;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post(route('admin.odn.pon-ports.store', olt.id), {
            preserveScroll: true,
            onSuccess: onClose,
        });
    };

    return (
        <Modal open onClose={onClose} title={`Tambah port PON ke ${olt.code}`} size="sm">
            <form onSubmit={submit} className="space-y-4">
                {assetError && (
                    <Alert variant="danger">
                        <AlertDescription>{assetError}</AlertDescription>
                    </Alert>
                )}
                <Input
                    id="pon-count"
                    label="Jumlah port"
                    type="number"
                    min={1}
                    max={64}
                    value={data.count}
                    onChange={(event) => setData('count', event.target.value)}
                    error={errors.count}
                    hint="Port baru dinomori setelah nomor tertinggi yang ada."
                    required
                />
                <div className="flex justify-end gap-2">
                    <Button type="button" variant="secondary" onClick={onClose}>
                        Batal
                    </Button>
                    <Button type="submit" variant="primary" loading={processing}>
                        Tambah port
                    </Button>
                </div>
            </form>
        </Modal>
    );
}
