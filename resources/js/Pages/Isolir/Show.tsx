import { Head } from '@inertiajs/react';
import GuestLayout from '@/Layouts/GuestLayout';
import { Button } from '@/Components/ui';

interface ShowProps extends Record<string, unknown> {
    company: {
        name: string;
        phone: string | null;
        /** `https://wa.me/{digits}`, made by the server; null unless the phone is an Indonesian mobile number. */
        whatsapp_url: string | null;
        email: string | null;
        website: string | null;
        /** The customer app listing; null until the platform has set one. */
        app_url: string | null;
    };
}

const linkClass =
    'inline-flex min-h-11 items-center rounded-sm text-foreground underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-hidden';

// Large buttons: 44 px high on every width, and a long ISP name wraps instead of overflowing the card.
const buttonClass = 'w-full text-center text-base whitespace-normal sm:min-h-11';

/** The website and the app link are text staff or the platform entered: link one only when it is a plain http(s) URL. */
function webLinkOf(value: string): string | null {
    try {
        const url = new URL(value);

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
 * Where a router sends isolated subscribers. The redirect carries no subscriber, so one page covers both causes
 * (unpaid bills, limited by the ISP) and gives each its way out: pay in the app, or call or write to the ISP. Public
 * and read-only: the company's own contact fields and the app link, nothing about a subscriber, and no login link
 * because customers use the app, not the web.
 */
export default function Show({ company }: ShowProps) {
    const phoneDigits = company.phone?.replace(/\D/g, '') ?? '';
    const telHref = phoneDigits
        ? `tel:${company.phone?.trim().startsWith('+') ? '+' : ''}${phoneDigits}`
        : null;
    const whatsappLink = company.whatsapp_url ? webLinkOf(company.whatsapp_url) : null;
    const appLink = company.app_url ? webLinkOf(company.app_url) : null;
    const webLink = company.website ? webLinkOf(company.website) : null;

    return (
        <GuestLayout>
            <Head title="Layanan dibatasi" />
            <main lang="id" className="space-y-6">
                <header className="space-y-2">
                    <p className="text-sm font-semibold wrap-break-word text-foreground">
                        {company.name}
                    </p>
                    <h1 className="text-xl font-semibold tracking-tight text-balance text-foreground sm:text-2xl">
                        Layanan internet Anda sedang dibatasi
                    </h1>
                </header>

                <section aria-label="Tagihan belum dibayar" className="space-y-3">
                    <p className="text-sm leading-relaxed text-muted-foreground">
                        <span className="font-medium text-foreground">
                            Ada tagihan yang belum dibayar?
                        </span>{' '}
                        Bayar lewat aplikasi {company.name}. Layanan aktif kembali otomatis setelah
                        pembayaran diterima.
                    </p>
                    {appLink && (
                        <Button asChild size="auto" className={buttonClass}>
                            <a href={appLink} target="_blank" rel="noopener noreferrer">
                                Buka aplikasi {company.name}
                            </a>
                        </Button>
                    )}
                </section>

                <section
                    aria-label="Dibatasi karena alasan lain"
                    className="space-y-3 border-t border-border pt-4"
                >
                    <p className="text-sm leading-relaxed text-muted-foreground">
                        <span className="font-medium text-foreground">
                            Dibatasi oleh {company.name} karena alasan lain?
                        </span>{' '}
                        Hubungi kami.
                    </p>
                    {(telHref || whatsappLink) && (
                        <div className="flex flex-col gap-2">
                            {telHref && (
                                <Button
                                    asChild
                                    variant="outline"
                                    size="auto"
                                    className={buttonClass}
                                >
                                    <a href={telHref}>
                                        Telepon
                                        <span className="font-normal wrap-break-word">
                                            {company.phone}
                                        </span>
                                    </a>
                                </Button>
                            )}
                            {whatsappLink && (
                                <Button
                                    asChild
                                    variant="outline"
                                    size="auto"
                                    className={buttonClass}
                                >
                                    <a
                                        href={whatsappLink}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                    >
                                        WhatsApp
                                    </a>
                                </Button>
                            )}
                        </div>
                    )}
                    {(company.email || company.website) && (
                        <dl className="space-y-1 text-sm">
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
                    )}
                </section>
            </main>
        </GuestLayout>
    );
}
