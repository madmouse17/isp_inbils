/*
 * Recommendations a form may offer because the system computes them from its own data: no guessing,
 * no free text. Each helper returns null when its input cannot give one answer.
 */

const EARTH_RADIUS_KM = 6371;
const toRadians = (degrees: number) => (degrees * Math.PI) / 180;

export interface GeoPoint {
    lat: number;
    lng: number;
}

/** Great-circle distance in kilometres. */
export function haversineKm(from: GeoPoint, to: GeoPoint): number {
    const dLat = toRadians(to.lat - from.lat);
    const dLng = toRadians(to.lng - from.lng);
    const a =
        Math.sin(dLat / 2) ** 2 +
        Math.cos(toRadians(from.lat)) * Math.cos(toRadians(to.lat)) * Math.sin(dLng / 2) ** 2;

    return 2 * EARTH_RADIUS_KM * Math.asin(Math.sqrt(a));
}

/** A coordinate pair from text or numbers; null when either part is blank, not a number or out of range. */
export function toGeoPoint(
    lat: string | number | null | undefined,
    lng: string | number | null | undefined,
): GeoPoint | null {
    if (lat === null || lat === undefined || lng === null || lng === undefined) return null;
    if (String(lat).trim() === '' || String(lng).trim() === '') return null;

    const point = { lat: Number(lat), lng: Number(lng) };

    return Number.isFinite(point.lat) &&
        Number.isFinite(point.lng) &&
        Math.abs(point.lat) <= 90 &&
        Math.abs(point.lng) <= 180
        ? point
        : null;
}

/** The location closest to `from`, among those that have coordinates; the first one wins a tie. */
export function nearestLocation<
    T extends { lat?: string | number | null; lng?: string | number | null },
>(locations: T[], from: GeoPoint): { location: T; km: number } | null {
    let best: { location: T; km: number } | null = null;

    for (const location of locations) {
        const point = toGeoPoint(location.lat, location.lng);
        if (point === null) continue;

        const km = haversineKm(from, point);
        if (best === null || km < best.km) best = { location, km };
    }

    return best;
}

/**
 * The lowest free drop port of the ODP the typed search narrowed to. The server lists free ports ordered by
 * ODP code, splitter and port number, and starts each label with the ODP code; when the list spans more than
 * one ODP nothing is the lowest of "the" ODP, so there is no answer.
 */
export function lowestFreeDropPort<T extends { label: string }>(
    options: T[] | undefined,
): T | null {
    if (!options || options.length === 0) return null;

    const odpOf = (option: T) => option.label.split(' · ')[0];

    return options.every((option) => odpOf(option) === odpOf(options[0])) ? options[0] : null;
}

/** The person with the fewest open jobs; the first one in the list wins a tie. */
export function lowestLoad<T extends { open_today: number }>(people: T[]): T | null {
    let best: T | null = null;

    for (const person of people) {
        if (best === null || person.open_today < best.open_today) best = person;
    }

    return best;
}

const DAY = /^(\d{4})-(\d{2})-(\d{2})$/;
const pad2 = (value: number) => String(value).padStart(2, '0');

/** Today as `Y-m-d` in the browser's calendar. */
export function todayDay(now: Date = new Date()): string {
    return `${now.getFullYear()}-${pad2(now.getMonth() + 1)}-${pad2(now.getDate())}`;
}

/** `Y-m-d` plus whole days; null when `day` is not a real date. */
export function addDays(day: string, days: number): string | null {
    const match = DAY.exec(day);
    if (!match || !Number.isFinite(days)) return null;

    const [year, month, date] = [Number(match[1]), Number(match[2]), Number(match[3])];
    const result = new Date(Date.UTC(year, month - 1, date + days));
    const real = new Date(Date.UTC(year, month - 1, date));
    if (real.getUTCMonth() !== month - 1 || real.getUTCDate() !== date) return null;

    return `${result.getUTCFullYear()}-${pad2(result.getUTCMonth() + 1)}-${pad2(result.getUTCDate())}`;
}

interface ParsedCidr {
    family: 4 | 6;
    length: number;
    /** The address as one integer. */
    address: bigint;
}

function parseV4(text: string): bigint | null {
    const parts = text.split('.');
    if (parts.length !== 4) return null;

    let address = 0n;
    for (const part of parts) {
        if (!/^\d{1,3}$/.test(part) || Number(part) > 255) return null;
        address = (address << 8n) | BigInt(part);
    }

    return address;
}

function parseV6(text: string): bigint | null {
    if (!/^[0-9a-f:]+$/i.test(text) || text.includes(':::')) return null;

    const halves = text.split('::');
    if (halves.length > 2) return null;

    const groups = (half: string) => (half === '' ? [] : half.split(':'));
    const head = groups(halves[0]);
    const tail = halves.length === 2 ? groups(halves[1]) : [];
    const missing = 8 - head.length - tail.length;
    if (halves.length === 1 ? missing !== 0 : missing < 1) return null;

    const all = [...head, ...Array<string>(halves.length === 2 ? missing : 0).fill('0'), ...tail];
    let address = 0n;
    for (const group of all) {
        if (!/^[0-9a-f]{1,4}$/i.test(group)) return null;
        address = (address << 16n) | BigInt(`0x${group}`);
    }

    return address;
}

/** `10.20.0.0/22` or `2001:db8::/48`; null for anything else (an IPv4-mapped IPv6 form is not read). */
export function parseCidr(text: string): ParsedCidr | null {
    const [host, length, ...rest] = text.trim().split('/');
    if (rest.length > 0 || !host || !/^\d{1,3}$/.test(length ?? '')) return null;

    const family = host.includes(':') ? 6 : 4;
    const address = family === 6 ? parseV6(host) : parseV4(host);

    return address !== null && Number(length) <= (family === 6 ? 128 : 32)
        ? { family, length: Number(length), address }
        : null;
}

/** The deepest prefix of `rows` that strictly contains `cidr`; null when none does or `cidr` is not valid yet. */
export function parentPrefixOf<T extends { cidr: string }>(cidr: string, rows: T[]): T | null {
    const child = parseCidr(cidr);
    if (child === null) return null;

    let best: { row: T; length: number } | null = null;

    for (const row of rows) {
        const parent = parseCidr(row.cidr);
        if (
            parent === null ||
            parent.family !== child.family ||
            parent.length >= child.length ||
            (best !== null && parent.length <= best.length)
        ) {
            continue;
        }

        const shift = BigInt((parent.family === 6 ? 128 : 32) - parent.length);
        if (parent.address >> shift === child.address >> shift)
            best = { row, length: parent.length };
    }

    return best?.row ?? null;
}
