import { useEffect, useState } from 'react';
import type { FormEvent } from 'react';
import axios, { isAxiosError } from 'axios';
import { router, usePage } from '@inertiajs/react';
import type { PageProps } from '@/types';
import {
    AlertDialog,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
    Button,
    Input,
    useToast,
} from '@/Components/ui';
import { formatCountdown, secondsLeft } from './impersonationCountdown';

/** Shared Inertia prop `impersonation` (ActiveImpersonation::toProp); null outside a support session. */
export type ImpersonationProp = {
    admin_name: string;
    user_name: string;
    company_name: string;
    company_code: string;
    mode: 'read' | 'write';
    expires_at: string;
    reason: string;
    write_url: string;
    read_url: string;
    stop_url: string;
    confirm_url: string;
};

type ValidationBody = { message?: string; errors?: { confirmation_code?: string[] } };
// shortcut: counts down on the browser clock; a skewed clock shifts the display only, the server still ends the session on time.
function useCountdown(expiresAt: string): number {
    const [remaining, setRemaining] = useState(() => secondsLeft(expiresAt, Date.now()));

    useEffect(() => {
        // the first tick runs from the interval; the initial state already holds the current value
        const timer = window.setInterval(
            () => setRemaining(secondsLeft(expiresAt, Date.now())),
            1000,
        );

        return () => window.clearInterval(timer);
    }, [expiresAt]);

    return remaining;
}

function WriteModeDialog({
    impersonation,
    onClose,
}: {
    impersonation: ImpersonationProp;
    onClose: () => void;
}) {
    const { toast } = useToast();
    const [code, setCode] = useState('');
    const [error, setError] = useState<string>();
    const [busy, setBusy] = useState(false);

    const submit = async (event: FormEvent) => {
        event.preventDefault();
        if (busy || code === '') return;

        setBusy(true);
        setError(undefined);

        try {
            await axios.post(
                impersonation.write_url,
                { confirmation_code: code },
                { headers: { Accept: 'application/json' } },
            );
            toast({ title: 'Mode ubah diaktifkan.', variant: 'success' });
            onClose();
            router.reload();
        } catch (failure) {
            if (isAxiosError<ValidationBody>(failure) && failure.response?.status === 423) {
                // Operator password step-up lives on the platform shell: go there, it returns to this page.
                window.location.assign(impersonation.confirm_url);

                return;
            }

            const body = isAxiosError<ValidationBody>(failure) ? failure.response?.data : undefined;
            const message = body?.errors?.confirmation_code?.[0] ?? body?.message;
            if (body?.errors?.confirmation_code) {
                setError(message);
            } else {
                toast({
                    title: message ?? 'Mode ubah gagal diaktifkan.',
                    variant: 'danger',
                });
            }
        } finally {
            setBusy(false);
        }
    };

    return (
        <AlertDialog
            open
            onOpenChange={(open) => {
                if (!open && !busy) onClose();
            }}
        >
            <AlertDialogContent>
                <form onSubmit={(event) => void submit(event)} className="space-y-5">
                    <AlertDialogHeader>
                        <AlertDialogTitle>Aktifkan mode ubah</AlertDialogTitle>
                        <AlertDialogDescription>
                            Perubahan yang Anda buat sebagai {impersonation.user_name} mengubah data
                            nyata {impersonation.company_name} dan dicatat di audit perusahaan.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <Input
                        id="impersonation-confirmation-code"
                        label={`Ketik ${impersonation.company_code} untuk melanjutkan`}
                        value={code}
                        autoComplete="off"
                        autoCapitalize="none"
                        autoCorrect="off"
                        spellCheck={false}
                        hint="Huruf besar dan kecil harus sama."
                        error={error}
                        onChange={(event) => setCode(event.target.value)}
                        required
                    />
                    <AlertDialogFooter>
                        <AlertDialogCancel disabled={busy}>Batal</AlertDialogCancel>
                        <Button type="submit" loading={busy} disabled={code === ''}>
                            Aktifkan mode ubah
                        </Button>
                    </AlertDialogFooter>
                </form>
            </AlertDialogContent>
        </AlertDialog>
    );
}

export function ImpersonationBanner() {
    const { impersonation } =
        usePage<PageProps<{ impersonation?: ImpersonationProp | null }>>().props;

    if (!impersonation) return null;

    return <ActiveBanner key={impersonation.expires_at} impersonation={impersonation} />;
}

function ActiveBanner({ impersonation }: { impersonation: ImpersonationProp }) {
    const [confirming, setConfirming] = useState(false);
    const [busy, setBusy] = useState(false);
    const remaining = useCountdown(impersonation.expires_at);
    const writable = impersonation.mode === 'write';

    useEffect(() => {
        // The server ends an expired session on the next request and sends the operator back to the panel.
        if (remaining === 0) router.reload();
    }, [remaining]);

    return (
        <div
            role="region"
            aria-label="Akses dukungan"
            className="flex shrink-0 flex-wrap items-center gap-x-4 gap-y-2 border-b border-amber-300 bg-amber-100 px-4 py-2 text-sm text-amber-950 md:px-6 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-100"
        >
            <p className="min-w-0 flex-1">
                Anda masuk sebagai <strong>{impersonation.user_name}</strong> (
                {impersonation.company_name}) · superadmin {impersonation.admin_name} · mode{' '}
                <strong>{writable ? 'Ubah' : 'Baca'}</strong> · sisa{' '}
                <span role="timer" aria-live="off" className="font-semibold tabular-nums">
                    {formatCountdown(remaining)}
                </span>
            </p>
            <div className="flex flex-wrap items-center gap-2">
                {writable ? (
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        loading={busy}
                        onClick={() => {
                            setBusy(true);
                            router.post(
                                impersonation.read_url,
                                {},
                                { preserveScroll: true, onFinish: () => setBusy(false) },
                            );
                        }}
                    >
                        Mode baca
                    </Button>
                ) : (
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() => setConfirming(true)}
                    >
                        Mode ubah
                    </Button>
                )}
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={() => router.post(impersonation.stop_url)}
                >
                    Keluar
                </Button>
            </div>
            {confirming ? (
                <WriteModeDialog
                    impersonation={impersonation}
                    onClose={() => setConfirming(false)}
                />
            ) : null}
        </div>
    );
}
