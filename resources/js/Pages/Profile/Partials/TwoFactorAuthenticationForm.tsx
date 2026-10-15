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
    Button,
    buttonVariants,
    EnumBadge,
    Input,
} from '@/Components/ui';
import { useForm } from '@inertiajs/react';
import { TWO_FACTOR_STATE } from '@/lib/statuses';
import { Copy, Download } from 'lucide-react';
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
    link.download = 'kode-pemulihan-inbils.txt';
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
                    <h2 className="text-lg font-medium text-foreground">Autentikasi dua langkah</h2>

                    <p className="mt-1 max-w-xl text-sm text-muted-foreground">
                        Setelah kata sandi, Anda memasukkan kode dari aplikasi autentikator. Kata
                        sandi yang bocor saja tidak cukup untuk masuk ke akun Anda.
                    </p>
                </div>

                <EnumBadge
                    map={TWO_FACTOR_STATE}
                    value={twoFactor.enabled ? 'enabled' : twoFactor.pending ? 'pending' : 'off'}
                />
            </header>

            {!twoFactor.enabled && !twoFactor.pending ? (
                <div className="space-y-3">
                    <Button type="button" onClick={startSetup} loading={enable.processing}>
                        Aktifkan autentikasi dua langkah
                    </Button>
                    <p className="text-sm text-muted-foreground">
                        Anda mungkin diminta memasukkan kata sandi dulu.
                    </p>
                </div>
            ) : null}

            {twoFactor.pending && !showSetup ? (
                <div className="space-y-4">
                    <Alert variant="warning">
                        <AlertTitle>Pengaturan belum selesai</AlertTitle>
                        <AlertDescription>
                            Kode QR hanya tampil sekali, jadi pengaturan ini tidak bisa dilanjutkan.
                            Mulai lagi untuk mendapat kode QR baru.
                        </AlertDescription>
                    </Alert>
                    <div className="flex flex-wrap gap-3">
                        <Button type="button" onClick={startSetup} loading={enable.processing}>
                            Mulai lagi
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={turnOff}
                            loading={disable.processing}
                        >
                            Batalkan pengaturan
                        </Button>
                    </div>
                </div>
            ) : null}

            {showSetup && setup ? (
                <div className="grid gap-6 md:grid-cols-[auto_1fr]">
                    <img
                        src={`data:image/svg+xml;charset=utf-8,${encodeURIComponent(setup.qr_svg)}`}
                        alt="Kode QR untuk kunci pengaturan di bawah"
                        className="size-48 rounded-lg border border-border bg-white"
                    />

                    <div className="space-y-5">
                        <ol className="space-y-2 list-decimal pl-5 text-sm text-foreground">
                            <li>Pindai kode QR dengan aplikasi autentikator di ponsel Anda.</li>
                            <li>
                                Tidak bisa memindai? Masukkan kunci pengaturan ini di aplikasi:
                                <code className="mt-1 block rounded-md bg-muted px-2 py-1 font-mono text-sm break-all select-all">
                                    {setup.setup_key.match(/.{1,4}/g)?.join(' ')}
                                </code>
                            </li>
                            <li>Ketik kode 6 digit yang tampil di aplikasi untuk menyelesaikan.</li>
                        </ol>

                        <form onSubmit={confirmSetup} className="space-y-4 max-w-xs">
                            <Input
                                id="two_factor_confirm_code"
                                label="Kode autentikasi"
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
                                    Konfirmasi dan aktifkan
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={turnOff}
                                    loading={disable.processing}
                                >
                                    Batalkan pengaturan
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
                            Kode pemulihan Anda
                        </h3>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Simpan di tempat aman, misalnya pengelola kata sandi. Setiap kode hanya
                            berlaku sekali dan tidak ditampilkan lagi.
                        </p>
                    </div>

                    <ul
                        aria-label="Kode pemulihan"
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
                                Salin
                            </Button>
                        ) : null}
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            leftIcon={<Download className="size-4" aria-hidden="true" />}
                            onClick={() => downloadCodes(recoveryCodes)}
                        >
                            Unduh sebagai teks
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            onClick={() => {
                                setRecoveryCodes(null);
                                setCopied(false);
                            }}
                        >
                            Saya sudah menyimpan kode ini
                        </Button>
                        <p role="status" className="text-sm text-muted-foreground">
                            {copied ? 'Disalin ke papan klip.' : ''}
                        </p>
                    </div>
                </div>
            ) : null}

            {twoFactor.enabled ? (
                <div className="space-y-4">
                    <p className="text-sm text-muted-foreground">
                        Sisa kode pemulihan: {twoFactor.recovery_codes_remaining}. Kode baru membuat
                        kode lama tidak berlaku lagi.
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
                            Buat kode pemulihan baru
                        </Button>
                        <Button
                            type="button"
                            variant="danger"
                            onClick={() => setConfirmingDisable(true)}
                        >
                            Nonaktifkan autentikasi dua langkah
                        </Button>
                    </div>
                    <p className="text-sm text-muted-foreground">
                        Tindakan ini mungkin meminta kata sandi Anda dulu.
                    </p>
                </div>
            ) : null}

            <AlertDialog open={confirmingDisable} onOpenChange={setConfirmingDisable}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Nonaktifkan autentikasi dua langkah?</AlertDialogTitle>
                        <AlertDialogDescription>
                            Akun Anda hanya dilindungi kata sandi, dan kode pemulihan tidak berlaku
                            lagi.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel type="button" disabled={disable.processing}>
                            Tetap aktif
                        </AlertDialogCancel>
                        <AlertDialogAction
                            type="button"
                            disabled={disable.processing}
                            className={buttonVariants({ variant: 'destructive' })}
                            onClick={(e) => {
                                e.preventDefault();
                                turnOff();
                            }}
                        >
                            {disable.processing ? 'Menonaktifkan…' : 'Nonaktifkan'}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </section>
    );
}
