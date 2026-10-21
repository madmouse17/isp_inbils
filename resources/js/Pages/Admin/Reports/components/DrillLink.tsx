import { Link } from '@inertiajs/react';

/** Drill-down URLs of a report page keyed by figure; only the lists the viewer may open are present. */
export type DrillLinks = Record<string, string>;

interface DrillLinkProps {
    /** URL the server built for the figure; absent when the viewer may not open its source rows. */
    href: string | undefined;
    /** Name of the figure, so each link has its own accessible name. */
    label: string;
    /** The row of a breakdown (a status, a category) when the figure is one. */
    group?: string;
    /** What the link says. Two links in one card name what each opens ("Lihat SPK", "Lihat tiket"), so they never read the same. */
    text?: string;
}

export function DrillLink({ href, label, group, text = 'Lihat data' }: DrillLinkProps) {
    if (!href) {
        return null;
    }

    const target =
        group === undefined
            ? href
            : `${href}${href.includes('?') ? '&' : '?'}group=${encodeURIComponent(group)}`;

    return (
        <Link
            href={target}
            // The accessible name starts with the words on the link, so a voice command by the visible text finds it.
            aria-label={`${text} di balik ${label}`}
            className="mt-2 mr-4 inline-block rounded-sm text-sm font-medium text-primary-text underline underline-offset-2 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-hidden"
        >
            {text}
        </Link>
    );
}
