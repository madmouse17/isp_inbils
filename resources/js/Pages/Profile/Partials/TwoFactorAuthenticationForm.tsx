import {
    Alert,
    AlertDescription,
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
    AlertTitle,
    Badge,
    Button,
    Input,
} from '@/Components/ui';
import { useForm } from '@inertiajs/react';
import { Copy, Download, ShieldAlert, ShieldCheck, ShieldOff } from 'lucide-react';
import type { FormEventHandler } from 'react';
import { useState } from 'react';

export interface TwoFactorStatus {
    enabled: boolean;
    pending: boolean;
    recovery_codes_remaining: number;
}

interface SetupData {
    setup_key: string;
    qr_svg: string;
}

interface TwoFactorFlash {
    setup?: SetupData;
    recovery_codes?: string[];
}

function downloadCodes(codes: string[]) {
    const url = URL.createObjectURL(new Blob([`${codes.join('\n')}\n`], { type: 'text/plain' }));
    const link = document.createElement('a');
    link.href = url;
    link.download = 'inbils-recovery-codes.txt';
    link.click();
    URL.revokeObjectURL(url);
}

export default function TwoFactorAuthenticationForm({
    twoFactor,
    className = '',
}: {
    twoFactor: TwoFactorStatus;
    className?: string;
}) {
    // The setup key and recovery codes arrive as flash data, in no prop, so they are kept here until
    // dismissed or replaced. A failed confirmation reloads the page without them but keeps this
    // component, so the QR code the user is scanning stays on screen.
    const [setup, setSetup] = useState<SetupData | null>(null);
    const [recoveryCodes, setRecoveryCodes] = useState<string[] | null>(null);
    const [copied, setCopied] = useState(false);
    const [confirmingDisable, setConfirmingDisable] = useState(false);

    const enable = useForm({});
    const regenerate = useForm({});
    const disable = useForm({});
    const confirm = useForm({ code: '' });

    // Flash data lives for one response, outside the props and the browser history state.
    const takeFlash = (flash: Record<string, unknown>) => {
        // Inertia types flash data as unknown; the shape is fixed by TwoFactorAuthenticationController.
        const sent = flash.two_factor as TwoFactorFlash | undefined;
        const { setup: newSetup, recovery_codes: newCodes } = sent ?? {};

        if (newSetup) {
            setSetup(newSetup);
            setRecoveryCodes(null);
        }

        if (newCodes) {
            setRecoveryCodes(newCodes);
            setSetup(null);
            setCopied(false);
        }
    };

    const startSetup = () =>
        enable.post(route('two-factor.enable'), { preserveScroll: true, onFlash: takeFlash });

    const confirmSetup: FormEventHandler = (e) => {
        e.preventDefault();

        confirm.post(route('two-factor.confirm'), {
            preserveScroll: true,
            onFlash: takeFlash,
            onSuccess: () => confirm.reset('code'),
        });
    };

    const turnOff = () =>
        disable.delete(route('two-factor.disable'), {
            preserveScroll: true,
            onSuccess: () => {
                setSetup(null);
                setRecoveryCodes(null);
            },
            onFinish: () => setConfirmingDisable(false),
        });

    const copyCodes = async (codes: string[]) => {
        await navigator.clipboard.writeText(codes.join('\n'));
        setCopied(true);
    };

    const showSetup = twoFactor.pending && setup !== null;
    const showCodes = twoFactor.enabled && recoveryCodes !== null;

    return (
        <section className={`space-y-6 ${className}`}>
            <header className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 className="text-lg font-medium text-foreground">
                        Two-Factor Authentication
                    </h2>

                    <p className="mt-1 max-w-xl text-sm text-muted-foreground">
                        Ask for a code from an authenticator app after your password, so a stolen
                        password is not enough to log in.
                    </p>
                </div>

                {twoFactor.enabled ? (
                    <Badge variant="success">
                        <ShieldCheck className="size-3.5" aria-hidden="true" />
                        On
                    </Badge>
                ) : twoFactor.pending ? (
                    <Badge variant="warning">
                        <ShieldAlert className="size-3.5" aria-hidden="true" />
                        Setup not finished
                    </Badge>
                ) : (
                    <Badge variant="secondary">
                        <ShieldOff className="size-3.5" aria-hidden="true" />
                        Off
                    </Badge>
                )}
            </header>

            {!twoFactor.enabled && !twoFactor.pending ? (
                <div className="space-y-3">
                    <Button type="button" onClick={startSetup} loading={enable.processing}>
                        Enable two-factor authentication
                    </Button>
                    <p className="text-sm text-muted-foreground">
                        You may be asked to confirm your password first.
                    </p>
                </div>
            ) : null}

            {twoFactor.pending && !showSetup ? (
                <div className="space-y-4">
                    <Alert variant="warning">
                        <AlertTitle>Setup not finished</AlertTitle>
                        <AlertDescription>
                            The QR code is shown only once, so this setup cannot be resumed. Start
                            again to get a new one.
                        </AlertDescription>
                    </Alert>
                    <div className="flex flex-wrap gap-3">
                        <Button type="button" onClick={startSetup} loading={enable.processing}>
                            Start again
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={turnOff}
                            loading={disable.processing}
                        >
                            Cancel setup
                        </Button>
                    </div>
                </div>
            ) : null}

            {showSetup && setup ? (
                <div className="grid gap-6 md:grid-cols-[auto_1fr]">
                    <img
                        src={`data:image/svg+xml;charset=utf-8,${encodeURIComponent(setup.qr_svg)}`}
                        alt="QR code for the setup key below"
                        className="size-48 rounded-lg border border-border bg-white"
                    />

                    <div className="space-y-5">
                        <ol className="space-y-2 list-decimal pl-5 text-sm text-foreground">
                            <li>Scan the QR code with an authenticator app on your phone.</li>
                            <li>
                                Cannot scan? Enter this setup key in the app instead:
                                <code className="mt-1 block rounded-md bg-muted px-2 py-1 font-mono text-sm break-all select-all">
                                    {setup.setup_key.match(/.{1,4}/g)?.join(' ')}
                                </code>
                            </li>
                            <li>Type the 6-digit code the app shows to finish.</li>
                        </ol>

                        <form onSubmit={confirmSetup} className="space-y-4 max-w-xs">
                            <Input
                                id="two_factor_confirm_code"
                                label="Authentication code"
                                name="code"
                                inputMode="numeric"
                                autoComplete="one-time-code"
                                autoFocus
                                value={confirm.data.code}
                                error={confirm.errors.code}
                                onChange={(e) => confirm.setData('code', e.target.value)}
                            />
                            <div className="flex flex-wrap gap-3">
                                <Button type="submit" loading={confirm.processing}>
                                    Confirm and turn on
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={turnOff}
                                    loading={disable.processing}
                                >
                                    Cancel setup
                                </Button>
                            </div>
                        </form>
                    </div>
                </div>
            ) : null}

            {showCodes && recoveryCodes ? (
                <div className="space-y-4 rounded-lg border border-border bg-muted/40 p-4">
                    <div>
                        <h3 className="text-base font-medium text-foreground">
                            Your recovery codes
                        </h3>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Save them somewhere safe, such as a password manager. Each code works
                            once, and they are not shown again.
                        </p>
                    </div>

                    <ul
                        aria-label="Recovery codes"
                        className="grid gap-2 font-mono text-sm text-foreground sm:grid-cols-2"
                    >
                        {recoveryCodes.map((code) => (
                            <li
                                key={code}
                                className="rounded-md bg-background px-3 py-1 select-all"
                            >
                                {code}
                            </li>
                        ))}
                    </ul>

                    <div className="flex flex-wrap items-center gap-3">
                        {typeof navigator !== 'undefined' && 'clipboard' in navigator ? (
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                leftIcon={<Copy className="size-4" aria-hidden="true" />}
                                onClick={() => void copyCodes(recoveryCodes)}
                            >
                                Copy
                            </Button>
                        ) : null}
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            leftIcon={<Download className="size-4" aria-hidden="true" />}
                            onClick={() => downloadCodes(recoveryCodes)}
                        >
                            Download as text
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            onClick={() => {
                                setRecoveryCodes(null);
                                setCopied(false);
                            }}
                        >
                            I have saved these codes
                        </Button>
                        <p role="status" className="text-sm text-muted-foreground">
                            {copied ? 'Copied to the clipboard.' : ''}
                        </p>
                    </div>
                </div>
            ) : null}

            {twoFactor.enabled ? (
                <div className="space-y-4">
                    <p className="text-sm text-muted-foreground">
                        {twoFactor.recovery_codes_remaining === 1
                            ? '1 recovery code left.'
                            : `${twoFactor.recovery_codes_remaining} recovery codes left.`}{' '}
                        Generating new codes makes the old ones stop working.
                    </p>
                    <div className="flex flex-wrap gap-3">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() =>
                                regenerate.post(route('two-factor.recovery-codes'), {
                                    preserveScroll: true,
                                    onFlash: takeFlash,
                                })
                            }
                            loading={regenerate.processing}
                        >
                            Generate new recovery codes
                        </Button>
                        <Button
                            type="button"
                            variant="danger"
                            onClick={() => setConfirmingDisable(true)}
                        >
                            Turn off two-factor authentication
                        </Button>
                    </div>
                    <p className="text-sm text-muted-foreground">
                        These actions may ask you to confirm your password first.
                    </p>
                </div>
            ) : null}

            <AlertDialog open={confirmingDisable} onOpenChange={setConfirmingDisable}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Turn off two-factor authentication?</AlertDialogTitle>
                        <AlertDialogDescription>
                            Your account will be protected by your password alone, and your recovery
                            codes will stop working.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel type="button" disabled={disable.processing}>
                            Keep it on
                        </AlertDialogCancel>
                        <AlertDialogAction
                            type="button"
                            disabled={disable.processing}
                            className="bg-destructive text-destructive-foreground shadow-xs hover:bg-destructive/90"
                            onClick={(e) => {
                                e.preventDefault();
                                turnOff();
                            }}
                        >
                            {disable.processing ? 'Turning off…' : 'Turn off'}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </section>
    );
}
