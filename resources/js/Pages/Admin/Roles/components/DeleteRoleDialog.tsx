import { useMemo, useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import { Alert, AlertDescription, Button, Modal, SearchSelect } from '@/Components/ui';
import { formatNumber } from '@/lib/format';

interface DeleteRoleDialogProps {
    role: { id: string; name: string; users_count?: number };
    /** Every role of the company; the role itself is left out here. */
    moveTargets: { id: string; name: string }[];
    onClose: () => void;
}

/**
 * Deletes a role of the company. A role that people hold needs a role to move them to, chosen here; the server
 * decides whether that is allowed and answers on `move_to` (no or a bad target), `roles` (the company would lose
 * its last administrator) or `role` (the role of customer accounts stays). The refusal stays in the dialog, next to
 * the choice. Mount it only while a role is pending, keyed by the role, so each opens without the last refusal.
 */
export function DeleteRoleDialog({ role, moveTargets, onClose }: DeleteRoleDialogProps) {
    const usersCount = role.users_count ?? 0;
    const needsTarget = usersCount > 0;
    const [moveTo, setMoveTo] = useState('');
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<Record<string, string | undefined>>({});
    // Synchronous guard against a same-tick double click, before React renders the disabled state.
    const inFlight = useRef(false);
    const options = useMemo(
        () =>
            moveTargets
                .filter((target) => target.id !== role.id)
                .map((target) => ({ value: target.id, label: target.name })),
        [moveTargets, role.id],
    );
    const noTarget = needsTarget && options.length === 0;
    const refusal = errors.roles ?? errors.role;

    const remove = () => {
        if (inFlight.current) return;

        inFlight.current = true;
        setProcessing(true);
        setErrors({});
        router.delete(route('admin.roles.destroy', role.id), {
            data: needsTarget ? { move_to: moveTo } : {},
            preserveScroll: true,
            onSuccess: onClose,
            onError: setErrors,
            onFinish: () => {
                inFlight.current = false;
                setProcessing(false);
            },
        });
    };

    return (
        <Modal
            open
            title={`Hapus peran ${role.name}?`}
            onClose={() => {
                if (!processing) onClose();
            }}
        >
            <div className="space-y-4">
                <p className="text-sm text-muted-foreground">
                    {needsTarget
                        ? `${formatNumber(usersCount)} pengguna memegang peran ini. Mereka mendapat peran yang Anda pilih dan kehilangan peran ini. Penghapusan tidak bisa dibatalkan.`
                        : 'Tidak ada pengguna yang memegang peran ini. Penghapusan tidak bisa dibatalkan.'}
                </p>

                {refusal ? (
                    <Alert variant="danger">
                        <AlertDescription>{refusal}</AlertDescription>
                    </Alert>
                ) : null}

                {needsTarget ? (
                    <SearchSelect
                        id="delete-role-move-to"
                        label={`Pindahkan ${formatNumber(usersCount)} pengguna ke`}
                        value={moveTo}
                        onChange={setMoveTo}
                        options={options}
                        placeholder="Cari peran tujuan"
                        emptyText="Perusahaan ini tidak punya peran lain."
                        error={errors.move_to}
                        hint={
                            noTarget
                                ? 'Buat peran lain lebih dulu agar pengguna bisa dipindahkan.'
                                : moveTo === ''
                                  ? 'Pilih peran tujuan untuk mengaktifkan tombol Hapus peran.'
                                  : undefined
                        }
                        disabled={processing}
                        required
                    />
                ) : null}

                <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                    <Button type="button" variant="outline" disabled={processing} onClick={onClose}>
                        Batal
                    </Button>
                    <Button
                        type="button"
                        variant="destructive"
                        loading={processing}
                        disabled={needsTarget && moveTo === ''}
                        onClick={remove}
                    >
                        Hapus peran
                    </Button>
                </div>
            </div>
        </Modal>
    );
}
