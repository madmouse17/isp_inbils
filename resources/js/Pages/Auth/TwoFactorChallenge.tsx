import { Button } from '@/Components/ui/Button';
import { Input } from '@/Components/ui/Input';
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEventHandler } from 'react';
import { useRef, useState } from 'react';

export default function TwoFactorChallenge() {
    const [usingRecoveryCode, setUsingRecoveryCode] = useState(false);
    const codeInput = useRef<HTMLInputElement>(null);

    const { data, setData, post, processing, errors, reset, clearErrors } = useForm({
        code: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        post(route('two-factor.login'), {
            onError: () => {
                reset('code');
                codeInput.current?.focus();
            },
        });
    };

    const toggleMethod = () => {
        setUsingRecoveryCode((current) => !current);
        reset('code');
        clearErrors();
        codeInput.current?.focus();
    };

    return (
        <GuestLayout>
            <Head title="Two-factor authentication" />

            <h1 className="text-xl font-semibold tracking-tight text-foreground">
                Two-factor authentication
            </h1>

            <p className="mt-2 mb-4 text-sm text-muted-foreground">
                {usingRecoveryCode
                    ? 'Enter one of your recovery codes. Each code works only once.'
                    : 'Enter the 6-digit code from your authenticator app.'}
            </p>

            <form onSubmit={submit} className="space-y-4">
                <Input
                    id="code"
                    ref={codeInput}
                    label={usingRecoveryCode ? 'Recovery code' : 'Authentication code'}
                    name="code"
                    value={data.code}
                    inputMode={usingRecoveryCode ? 'text' : 'numeric'}
                    autoComplete="one-time-code"
                    autoCapitalize="none"
                    autoCorrect="off"
                    spellCheck={false}
                    autoFocus
                    error={errors.code}
                    onChange={(e) => setData('code', e.target.value)}
                />

                <Button type="submit" className="w-full" loading={processing}>
                    Log in
                </Button>
            </form>

            <div className="mt-4 flex flex-wrap items-center justify-between gap-3 text-sm">
                <button
                    type="button"
                    onClick={toggleMethod}
                    className="rounded-md font-medium text-primary underline-offset-4 hover:underline focus:ring-2 focus:ring-ring focus:ring-offset-2 focus:outline-hidden"
                >
                    {usingRecoveryCode ? 'Use an authentication code' : 'Use a recovery code'}
                </button>

                <Link
                    href={route('login')}
                    className="rounded-md text-muted-foreground underline-offset-4 hover:underline focus:ring-2 focus:ring-ring focus:ring-offset-2 focus:outline-hidden"
                >
                    Back to log in
                </Link>
            </div>
        </GuestLayout>
    );
}
