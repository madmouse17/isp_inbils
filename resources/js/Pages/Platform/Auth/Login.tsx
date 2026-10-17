import { useRef } from 'react';
import type { FormEvent } from 'react';
import { useForm } from '@inertiajs/react';
import { Alert, AlertDescription, Button, Input } from '@/Components/ui';
import PlatformAuthLayout from '@/Layouts/PlatformAuthLayout';

export default function Login({ status }: { status?: string | null }) {
    const passwordInput = useRef<HTMLInputElement>(null);
    const form = useForm({ email: '', password: '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(route('platform.login.store'), {
            onError: () => passwordInput.current?.focus(),
            onFinish: () => form.reset('password'),
        });
    };

    return (
        <PlatformAuthLayout title="Masuk ke panel operator">
            <div className="space-y-2">
                <h1 className="text-2xl font-semibold tracking-tight">Masuk ke panel operator</h1>
                <p className="text-sm text-muted-foreground">
                    Gunakan akun operator Inbils. Akun perusahaan tidak dapat masuk di sini.
                </p>
            </div>

            {status ? (
                <Alert variant="info" className="mt-6">
                    <AlertDescription>{status}</AlertDescription>
                </Alert>
            ) : null}

            <form onSubmit={submit} className="space-y-5 mt-6">
                <Input
                    id="platform-email"
                    label="Email"
                    type="email"
                    name="email"
                    value={form.data.email}
                    autoComplete="username"
                    autoFocus
                    error={form.errors.email}
                    onChange={(event) => form.setData('email', event.target.value)}
                    required
                />
                <Input
                    ref={passwordInput}
                    id="platform-password"
                    label="Kata sandi"
                    type="password"
                    name="password"
                    value={form.data.password}
                    autoComplete="current-password"
                    error={form.errors.password}
                    onChange={(event) => form.setData('password', event.target.value)}
                    required
                />
                <Button type="submit" className="w-full" loading={form.processing}>
                    Masuk
                </Button>
            </form>
        </PlatformAuthLayout>
    );
}
