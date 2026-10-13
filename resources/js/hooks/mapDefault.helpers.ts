/** Pure helpers for useMapDefault — kept separate for assert-based checks. */
import type { CompanyMap } from '@/types';

export type MapDefault = {
    center: { lat: number; lng: number };
    zoom: number;
};

/** All of Indonesia: what a company sees until an admin picks a region. */
export const INDONESIA_MAP_DEFAULT: MapDefault = {
    center: { lat: -2.5489, lng: 118.0149 },
    zoom: 5,
};

export function resolveMapDefault(map: Partial<CompanyMap> | null | undefined): MapDefault {
    const { lat, lng, zoom } = map ?? {};

    if (
        typeof lat !== 'number' ||
        typeof lng !== 'number' ||
        typeof zoom !== 'number' ||
        !Number.isFinite(lat) ||
        !Number.isFinite(lng) ||
        !Number.isFinite(zoom)
    ) {
        return INDONESIA_MAP_DEFAULT;
    }

    return { center: { lat, lng }, zoom };
}
