import type { RemoteOption } from '@/Components/composite';
import type { Paginated } from '@/types';

export type AnnouncementTab = 'announcements' | 'banners';

/** A location or a package category a row is aimed at. The server sends `code`; a location just picked has its path as `description`. */
export type Target = { id: string; name: string; code?: string; description?: string };

type ContentRow = {
    id: string;
    title: string;
    /** `all` or `targeted`. */
    audience: string;
    /** One of `Announcement::STATUSES`, worked out by the server. */
    status: string;
    /** `Y-m-d H:i` on the company clock. */
    starts_at: string;
    ends_at: string | null;
    /** The publish instant; null while the row is a draft. */
    published_at: string | null;
    location_targets: Target[];
    package_category_targets: Target[];
};

export type AnnouncementRow = ContentRow & {
    body: string;
    kind: string;
};

export type BannerRow = ContentRow & {
    alt_text: string;
    /** The authenticated media route of the image; null when none is stored (any more). */
    image_url: string | null;
    link_kind: string;
    link_target: string | null;
    sort_order: number;
    tap_count: number;
};

/** A live banner of `preview.banners`: what the app carousel lists now. */
export type LiveBanner = Pick<BannerRow, 'id' | 'title' | 'alt_text' | 'image_url' | 'sort_order'>;

export interface CarouselPreview {
    banners: { data: LiveBanner[] };
    /** Live banners the app shows at most. */
    max_banners: number;
    auto_advance_seconds: number;
}

export interface IndexProps extends Record<string, unknown> {
    tab: AnnouncementTab;
    /** Only the list of the open tab is sent; the other is null. */
    announcements: Paginated<AnnouncementRow> | null;
    banners: Paginated<BannerRow> | null;
    counts: { announcements: number; banners: number };
    filters: { tab?: string | null; search?: string | null; status?: string | null };
    kinds: string[];
    audiences: string[];
    appRoutes: string[];
    packageCategories: Target[];
    /** Absent until the first location search, as Inertia sends an optional prop only on request. */
    locationOptions?: RemoteOption[];
    preview: CarouselPreview;
    can: { manage: boolean };
}
