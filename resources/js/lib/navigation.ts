import {
    ArchiveBoxIcon,
    ArrowTrendingUpIcon,
    ArrowsRightLeftIcon,
    BanknotesIcon,
    BoltIcon,
    BriefcaseIcon,
    BuildingOfficeIcon,
    ChartBarIcon,
    ChartPieIcon,
    ChatBubbleLeftRightIcon,
    ClipboardDocumentCheckIcon,
    ClipboardDocumentListIcon,
    ClockIcon,
    CpuChipIcon,
    CubeIcon,
    DocumentTextIcon,
    ExclamationCircleIcon,
    FolderIcon,
    GlobeAltIcon,
    HashtagIcon,
    HomeIcon,
    IdentificationIcon,
    InboxArrowDownIcon,
    KeyIcon,
    MagnifyingGlassIcon,
    MapPinIcon,
    PresentationChartLineIcon,
    RectangleGroupIcon,
    ScaleIcon,
    ServerStackIcon,
    ShareIcon,
    ShieldCheckIcon,
    SignalIcon,
    Squares2X2Icon,
    StarIcon,
    TruckIcon,
    UserGroupIcon,
    UsersIcon,
    WifiIcon,
    WrenchScrewdriverIcon,
} from '@heroicons/react/24/outline';
import type { PageProps } from '@/types';

/**
 * Sidebar navigation: every group, every item and the capability gate of each item.
 *
 * A gate only decides whether a link is offered. Each one mirrors the gate its destination enforces
 * on the server (policy `viewAny`, route middleware or query eligibility), which stays authoritative.
 *
 * To add an item: add it here and, in the same position, to
 * tests/Feature/fixtures/sidebar-destinations.json. navigation.selfcheck.mjs fails until the two
 * agree; AdminMenuAuthorizationTest then checks that table against the server for every seeded role.
 */

type NavIcon = typeof HomeIcon;

/** Booleans the server shares that gate an item on top of, or instead of, permissions. */
type NavFlag =
    | 'canMyWork'
    | 'canConsolidate'
    | 'developerGalleryEnabled'
    | 'isActiveUser'
    | 'canTechnicianReport';

export interface NavContext {
    /** `auth.user.permissions` */
    permissions: readonly string[];
    /** `MyWorkQuery::isEligible`, computed by the server. */
    canMyWork: boolean;
    /** `companyHierarchy.can_consolidate`: the effective company is a Head and the user may consolidate. */
    canConsolidate: boolean;
    /** True only where the `admin.components` route exists (local/testing), see HandleInertiaRequests. */
    developerGalleryEnabled: boolean;
    /** `auth.user.is_active` */
    isActiveUser: boolean;
    /** `canTechnicianReport`, computed by the Reporting module: the user may open the technician report. */
    canTechnicianReport: boolean;
    workQueues: NonNullable<PageProps['workQueues']>;
}

/** Every item carries a gate: any of some permissions, a flag, or both. An ungated item does not compile. */
type NavGate =
    | { anyOf: readonly [string, ...string[]]; flag?: NavFlag }
    | { anyOf?: undefined; flag: NavFlag };

export type NavItem = NavGate & {
    label: string;
    href: string;
    icon: NavIcon;
    badge?: (ctx: NavContext) => number | undefined;
};

export interface NavGroup {
    id: string;
    /** A group without a title is a standalone entry: no collapsible header. */
    title?: string;
    items: readonly NavItem[];
}

const positive = (count: number | null | undefined) =>
    count != null && count > 0 ? count : undefined;

export const navGroups: readonly NavGroup[] = [
    {
        id: 'dashboard',
        items: [
            {
                label: 'Dasbor',
                href: '/admin/dashboard',
                icon: HomeIcon,
                anyOf: ['dashboard.view'],
            },
        ],
    },
    {
        id: 'operations',
        title: 'Operasional',
        items: [
            {
                // MyWorkQuery::isEligible: work permissions plus an active employee profile.
                label: 'Pekerjaan Saya',
                href: '/admin/my-work',
                icon: WrenchScrewdriverIcon,
                flag: 'canMyWork',
                badge: ({ workQueues }) =>
                    workQueues.myWorkEligible === true
                        ? positive(workQueues.myWorkPending)
                        : undefined,
            },
            {
                // DispatchQueueQuery::isEligible also requires an active user.
                label: 'Penugasan',
                href: '/admin/dispatch',
                icon: BriefcaseIcon,
                anyOf: ['ticket.assign', 'ticket.reassign', 'spk.assign', 'spk.reassign'],
                flag: 'isActiveUser',
                badge: ({ workQueues }) => positive(workQueues.dispatchUnassigned),
            },
            {
                label: 'Persetujuan',
                href: '/admin/approvals',
                icon: ClipboardDocumentCheckIcon,
                anyOf: [
                    'spk.approve',
                    'spk.reject',
                    'inventory.request.approve',
                    'inventory.request.reject',
                ],
                badge: ({ workQueues }) => positive(workQueues.approvalPending),
            },
            {
                label: 'SPK',
                href: '/admin/spk',
                icon: ClipboardDocumentListIcon,
                anyOf: ['spk.view'],
            },
            {
                label: 'Tiket',
                href: '/admin/tickets',
                icon: ChatBubbleLeftRightIcon,
                anyOf: ['ticket.view'],
            },
            {
                label: 'Evaluasi',
                href: '/admin/evaluations',
                icon: StarIcon,
                anyOf: ['evaluation.view', 'evaluation.view.own'],
            },
        ],
    },
    {
        id: 'customers-services',
        title: 'Pelanggan & Layanan',
        items: [
            {
                label: 'Pelanggan',
                href: '/admin/customers',
                icon: UserGroupIcon,
                anyOf: ['customer.view'],
            },
            // The four service policies accept `service.manage` as well as `service.view`.
            {
                label: 'Paket Layanan',
                href: '/admin/service-packages',
                icon: WifiIcon,
                anyOf: ['service.view', 'service.manage'],
            },
            {
                label: 'Profil Bandwidth',
                href: '/admin/bandwidth-profiles',
                icon: SignalIcon,
                anyOf: ['service.view', 'service.manage'],
            },
            {
                label: 'Profil Kecepatan',
                href: '/admin/speed-profiles',
                icon: BoltIcon,
                anyOf: ['service.view', 'service.manage'],
            },
            {
                label: 'Tingkat SLA',
                href: '/admin/sla-tiers',
                icon: ClockIcon,
                anyOf: ['service.view', 'service.manage'],
            },
        ],
    },
    {
        id: 'network',
        title: 'Jaringan',
        items: [
            {
                label: 'Lokasi',
                href: '/admin/locations',
                icon: MapPinIcon,
                anyOf: ['location.view'],
            },
            {
                label: 'Aset Jaringan',
                href: '/admin/network-assets',
                icon: ServerStackIcon,
                anyOf: ['network_asset.view'],
            },
            {
                label: 'Router NAS',
                href: '/admin/nas-devices',
                icon: CpuChipIcon,
                anyOf: ['network_asset.nas.view'],
            },
            {
                label: 'Alamat IP',
                href: '/admin/ipam/prefixes',
                icon: GlobeAltIcon,
                anyOf: ['ipam.view'],
            },
            {
                label: 'ODN',
                href: '/admin/odn',
                icon: ShareIcon,
                anyOf: ['odn.view'],
            },
        ],
    },
    {
        id: 'inventory',
        title: 'Inventaris',
        items: [
            {
                // InventoryRequestPolicy::viewAny; `inventory.view` alone does not open it.
                label: 'Permintaan Barang',
                href: '/admin/inventory/requests',
                icon: InboxArrowDownIcon,
                anyOf: ['inventory.request.view.own', 'inventory.request.view.all'],
            },
            {
                label: 'Barang',
                href: '/admin/products',
                icon: CubeIcon,
                anyOf: ['inventory.view'],
            },
            {
                label: 'Kategori',
                href: '/admin/categories',
                icon: FolderIcon,
                anyOf: ['inventory.view'],
            },
            {
                label: 'Satuan',
                href: '/admin/units',
                icon: ScaleIcon,
                anyOf: ['inventory.view'],
            },
            {
                label: 'Stok',
                href: '/admin/stocks',
                icon: ArchiveBoxIcon,
                anyOf: ['inventory.view'],
            },
            {
                label: 'Cari Barang',
                href: '/admin/inventory/find',
                icon: MagnifyingGlassIcon,
                anyOf: ['inventory.view'],
            },
            {
                label: 'Mutasi Stok',
                href: '/admin/stock-movements',
                icon: ArrowsRightLeftIcon,
                anyOf: ['inventory.view'],
            },
        ],
    },
    {
        id: 'finance-reports',
        title: 'Keuangan & Laporan',
        items: [
            {
                label: 'Tagihan',
                href: '/admin/invoices',
                icon: BanknotesIcon,
                anyOf: ['billing.view'],
            },
            {
                label: 'Tunggakan',
                href: '/admin/billing/receivables',
                icon: ExclamationCircleIcon,
                anyOf: ['billing.view'],
            },
            {
                label: 'Laporan',
                href: '/admin/reports',
                icon: ChartBarIcon,
                anyOf: ['report.view'],
            },
            {
                // TechnicianReportAccess: the technician role, or a manager placed in an organization.
                // The permission alone cannot tell, so the server shares `canTechnicianReport`.
                label: 'Kinerja Teknisi',
                href: '/admin/reports/technician',
                icon: PresentationChartLineIcon,
                anyOf: ['report.technician'],
                flag: 'canTechnicianReport',
            },
            {
                label: 'KPI Penjualan',
                href: '/admin/reports/sales',
                icon: ArrowTrendingUpIcon,
                anyOf: ['report.sales.view'],
            },
            {
                // The permission is global; the route also needs the effective company to be a Head,
                // which the server shares as `companyHierarchy.can_consolidate`.
                label: 'Metrik Konsolidasi',
                href: '/admin/reports/consolidated',
                icon: ChartPieIcon,
                anyOf: ['report.consolidated.view'],
                flag: 'canConsolidate',
            },
        ],
    },
    {
        id: 'administration',
        title: 'Administrasi',
        items: [
            {
                label: 'Perusahaan',
                href: '/admin/company/profile',
                icon: BuildingOfficeIcon,
                anyOf: ['company.manage'],
            },
            {
                label: 'Organisasi',
                href: '/admin/organizations',
                icon: RectangleGroupIcon,
                anyOf: ['organization.view'],
            },
            {
                label: 'Karyawan',
                href: '/admin/employees',
                icon: IdentificationIcon,
                anyOf: ['employee.view'],
            },
            {
                label: 'Kendaraan',
                href: '/admin/vehicles',
                icon: TruckIcon,
                anyOf: ['vehicle.view'],
            },
            {
                label: 'Pengguna',
                href: '/admin/users',
                icon: UsersIcon,
                anyOf: ['users.manage'],
            },
            {
                label: 'Peran',
                href: '/admin/roles',
                icon: ShieldCheckIcon,
                anyOf: ['roles.manage'],
            },
            {
                label: 'Hak Akses',
                href: '/admin/permissions',
                icon: KeyIcon,
                anyOf: ['roles.manage'],
            },
            {
                label: 'Dokumen',
                href: '/admin/documents',
                icon: DocumentTextIcon,
                anyOf: ['system.setting'],
            },
            {
                label: 'Penomoran',
                href: '/admin/number-sequences',
                icon: HashtagIcon,
                anyOf: ['system.setting'],
            },
            {
                // The route answers 404 outside local/testing, which only the server knows.
                label: 'Komponen',
                href: '/admin/components',
                icon: Squares2X2Icon,
                anyOf: ['developer.components.view'],
                flag: 'developerGalleryEnabled',
            },
        ],
    },
];

export interface VisibleNavItem {
    label: string;
    href: string;
    icon: NavIcon;
    badge?: number;
}

export interface VisibleNavGroup {
    id: string;
    title?: string;
    items: VisibleNavItem[];
}

function isVisible(item: NavItem, ctx: NavContext): boolean {
    const permitted =
        item.anyOf === undefined ||
        item.anyOf.some((permission) => ctx.permissions.includes(permission));

    return permitted && (item.flag === undefined || ctx[item.flag]);
}

/** The groups the actor may use, each with only its visible items; a group left empty is dropped. */
export function visibleNavGroups(ctx: NavContext): VisibleNavGroup[] {
    return navGroups
        .map((group) => ({
            id: group.id,
            title: group.title,
            items: group.items
                .filter((item) => isVisible(item, ctx))
                .map(({ label, href, icon, badge }) => ({
                    label,
                    href,
                    icon,
                    badge: badge?.(ctx),
                })),
        }))
        .filter((group) => group.items.length > 0);
}

/** The one item that matches `url`: the longest matching href, so `/admin/reports/technician` beats `/admin/reports`. */
export function activeNavHref(groups: readonly VisibleNavGroup[], url: string): string | null {
    const path = url.split(/[?#]/, 1)[0];
    let active: string | null = null;

    for (const { items } of groups) {
        for (const { href } of items) {
            if (
                (path === href || path.startsWith(`${href}/`)) &&
                href.length > (active?.length ?? 0)
            ) {
                active = href;
            }
        }
    }

    return active;
}
