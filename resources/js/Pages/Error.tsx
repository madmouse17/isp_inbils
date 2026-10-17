import { Head, Link, usePage } from '@inertiajs/react';
import { AlertCircle, ShieldAlert } from 'lucide-react';
import { Button } from '@/Components/ui/Button';
import {
    Empty,
    EmptyContent,
    EmptyDescription,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
} from '@/Components/ui/empty';
import { Wordmark } from '@/Components/ui/Wordmark';
import type { PageProps } from '@/types';

interface ErrorCopy {
    title: string;
    description: string;
    /** The page can succeed on a second try, so it gets a reload button. */
    retry: boolean;
}

const copy: Record<number, ErrorCopy> = {
    403: {
        title: 'Akses ditolak',
        description:
            'Anda tidak punya izin untuk membuka halaman ini. Hubungi administrator jika Anda memerlukan aksesnya.',
        retry: false,
    },
    404: {
        title: 'Halaman tidak ditemukan',
        description:
            'Alamat yang Anda buka tidak ada atau sudah dipindahkan. Periksa alamatnya, atau kembali ke halaman utama.',
        retry: false,
    },
    419: {
        title: 'Sesi berakhir',
        description:
            'Halaman ini sudah terlalu lama terbuka dan kedaluwarsa. Muat ulang halaman, lalu ulangi langkah Anda.',
        retry: true,
    },
    500: {
        title: 'Terjadi kesalahan di server',
        description:
            'Permintaan Anda tidak dapat diproses. Coba lagi sebentar lagi. Jika masalah berlanjut, hubungi administrator.',
        retry: true,
    },
    503: {
        title: 'Layanan sedang tidak tersedia',
        description: 'Aplikasi sedang dalam pemeliharaan. Coba lagi beberapa saat lagi.',
        retry: true,
    },
};

/**
 * Rendered by the exception handler for 403, 404, 419, 500 and 503. It stands alone: no AdminLayout,
 * and the shared auth props are read defensively, because a failed request may carry none.
 */
export default function ErrorPage({ status }: { status: number }) {
    const { title, description, retry } = copy[status] ?? {
        title: `Kesalahan ${status}`,
        description: 'Terjadi kesalahan saat memproses permintaan Anda.',
        retry: true,
    };
    const pageProps = usePage<PageProps>().props;
    const platformShell = route().has('platform.login');
    const platformSignedIn = Boolean(
        (pageProps as PageProps & { platform?: { admin?: unknown } }).platform?.admin,
    );
    const signedIn = platformShell ? platformSignedIn : Boolean(pageProps.auth?.user);
    const homeHref = platformShell
        ? route(signedIn ? 'platform.dashboard' : 'platform.login')
        : route(signedIn ? 'admin.dashboard' : 'login');
    const Icon = status === 403 ? ShieldAlert : AlertCircle;

    return (
        <main className="flex min-h-svh flex-col items-center justify-center gap-6 bg-muted/30 p-6 dark:bg-background">
            <Head title={title} />
            <Wordmark className="text-2xl" />
            <Empty className="max-w-lg flex-none border border-dashed bg-card">
                <EmptyHeader>
                    <EmptyMedia variant="icon">
                        <Icon />
                    </EmptyMedia>
                    <EmptyTitle>
                        <h1>{title}</h1>
                    </EmptyTitle>
                    <EmptyDescription>{description}</EmptyDescription>
                    <p className="text-sm text-muted-foreground">Kode galat {status}</p>
                </EmptyHeader>
                <EmptyContent>
                    {retry ? (
                        <Button type="button" onClick={() => window.location.reload()}>
                            Muat ulang halaman
                        </Button>
                    ) : null}
                    <Button asChild variant={retry ? 'outline' : 'default'}>
                        <Link href={homeHref}>{signedIn ? 'Ke dasbor' : 'Masuk'}</Link>
                    </Button>
                </EmptyContent>
            </Empty>
        </main>
    );
}
