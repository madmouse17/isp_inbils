import { useRef, useState } from 'react';
import type { FormEvent } from 'react';
import { Link, useForm } from '@inertiajs/react';
import { Button, Input } from '@/Components/ui';
import PlatformAuthLayout from '@/Layouts/PlatformAuthLayout';

export default function TwoFactorChallenge() {
    const [usingRecoveryCode, setUsingRecoveryCode] = useState(false);
    const codeInput = useRef<HTMLInputElement>(null);
    const form = useForm({ code: '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(route('platform.two-factor.login.store'), {
            onError: () => {
                form.reset('code');
                codeInput.current?.focus();
            },
        });
    };

    const toggleMethod = () => {
        setUsingRecoveryCode((current) => !current);
        form.reset('code');
        form.clearErrors();
        codeInput.current?.focus();
    };

    return (
        <PlatformAuthLayout title="Autentikasi dua langkah">
            <h1 className="text-xl font-semibold tracking-tight">Autentikasi dua langkah</h1>
            <p className="mt-2 text-sm text-muted-foreground">
                {usingRecoveryCode
                    ? 'Masukkan salah satu kode pemulihan. Setiap kode hanya berlaku sekali.'
                    : 'Masukkan kode 6 digit dari aplikasi autentikator.'}
            </p>

            <form onSubmit={submit} className="space-y-4 mt-5">
                <Input
                    ref={codeInput}
                    id="platform-two-factor-code"
                    label={usingRecoveryCode ? 'Kode pemulihan' : 'Kode autentikasi'}
                    value={form.data.code}
                    inputMode={usingRecoveryCode ? 'text' : 'numeric'}
                    autoComplete="one-time-code"
                    autoCapitalize="none"
                    autoCorrect="off"
                    spellCheck={false}
                    autoFocus
                    error={form.errors.code}
                    onChange={(event) => form.setData('code', event.target.value)}
                    required
                />
                <Button type="submit" className="w-full" loading={form.processing}>
                    Masuk
                </Button>
            </form>

            <div className="mt-4 flex flex-wrap items-center justify-between gap-3 text-sm">
                <button
                    type="button"
                    onClick={toggleMethod}
                    className="inline-flex min-h-11 items-center rounded-md font-medium text-primary-text underline-offset-4 hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-hidden"
                >
                    {usingRecoveryCode ? 'Pakai kode autentikasi' : 'Pakai kode pemulihan'}
                </button>
                <Link
                    href={route('platform.login')}
                    className="inline-flex min-h-11 items-center rounded-md text-muted-foreground underline-offset-4 hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-hidden"
                >
                    Kembali ke halaman masuk
                </Link>
            </div>
        </PlatformAuthLayout>
    );
}
