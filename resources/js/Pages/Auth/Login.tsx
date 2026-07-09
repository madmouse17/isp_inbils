import ApplicationLogo from '@/Components/ApplicationLogo';
import { Button } from '@/Components/ui/Button';
import { Card, CardContent } from '@/Components/ui/Card';
import { Checkbox } from '@/Components/ui/Checkbox';
import { Input } from '@/Components/ui/Input';
import { Label } from '@/Components/ui/Label';
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
        <main className="min-h-screen bg-background text-foreground">
            <Head title="Log in" />

            <div className="grid min-h-screen lg:grid-cols-[1.05fr_0.95fr]">
                <section className="hidden border-r border-border bg-surface-950 text-white lg:block">
                    <div className="flex h-full flex-col justify-between p-12">
                        <Link
                            href="/"
                            className="flex items-center gap-3 text-sm font-semibold tracking-tight"
                        >
                            <span className="flex h-10 w-10 items-center justify-center rounded-lg bg-white text-primary shadow-sm">
                                <ApplicationLogo className="h-6 w-6 fill-current" />
                            </span>
                            Inbils
                        </Link>

                        <div className="max-w-xl">
                            <div className="mb-5 inline-flex rounded-full border border-white/10 bg-white/5 px-3 py-1 text-xs font-medium text-brand-200">
                                Billing command center
                            </div>
                            <h1 className="text-4xl font-semibold leading-tight tracking-tight xl:text-5xl">
                                Masuk ke panel yang rapi untuk invoice, pelanggan, dan kas masuk.
                            </h1>
                            <p className="mt-5 max-w-md text-sm leading-6 text-slate-300">
                                Satu pintu untuk operasional ISP: pantau tagihan, tindak tunggakan,
                                dan lanjutkan kerja harian tanpa pindah konteks.
                            </p>
                        </div>

                        <div className="grid grid-cols-3 gap-3 text-xs text-slate-300">
                            <div className="rounded-lg border border-white/10 bg-white/5 p-3">
                                <div className="text-lg font-semibold text-white">AR</div>
                                <div>aging siap pantau</div>
                            </div>
                            <div className="rounded-lg border border-white/10 bg-white/5 p-3">
                                <div className="text-lg font-semibold text-white">PDF</div>
                                <div>invoice siap kirim</div>
                            </div>
                            <div className="rounded-lg border border-white/10 bg-white/5 p-3">
                                <div className="text-lg font-semibold text-white">Tax</div>
                                <div>per company</div>
                            </div>
                        </div>
                    </div>
                </section>

                <section className="flex items-center justify-center bg-muted/30 px-4 py-10 sm:px-6 lg:bg-background">
                    <Card className="w-full max-w-md border-border/80 shadow-sm">
                        <CardContent className="p-6 sm:p-8">
                            <div className="mb-8 lg:hidden">
                                <Link
                                    href="/"
                                    className="inline-flex items-center gap-3 text-sm font-semibold tracking-tight"
                                >
                                    <span className="flex h-10 w-10 items-center justify-center rounded-lg bg-primary text-primary-foreground shadow-sm">
                                        <ApplicationLogo className="h-6 w-6 fill-current" />
                                    </span>
                                    Inbils
                                </Link>
                            </div>

                            <div className="mb-8">
                                <p className="text-sm font-medium text-primary">Welcome back</p>
                                <h2 className="mt-2 text-2xl font-semibold tracking-tight">
                                    Log in to admin
                                </h2>
                                <p className="mt-2 text-sm text-muted-foreground">
                                    Gunakan akun admin untuk melanjutkan operasional.
                                </p>
                            </div>

                            {status && (
                                <div className="mb-5 rounded-md border border-green-200 bg-green-50 px-3 py-2 text-sm font-medium text-green-700">
                                    {status}
                                </div>
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
                                    label="Password"
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
                                        Remember me
                                    </Label>

                                    {canResetPassword && (
                                        <Link
                                            href={route('password.request')}
                                            className="rounded-md text-sm font-medium text-primary underline-offset-4 hover:underline focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2"
                                        >
                                            Forgot password?
                                        </Link>
                                    )}
                                </div>

                                <Button type="submit" className="w-full" loading={processing}>
                                    Log in
                                </Button>
                            </form>
                        </CardContent>
                    </Card>
                </section>
            </div>
        </main>
    );
}
