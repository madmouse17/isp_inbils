import { Alert, AlertDescription } from '@/Components/ui/Alert';
import { Button } from '@/Components/ui/Button';
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEventHandler } from 'react';

export default function VerifyEmail({ status }: { status?: string }) {
    const { post, processing } = useForm({});

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        post(route('verification.send'));
    };

    return (
        <GuestLayout>
            <Head title="Verifikasi email" />

            <h1 className="text-xl font-semibold tracking-tight text-foreground">
                Verifikasi email Anda
            </h1>

            <p className="mt-2 mb-4 text-sm text-muted-foreground">
                Buka tautan yang kami kirim ke email Anda untuk memverifikasi alamatnya. Belum
                menerima email? Kirim ulang tautannya.
            </p>

            {status === 'verification-link-sent' && (
                <Alert variant="success" className="mb-4">
                    <AlertDescription>
                        Tautan verifikasi baru sudah dikirim ke email Anda.
                    </AlertDescription>
                </Alert>
            )}

            <form onSubmit={submit} className="flex flex-wrap items-center justify-between gap-3">
                <Button type="submit" loading={processing}>
                    Kirim ulang tautan
                </Button>

                <Link
                    href={route('logout')}
                    method="post"
                    as="button"
                    className="inline-flex min-h-11 items-center rounded-md text-sm text-muted-foreground underline-offset-4 hover:underline focus:ring-2 focus:ring-ring focus:ring-offset-2 focus:outline-hidden sm:min-h-0"
                >
                    Keluar
                </Link>
            </form>
        </GuestLayout>
    );
}
