import { useState } from 'react';
import type { FormEvent } from 'react';
import { router, useForm } from '@inertiajs/react';
import { Download } from 'lucide-react';
import { Alert, AlertDescription, AlertTitle, Button, EnumBadge, Input } from '@/Components/ui';
import { CopyButton } from '@/Components/composite/CopyButton';
import PlatformAuthLayout from '@/Layouts/PlatformAuthLayout';
import { TWO_FACTOR_STATE } from '@/lib/statuses';
import type { PlatformTwoFactorFlash, PlatformTwoFactorStatus } from '@/types/platform';
import { RegenerateRecoveryCodesDialog } from './components/RegenerateRecoveryCodesDialog';

function downloadCodes(codes: string[]) {
    const url = URL.createObjectURL(new Blob([`${codes.join('\n')}\n`], { type: 'text/plain' }));
    const link = document.createElement('a');
    link.href = url;
    link.download = 'kode-pemulihan-platform-inbils.txt';
    link.click();
    URL.revokeObjectURL(url);
}

export default function TwoFactorSetup({ twoFactor }: { twoFactor: PlatformTwoFactorStatus }) {
    const [setup, setSetup] = useState<PlatformTwoFactorFlash['setup']>(undefined);
    const [recoveryCodes, setRecoveryCodes] = useState<string[] | null>(null);
    const start = useForm({});
    const confirm = useForm({ code: '' });
    const [regenerating, setRegenerating] = useState(false);

    const takeFlash = (flash: Record<string, unknown>) => {
        const sent = flash.two_factor as PlatformTwoFactorFlash | undefined;

        if (sent?.setup) {
            setSetup(sent.setup);
            setRecoveryCodes(null);
        }

        if (sent?.recovery_codes) {
            setRecoveryCodes(sent.recovery_codes);
            setSetup(undefined);
        }
    };

    const startSetup = () =>
        start.post(route('platform.two-factor.setup.store'), {
            preserveScroll: true,
            onFlash: takeFlash,
        });

    const confirmSetup = (event: FormEvent) => {
        event.preventDefault();
        confirm.post(route('platform.two-factor.confirm'), {
            preserveScroll: true,
            onFlash: takeFlash,
            onSuccess: () => confirm.reset('code'),
        });
    };

    const showSetup = twoFactor.pending && setup !== undefined;
    const showCodes = twoFactor.enabled && recoveryCodes !== null;

    return (
        <PlatformAuthLayout title="Autentikasi dua langkah" wide>
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h1 className="text-xl font-semibold tracking-tight">
                        Autentikasi dua langkah
                    </h1>
                    <p className="mt-2 max-w-xl text-sm text-muted-foreground">
                        Admin platform wajib memakai kode autentikator setelah kata sandi.
                    </p>
                </div>
                <EnumBadge
                    map={TWO_FACTOR_STATE}
                    value={twoFactor.enabled ? 'enabled' : twoFactor.pending ? 'pending' : 'off'}
                />
            </div>

            {!twoFactor.enabled && !twoFactor.pending ? (
                <div className="space-y-3 mt-6">
                    <p className="text-sm text-muted-foreground">
                        Siapkan aplikasi autentikator sebelum membuka panel operator.
                    </p>
                    <Button type="button" onClick={startSetup} loading={start.processing}>
                        Mulai pengaturan
                    </Button>
                </div>
            ) : null}

            {twoFactor.pending && !showSetup ? (
                <div className="space-y-4 mt-6">
                    <Alert variant="warning">
                        <AlertTitle>Pengaturan belum selesai</AlertTitle>
                        <AlertDescription>
                            Kode QR hanya tampil sekali. Mulai lagi untuk mendapat kode baru.
                        </AlertDescription>
                    </Alert>
                    <Button type="button" onClick={startSetup} loading={start.processing}>
                        Mulai lagi
                    </Button>
                </div>
            ) : null}

            {showSetup && setup ? (
                <div className="mt-6 grid gap-6 md:grid-cols-[auto_1fr]">
                    <img
                        src={`data:image/svg+xml;charset=utf-8,${encodeURIComponent(setup.qr_svg)}`}
                        alt="Kode QR untuk kunci pengaturan"
                        className="size-48 rounded-lg border border-border bg-white"
                    />
                    <div className="space-y-5">
                        <ol className="space-y-2 list-decimal pl-5 text-sm">
                            <li>Pindai kode QR dengan aplikasi autentikator.</li>
                            <li>
                                Jika pemindaian gagal, masukkan kunci ini:
                                <code className="mt-1 block rounded-md bg-muted px-2 py-1 font-mono text-sm break-all select-all">
                                    {setup.setup_key.match(/.{1,4}/g)?.join(' ')}
                                </code>
                            </li>
                            <li>Ketik kode 6 digit untuk menyelesaikan pengaturan.</li>
                        </ol>
                        <form onSubmit={confirmSetup} className="space-y-4 max-w-xs">
                            <Input
                                id="platform-two-factor-confirm-code"
                                label="Kode autentikasi"
                                value={confirm.data.code}
                                inputMode="numeric"
                                autoComplete="one-time-code"
                                autoFocus
                                error={confirm.errors.code}
                                onChange={(event) => confirm.setData('code', event.target.value)}
                                required
                            />
                            <Button type="submit" loading={confirm.processing}>
                                Konfirmasi dan aktifkan
                            </Button>
                        </form>
                    </div>
                </div>
            ) : null}

            {showCodes && recoveryCodes ? (
                <div className="space-y-4 mt-6 rounded-lg border bg-muted/30 p-4">
                    <div>
                        <h2 className="font-semibold">Kode pemulihan Anda</h2>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Simpan di tempat aman. Setiap kode hanya berlaku sekali dan tidak
                            ditampilkan lagi.
                        </p>
                    </div>
                    <ul
                        aria-label="Kode pemulihan"
                        className="grid gap-2 font-mono text-sm sm:grid-cols-2"
                    >
                        {recoveryCodes.map((code) => (
                            <li
                                key={code}
                                className="rounded-md bg-background px-3 py-2 select-all"
                            >
                                {code}
                            </li>
                        ))}
                    </ul>
                    <div className="flex flex-wrap gap-2">
                        <CopyButton value={recoveryCodes.join('\n')} />
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
                            onClick={() => router.get(route('platform.dashboard'))}
                        >
                            Saya sudah menyimpan kode ini
                        </Button>
                    </div>
                </div>
            ) : null}

            {twoFactor.enabled && !showCodes ? (
                <div className="space-y-4 mt-6">
                    <Alert variant="success">
                        <AlertTitle>Autentikasi dua langkah aktif</AlertTitle>
                        <AlertDescription>
                            Sisa kode pemulihan: {twoFactor.recovery_codes_remaining}. Kode baru
                            membuat kode lama tidak berlaku lagi.
                        </AlertDescription>
                    </Alert>
                    <Button type="button" variant="outline" onClick={() => setRegenerating(true)}>
                        Buat kode pemulihan baru
                    </Button>
                </div>
            ) : null}
            {regenerating ? (
                <RegenerateRecoveryCodesDialog
                    onFlash={takeFlash}
                    onClose={() => setRegenerating(false)}
                />
            ) : null}
        </PlatformAuthLayout>
    );
}
