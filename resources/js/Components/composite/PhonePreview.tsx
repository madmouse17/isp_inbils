import { useState } from 'react';
import {
    ChevronLeft,
    ChevronRight,
    Circle,
    CircleAlert,
    CircleCheck,
    ImageOff,
    Info,
    TriangleAlert,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { Button } from '@/Components/ui/Button';
import { IconButton } from '@/Components/ui/IconButton';
import { useTheme } from '@/hooks/useTheme';
import { ANNOUNCEMENT_KIND, resolveStatus } from '@/lib/statuses';
import type { BadgeTone } from '@/lib/statuses';
import { cn } from '@/lib/utils';

export interface PhoneAnnouncement {
    /** A key of `ANNOUNCEMENT_KIND`: the card takes the tone of its kind. */
    kind: string;
    title: string;
    body: string;
    /** The period as the person reads it, already written in the company date format. */
    period: string;
}

export interface PhoneBanner {
    /** The caption under the image, and the spoken label when there is no alt text. */
    title: string;
    altText: string;
    imageUrl: string | null;
}

export interface PhonePreviewProps {
    announcement?: PhoneAnnouncement | null;
    banners?: readonly PhoneBanner[];
    /** The slide shown first, and shown again whenever this changes. */
    bannerIndex?: number;
    className?: string;
}

type PhoneMode = 'light' | 'dark';

interface PhoneColors {
    surface: string;
    card: string;
    ink: string;
    muted: string;
    dot: string;
    dotActive: string;
    tones: Record<BadgeTone, { background: string; text: string }>;
}

/**
 * The customer app screen in each mode. The four status tones are the app's own (`inbils_colors.dart`,
 * the same whatever the ISP colour is). The surfaces only approximate its seeded scheme, which follows the
 * ISP brand colour. Every text pair reaches 6.4:1 and each dot 4:1 on its card.
 */
const PHONE_COLORS: Record<PhoneMode, PhoneColors> = {
    light: {
        surface: '#F6F8FC',
        card: '#E8EDF5',
        ink: '#0F172A',
        muted: '#475569',
        dot: '#64748B',
        dotActive: '#0F172A',
        tones: {
            neutral: { background: '#E8EDF5', text: '#0F172A' },
            info: { background: '#DBEAFE', text: '#1E3A8A' },
            success: { background: '#DCFCE7', text: '#14532D' },
            warning: { background: '#FEF3C7', text: '#78350F' },
            danger: { background: '#FEE2E2', text: '#7F1D1D' },
        },
    },
    dark: {
        surface: '#0B1220',
        card: '#18233A',
        ink: '#E2E8F0',
        muted: '#A9B6CB',
        dot: '#7C8BA3',
        dotActive: '#E2E8F0',
        tones: {
            neutral: { background: '#18233A', text: '#E2E8F0' },
            info: { background: '#1E3A8A', text: '#BFDBFE' },
            success: { background: '#14532D', text: '#BBF7D0' },
            warning: { background: '#78350F', text: '#FDE68A' },
            danger: { background: '#7F1D1D', text: '#FECACA' },
        },
    },
};

/** The icon every tone shows beside its text, so the tone is never colour alone. */
const TONE_ICON: Record<BadgeTone, LucideIcon> = {
    neutral: Circle,
    info: Info,
    success: CircleCheck,
    warning: TriangleAlert,
    danger: CircleAlert,
};

const MODES = [
    { value: 'light', label: 'Terang' },
    { value: 'dark', label: 'Gelap' },
] as const;

/**
 * What the customer app Home will show, drawn at the phone's 360 px width inside a rounded frame:
 * an announcement card in the tone of its kind and the promo banner carousel (image, caption, page
 * dots). The phone has its own Terang and Gelap switch, which starts on the theme of the web page.
 * Text the person has not written yet shows as an italic placeholder.
 */
export function PhonePreview({
    announcement,
    banners = [],
    bannerIndex = 0,
    className,
}: PhonePreviewProps) {
    const { dark } = useTheme();
    const [picked, setPicked] = useState<PhoneMode | null>(null);
    const mode: PhoneMode = picked ?? (dark ? 'dark' : 'light');
    const colors = PHONE_COLORS[mode];

    const [slide, setSlide] = useState(bannerIndex);
    const [followed, setFollowed] = useState(bannerIndex);
    if (bannerIndex !== followed) {
        setFollowed(bannerIndex);
        setSlide(bannerIndex);
    }
    // The list can shrink under the slide the person was on.
    const current = Math.min(slide, Math.max(banners.length - 1, 0));
    const banner = banners[current];
    const step = (by: number) => setSlide((current + by + banners.length) % banners.length);

    return (
        <div className={cn('flex w-fit max-w-full flex-col items-center gap-3', className)}>
            <div role="group" aria-label="Tema aplikasi" className="flex gap-1">
                {MODES.map(({ value, label }) => (
                    <Button
                        key={value}
                        type="button"
                        size="sm"
                        variant={mode === value ? 'primary' : 'outline'}
                        aria-pressed={mode === value}
                        onClick={() => setPicked(value)}
                    >
                        {label}
                    </Button>
                ))}
            </div>

            <div className="w-fit max-w-full rounded-4xl border-8 border-surface-800 bg-surface-800 dark:border-surface-700 dark:bg-surface-700">
                <div
                    role="group"
                    aria-label="Pratinjau beranda aplikasi pelanggan"
                    className="flex min-h-96 w-90 max-w-full flex-col gap-3 overflow-hidden rounded-3xl p-4"
                    style={{ backgroundColor: colors.surface, color: colors.ink }}
                >
                    {announcement ? (
                        <AnnouncementCard announcement={announcement} colors={colors} />
                    ) : null}
                    {banner ? (
                        <BannerCard
                            banner={banner}
                            current={current}
                            count={banners.length}
                            colors={colors}
                        />
                    ) : null}
                    {!announcement && !banner ? (
                        <p className="m-auto text-sm" style={{ color: colors.muted }}>
                            Belum ada isi untuk dipratinjau.
                        </p>
                    ) : null}
                </div>
            </div>

            {banners.length > 1 ? (
                <div className="flex items-center gap-2">
                    <IconButton label="Banner sebelumnya" onClick={() => step(-1)}>
                        <ChevronLeft className="size-4" aria-hidden="true" />
                    </IconButton>
                    <span className="text-sm text-muted-foreground tabular-nums">
                        Banner {current + 1} dari {banners.length}
                    </span>
                    <IconButton label="Banner berikutnya" onClick={() => step(1)}>
                        <ChevronRight className="size-4" aria-hidden="true" />
                    </IconButton>
                </div>
            ) : null}
        </div>
    );
}

function AnnouncementCard({
    announcement,
    colors,
}: {
    announcement: PhoneAnnouncement;
    colors: PhoneColors;
}) {
    const tone = resolveStatus(ANNOUNCEMENT_KIND, announcement.kind)?.tone ?? 'neutral';
    const Icon = TONE_ICON[tone];

    return (
        <section
            aria-label="Pengumuman"
            className="flex gap-3 rounded-2xl p-4"
            style={{
                backgroundColor: colors.tones[tone].background,
                color: colors.tones[tone].text,
            }}
        >
            <Icon className="mt-0.5 size-5 shrink-0" aria-hidden="true" />
            <div className="space-y-1 min-w-0">
                <p
                    className={cn(
                        'text-sm font-semibold wrap-break-word',
                        !announcement.title && 'italic',
                    )}
                >
                    {announcement.title || 'Judul pengumuman'}
                </p>
                <p
                    className={cn(
                        'text-sm wrap-break-word whitespace-pre-line',
                        !announcement.body && 'italic',
                    )}
                >
                    {announcement.body || 'Isi pengumuman tampil di sini.'}
                </p>
                <p className="text-xs">{announcement.period}</p>
            </div>
        </section>
    );
}

function BannerCard({
    banner,
    current,
    count,
    colors,
}: {
    banner: PhoneBanner;
    current: number;
    count: number;
    colors: PhoneColors;
}) {
    return (
        <section
            aria-label="Banner promo"
            className="overflow-hidden rounded-2xl"
            style={{ backgroundColor: colors.card }}
        >
            <div className="aspect-2/1 w-full">
                {banner.imageUrl ? (
                    <img
                        src={banner.imageUrl}
                        alt={banner.altText || banner.title}
                        className="size-full object-cover"
                    />
                ) : (
                    <div
                        className="flex size-full flex-col items-center justify-center gap-1"
                        style={{ color: colors.muted }}
                    >
                        <ImageOff className="size-6" aria-hidden="true" />
                        <span className="text-xs">Belum ada gambar</span>
                    </div>
                )}
            </div>
            <div className="space-y-2 px-3 pt-2 pb-3">
                <p
                    className={cn(
                        'text-sm font-semibold wrap-break-word',
                        !banner.title && 'italic',
                    )}
                >
                    {banner.title || 'Judul banner'}
                </p>
                {count > 1 ? (
                    <div className="flex justify-center gap-1.5" aria-hidden="true">
                        {Array.from({ length: count }, (_, index) => (
                            <span
                                key={index}
                                className={cn(
                                    'h-2 rounded-full',
                                    index === current ? 'w-5' : 'w-2',
                                )}
                                style={{
                                    backgroundColor:
                                        index === current ? colors.dotActive : colors.dot,
                                }}
                            />
                        ))}
                    </div>
                ) : null}
            </div>
        </section>
    );
}
