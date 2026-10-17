import type { FormEvent } from 'react';
import { useState } from 'react';
import { useForm } from '@inertiajs/react';
import { Button, Modal } from '@/Components/ui';
import { DropPortSelect } from '@/Components/composite/odn/DropPortSelect';
import type { DropPortOption } from '@/types/ipam-odn';

interface AssignDropDialogProps {
    onClose: () => void;
    subscription: { id: string; code: string };
    /** True when a drop already exists: the port then moves instead of being set. */
    moving: boolean;
    options: DropPortOption[] | undefined;
    unavailable?: string;
}

/** Mount it only while open, so each opening starts with no port chosen and no stale errors. */
export function AssignDropDialog({
    onClose,
    subscription,
    moving,
    options,
    unavailable,
}: AssignDropDialogProps) {
    const [portId, setPortId] = useState('');
    const { post, errors, processing } = useForm({ subscription_id: subscription.id });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post(route('admin.odn.drops.store', portId), {
            preserveScroll: true,
            onSuccess: onClose,
        });
    };

    return (
        <Modal
            open
            onClose={onClose}
            title={`${moving ? 'Pindahkan' : 'Atur'} port drop ${subscription.code}`}
        >
            <form onSubmit={submit} className="space-y-4">
                <DropPortSelect
                    id="assign-drop-port"
                    value={portId}
                    onChange={setPortId}
                    options={options}
                    unavailable={unavailable}
                    error={
                        (errors as Record<string, string | undefined>).port ??
                        errors.subscription_id
                    }
                />
                <div className="flex justify-end gap-2">
                    <Button type="button" variant="secondary" onClick={onClose}>
                        Batal
                    </Button>
                    <Button
                        type="submit"
                        variant="primary"
                        loading={processing}
                        disabled={portId === ''}
                    >
                        {moving ? 'Pindahkan port drop' : 'Atur port drop'}
                    </Button>
                </div>
            </form>
        </Modal>
    );
}
