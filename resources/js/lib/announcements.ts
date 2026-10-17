/**
 * Helpers of the Pengumuman & Promo page (`Pages/Admin/Announcements`): the banner image rule the
 * server enforces, the order of the app carousel, the company clock for a new schedule and the
 * server message of a list field.
 */

/** The first server message of a field or of any item of that list (`location_ids`, `location_ids.0`). */
export function fieldError(
    errors: Readonly<Record<string, string | undefined>>,
    field: string,
): string | undefined {
    return (
        errors[field] ?? Object.entries(errors).find(([key]) => key.startsWith(`${field}.`))?.[1]
    );
}

/** 2:1 within 5 percent, in integers so the page and `SavePromoBannerRequest::bannerRatio` agree on every size. */
export function isBannerRatio(width: number, height: number): boolean {
    return height > 0 && width * 10 >= height * 19 && width * 10 <= height * 21;
}

/** Pixel size of an image file as stored: the server measures it before any EXIF rotation, so this does too. */
export async function readImageSize(file: Blob): Promise<{ width: number; height: number }> {
    const bitmap = await createImageBitmap(file, { imageOrientation: 'none' });
    const size = { width: bitmap.width, height: bitmap.height };
    bitmap.close();

    return size;
}

/** Newest first by public id: a ULID sorts by creation time. An unsaved draft has no id and counts as the newest. */
function newestFirst(a: string | null, b: string | null): number {
    if (a === b) return 0;
    if (a === null) return -1;
    if (b === null) return 1;

    return a < b ? 1 : -1;
}

/**
 * The carousel as the app lists it (`sort_order`, then newest first) once `draft` is live among `live`,
 * cut to the `max` the app shows. `index` is the draft's slide, or null when it falls past the cut.
 * A draft that is not saved yet has no id and counts as the newest.
 */
export function placeInCarousel<T extends { id: string | null; sortOrder: number }>(
    live: readonly T[],
    draft: T,
    max: number,
): { banners: T[]; index: number | null } {
    const ordered = [...live, draft].sort(
        (a, b) => a.sortOrder - b.sortOrder || newestFirst(a.id, b.id),
    );
    const index = ordered.indexOf(draft);

    return { banners: ordered.slice(0, max), index: index < max ? index : null };
}

/** The company clock now as `Y-m-d H:i`, the value a date-time picker holds; the browser zone when the zone name is unknown. */
export function nowInZone(timeZone: string | null | undefined, now: Date = new Date()): string {
    const options: Intl.DateTimeFormatOptions = {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        hourCycle: 'h23',
    };
    let formatter: Intl.DateTimeFormat;
    try {
        formatter = new Intl.DateTimeFormat('en-US', {
            ...options,
            timeZone: timeZone ?? undefined,
        });
    } catch {
        formatter = new Intl.DateTimeFormat('en-US', options);
    }
    const parts = formatter.formatToParts(now);
    const part = (type: string) => parts.find((entry) => entry.type === type)?.value ?? '';

    return `${part('year')}-${part('month')}-${part('day')} ${part('hour')}:${part('minute')}`;
}
