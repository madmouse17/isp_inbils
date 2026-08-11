/** Pure helpers for LatLngMapPicker — kept separate for assert-based checks. */

export type LatLngStrings = {
    lat: string;
    lng: string;
};

export const JAKARTA_CENTER = { lat: -6.2088, lng: 106.8456 } as const;

export function formatCoord(value: number): string {
    return value.toFixed(7);
}

export function parseCoord(value: string | null | undefined): number | null {
    if (value == null || value === '') {
        return null;
    }
    const n = Number(value);
    return Number.isFinite(n) ? n : null;
}

export function resolveCenter(
    lat: string | null | undefined,
    lng: string | null | undefined,
    defaultCenter: { lat: number; lng: number } = JAKARTA_CENTER,
): { lat: number; lng: number } {
    const parsedLat = parseCoord(lat);
    const parsedLng = parseCoord(lng);
    if (parsedLat != null && parsedLng != null) {
        return { lat: parsedLat, lng: parsedLng };
    }
    return defaultCenter;
}

export function toLatLngStrings(lat: number, lng: number): LatLngStrings {
    return { lat: formatCoord(lat), lng: formatCoord(lng) };
}
