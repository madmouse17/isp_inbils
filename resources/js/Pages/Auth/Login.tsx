import { AuthBackdrop } from '@/Components/composite/AuthBackdrop';
import { Alert, AlertDescription } from '@/Components/ui/Alert';
import { Button } from '@/Components/ui/Button';
import { Card, CardContent } from '@/Components/ui/Card';
import { Checkbox } from '@/Components/ui/Checkbox';
import { Input } from '@/Components/ui/Input';
import { Label } from '@/Components/ui/Label';
import { BrandLockup, BrandMark } from '@/Components/ui/BrandMark';
import { Wordmark } from '@/Components/ui/Wordmark';
import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEventHandler } from 'react';

export default function Login({
    status,
    canResetPassword,
}: {
    status?: string;
    canResetPassword: boolean;
}) {
    const { data, setData, post, processing, errors, reset } = useForm({
        email: '',
        password: '',
        remember: false as boolean,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        post(route('login'), {
            onFinish: () => reset('password'),
        });
    };

    return (
        <main className="relative isolate min-h-screen bg-muted/30 text-foreground lg:bg-background dark:bg-background">
            <AuthBackdrop />
            <Head title="Masuk" />

            <div className="grid min-h-screen lg:grid-cols-[1.05fr_0.95fr]">
                <section className="hidden border-r border-border bg-surface-950 text-white lg:block">
                    <div className="flex h-full flex-col justify-between p-12">
                        <Link
                            href="/"
                            className="flex w-fit items-center gap-3 rounded-md text-2xl focus-visible:ring-2 focus-visible:ring-white focus-visible:outline-hidden"
                        >
                            <BrandMark className="size-10 text-brand-400" />
                            <Wordmark className="text-white" />
                        </Link>

                        <p className="max-w-xl text-3xl leading-tight font-semibold tracking-tight xl:text-4xl">
                            Kelola pelanggan, tagihan, penugasan teknisi, dan stok ISP Anda di satu
                            tempat.
                        </p>
                    </div>
                </section>

                <section className="flex items-center justify-center px-4 py-10 sm:px-6">
                    <Card className="auth-rise w-full max-w-md">
                        <CardContent className="p-6 sm:p-8">
                            <div className="mb-8 flex justify-center lg:hidden">
                                <Link
                                    href="/"
                                    className="rounded-md focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-hidden"
                                >
                                    <BrandLockup />
                                </Link>
                            </div>

                            <div className="mb-8">
                                <h1 className="text-2xl font-semibold tracking-tight">Masuk</h1>
                                <p className="mt-2 text-sm text-muted-foreground">
                                    Gunakan email dan kata sandi akun Anda.
                                </p>
                            </div>

                            {status && (
                                <Alert variant="success" className="mb-5">
                                    <AlertDescription>{status}</AlertDescription>
                                </Alert>
                            )}

                            <form onSubmit={submit} className="space-y-5">
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

                                <Input
                                    id="password"
                                    label="Kata sandi"
                                    type="password"
                                    name="password"
                                    value={data.password}
                                    autoComplete="current-password"
                                    error={errors.password}
                                    onChange={(e) => setData('password', e.target.value)}
                                />

                                <div className="flex items-center justify-between gap-4">
                                    <Label className="flex items-center gap-2 text-sm font-normal text-muted-foreground">
                                        <Checkbox
                                            name="remember"
                                            checked={data.remember}
                                            onChange={(e) => setData('remember', e.target.checked)}
                                            className="mt-0"
                                        />
                                        Ingat saya
                                    </Label>

                                    {canResetPassword && (
                                        <Link
                                            href={route('password.request')}
                                            className="inline-flex min-h-11 items-center rounded-md text-sm font-medium text-primary-text underline-offset-4 hover:underline focus:ring-2 focus:ring-ring focus:ring-offset-2 focus:outline-hidden sm:min-h-0"
                                        >
                                            Lupa kata sandi?
                                        </Link>
                                    )}
                                </div>

                                <Button type="submit" className="w-full" loading={processing}>
                                    Masuk
                                </Button>
                            </form>
                        </CardContent>
                    </Card>
                </section>
            </div>
        </main>
    );
}
