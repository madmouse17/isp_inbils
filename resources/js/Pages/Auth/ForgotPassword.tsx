import { Alert, AlertDescription } from '@/Components/ui/Alert';
import { Button } from '@/Components/ui/Button';
import { Input } from '@/Components/ui/Input';
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEventHandler } from 'react';

export default function ForgotPassword({ status }: { status?: string }) {
    const { data, setData, post, processing, errors } = useForm({
        email: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        post(route('password.email'));
    };

    return (
        <GuestLayout>
            <Head title="Lupa kata sandi" />

            <h1 className="text-xl font-semibold tracking-tight text-foreground">
                Lupa kata sandi
            </h1>

            <p className="mt-2 mb-4 text-sm text-muted-foreground">
                Masukkan email akun Anda. Kami kirim tautan untuk membuat kata sandi baru.
            </p>

            {status && (
                <Alert variant="success" className="mb-4">
                    <AlertDescription>{status}</AlertDescription>
                </Alert>
            )}

            <form onSubmit={submit} className="space-y-4">
                <Input
                    id="email"
                    label="Email"
                    type="email"
                    name="email"
                    value={data.email}
                    autoComplete="username"
                    autoFocus
                    error={errors.email}
                    onChange={(e) => setData('email', e.target.value)}
                />

                <Button type="submit" className="w-full" loading={processing}>
                    Kirim tautan
                </Button>
            </form>

            <div className="mt-4 text-sm">
                <Link
                    href={route('login')}
                    className="inline-flex min-h-11 items-center rounded-md text-muted-foreground underline-offset-4 hover:underline focus:ring-2 focus:ring-ring focus:ring-offset-2 focus:outline-hidden sm:min-h-0"
                >
                    Kembali ke halaman masuk
                </Link>
            </div>
        </GuestLayout>
    );
}
