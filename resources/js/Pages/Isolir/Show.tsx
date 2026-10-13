import { Head } from '@inertiajs/react';
import GuestLayout from '@/Layouts/GuestLayout';
import { Button } from '@/Components/ui';

interface ShowProps extends Record<string, unknown> {
    company: {
        name: string;
        phone: string | null;
        email: string | null;
        website: string | null;
    };
    loginUrl: string;
}

const linkClass =
    'inline-flex min-h-11 items-center rounded-sm text-foreground underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-hidden';

/** The website is staff-entered free text: link it only when it is a plain http(s) URL. */
function webLinkOf(website: string): string | null {
    try {
        const url = new URL(website);

        return url.protocol === 'http:' || url.protocol === 'https:' ? url.href : null;
    } catch {
        return null;
    }
}

function ContactRow({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <div className="flex flex-col sm:flex-row sm:items-center sm:gap-3">
            <dt className="font-medium text-foreground sm:w-24">{label}</dt>
            <dd className="min-w-0 wrap-break-word">{children}</dd>
        </div>
    );
}

/**
 * Where a router sends isolated subscribers. Public and read-only: the company's own contact fields and
 * the login link, nothing about a subscriber.
 */
export default function Show({ company, loginUrl }: ShowProps) {
    const phoneDigits = company.phone?.replace(/\D/g, '') ?? '';
    const telHref = phoneDigits
        ? `tel:${company.phone?.trim().startsWith('+') ? '+' : ''}${phoneDigits}`
        : null;
    const webLink = company.website ? webLinkOf(company.website) : null;
    const hasContact = Boolean(company.phone || company.email || company.website);

    return (
        <GuestLayout>
            <Head title="Layanan diisolir" />
            <main lang="id" className="space-y-6">
                <header className="space-y-2">
                    <p className="text-sm font-semibold text-foreground">{company.name}</p>
                    <h1 className="text-xl font-semibold tracking-tight text-foreground sm:text-2xl">
                        Layanan internet Anda sedang diisolir
                    </h1>
                    <p className="text-sm leading-relaxed text-muted-foreground">
                        Layanan dihentikan sementara karena ada tagihan yang belum dibayar. Layanan
                        akan diaktifkan kembali setelah pembayaran tercatat.
                    </p>
                </header>

                <section aria-labelledby="cara-aktif" className="space-y-2">
                    <h2 id="cara-aktif" className="text-sm font-medium text-foreground">
                        Cara mengaktifkan kembali
                    </h2>
                    <ol className="space-y-1.5 list-decimal pl-5 text-sm leading-relaxed text-muted-foreground">
                        <li>Masuk ke akun pelanggan Anda.</li>
                        <li>Buka tagihan yang belum dibayar, lalu bayar secara online.</li>
                    </ol>
                </section>

                <Button
                    asChild
                    size="auto"
                    className="w-full text-center text-base whitespace-normal sm:min-h-11"
                >
                    <a href={loginUrl}>Masuk untuk melihat dan membayar tagihan</a>
                </Button>

                <div className="space-y-3 border-t border-border pt-4">
                    <p className="text-sm text-muted-foreground">
                        Sudah membayar tetapi layanan belum aktif? Hubungi kami.
                    </p>
                    {hasContact && (
                        <section aria-label={`Kontak ${company.name}`} className="space-y-3">
                            <dl className="space-y-1 text-sm">
                                {company.phone && (
                                    <ContactRow label="Telepon">
                                        {telHref ? (
                                            <a href={telHref} className={linkClass}>
                                                {company.phone}
                                            </a>
                                        ) : (
                                            company.phone
                                        )}
                                    </ContactRow>
                                )}
                                {company.email && (
                                    <ContactRow label="Email">
                                        <a href={`mailto:${company.email}`} className={linkClass}>
                                            {company.email}
                                        </a>
                                    </ContactRow>
                                )}
                                {company.website && (
                                    <ContactRow label="Situs web">
                                        {webLink ? (
                                            <a
                                                href={webLink}
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                className={linkClass}
                                            >
                                                {company.website}
                                            </a>
                                        ) : (
                                            company.website
                                        )}
                                    </ContactRow>
                                )}
                            </dl>
                        </section>
                    )}
                </div>
            </main>
        </GuestLayout>
    );
}
