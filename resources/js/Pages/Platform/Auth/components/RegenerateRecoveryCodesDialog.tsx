import { useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import {
    ConfirmActionDialog,
    type PlatformConfirmation,
} from '@/Components/composite/platform/ConfirmActionDialog';

interface RegenerateRecoveryCodesDialogProps {
    onClose: () => void;
    onFlash: (flash: Record<string, unknown>) => void;
}

export function RegenerateRecoveryCodesDialog({
    onClose,
    onFlash,
}: RegenerateRecoveryCodesDialogProps) {
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<Record<string, string | undefined>>({});
    const inFlight = useRef(false);

    const submit = (_confirmation: PlatformConfirmation) => {
        if (inFlight.current) return;

        inFlight.current = true;
        setProcessing(true);
        setErrors({});
        router.post(
            route('platform.two-factor.recovery-codes'),
            {},
            {
                preserveScroll: true,
                onFlash: (flash) => onFlash(flash as Record<string, unknown>),
                onError: (visitErrors) =>
                    setErrors(visitErrors as Record<string, string | undefined>),
                onSuccess: onClose,
                onFinish: () => {
                    inFlight.current = false;
                    setProcessing(false);
                },
            },
        );
    };

    return (
        <ConfirmActionDialog
            title="Buat kode pemulihan baru?"
            description="Kode lama langsung tidak berlaku. Simpan kode baru sebelum meninggalkan halaman."
            confirmLabel="Buat kode baru"
            processing={processing}
            errors={errors}
            onConfirmed={submit}
            onClose={onClose}
        />
    );
}
