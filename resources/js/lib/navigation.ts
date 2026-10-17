import {
    Archive,
    TrendingUp,
    ArrowLeftRight,
    Banknote,
    Zap,
    Briefcase,
    Building2,
    BarChart3,
    PieChart,
    MessagesSquare,
    ClipboardCheck,
    ClipboardList,
    Clock,
    Cpu,
    Box,
    FileText,
    AlertCircle,
    Folder,
    Globe,
    Hash,
    Home,
    IdCard,
    Inbox,
    Key,
    Search,
    Layers,
    MapPin,
    Megaphone,
    LineChart,
    Group,
    Scale,
    Server,
    Share2,
    ShieldCheck,
    Signal,
    LayoutGrid,
    Star,
    Tag,
    Truck,
    Users,
    UsersRound,
    Wifi,
    Wrench,
    type LucideIcon,
} from 'lucide-react';
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

type NavIcon = LucideIcon;

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
                icon: Home,
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
                icon: Wrench,
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
                icon: Briefcase,
                anyOf: ['ticket.assign', 'ticket.reassign', 'spk.assign', 'spk.reassign'],
                flag: 'isActiveUser',
                badge: ({ workQueues }) => positive(workQueues.dispatchUnassigned),
            },
            {
                label: 'Persetujuan',
                href: '/admin/approvals',
                icon: ClipboardCheck,
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
                icon: ClipboardList,
                anyOf: ['spk.view'],
            },
            {
                label: 'Tiket',
                href: '/admin/tickets',
                icon: MessagesSquare,
                anyOf: ['ticket.view'],
            },
            {
                label: 'Evaluasi',
                href: '/admin/evaluations',
                icon: Star,
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
                icon: Users,
                anyOf: ['customer.view'],
            },
            // The service policies accept `service.manage` as well as `service.view`.
            {
                label: 'Paket Layanan',
                href: '/admin/service-packages',
                icon: Wifi,
                anyOf: ['service.view', 'service.manage'],
            },
            {
                label: 'Kategori Paket',
                href: '/admin/service-package-categories',
                icon: Layers,
                anyOf: ['service.view', 'service.manage'],
            },
            {
                label: 'Profil Bandwidth',
                href: '/admin/bandwidth-profiles',
                icon: Signal,
                anyOf: ['service.view', 'service.manage'],
            },
            {
                label: 'Profil Kecepatan',
                href: '/admin/speed-profiles',
                icon: Zap,
                anyOf: ['service.view', 'service.manage'],
            },
            {
                label: 'Tingkat SLA',
                href: '/admin/sla-tiers',
                icon: Clock,
                anyOf: ['service.view', 'service.manage'],
            },
            {
                label: 'Kategori Tiket',
                href: '/admin/ticket-categories',
                icon: Tag,
                anyOf: ['ticket.category.manage'],
            },
            {
                label: 'Pengumuman & Promo',
                href: '/admin/announcements',
                icon: Megaphone,
                anyOf: ['announcement.manage'],
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
                icon: MapPin,
                anyOf: ['location.view'],
            },
            {
                label: 'Aset Jaringan',
                href: '/admin/network-assets',
                icon: Server,
                anyOf: ['network_asset.view'],
            },
            {
                label: 'Router NAS',
                href: '/admin/nas-devices',
                icon: Cpu,
                anyOf: ['network_asset.nas.view'],
            },
            {
                label: 'Alamat IP',
                href: '/admin/ipam/prefixes',
                icon: Globe,
                anyOf: ['ipam.view'],
            },
            {
                label: 'ODN',
                href: '/admin/odn',
                icon: Share2,
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
                icon: Inbox,
                anyOf: ['inventory.request.view.own', 'inventory.request.view.all'],
            },
            {
                label: 'Barang',
                href: '/admin/products',
                icon: Box,
                anyOf: ['inventory.view'],
            },
            {
                label: 'Kategori',
                href: '/admin/categories',
                icon: Folder,
                anyOf: ['inventory.view'],
            },
            {
                label: 'Satuan',
                href: '/admin/units',
                icon: Scale,
                anyOf: ['inventory.view'],
            },
            {
                label: 'Stok',
                href: '/admin/stocks',
                icon: Archive,
                anyOf: ['inventory.view'],
            },
            {
                label: 'Cari Barang',
                href: '/admin/inventory/find',
                icon: Search,
                anyOf: ['inventory.view'],
            },
            {
                label: 'Mutasi Stok',
                href: '/admin/stock-movements',
                icon: ArrowLeftRight,
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
                icon: Banknote,
                anyOf: ['billing.view'],
            },
            {
                label: 'Tunggakan',
                href: '/admin/billing/receivables',
                icon: AlertCircle,
                anyOf: ['billing.view'],
            },
            {
                label: 'Laporan',
                href: '/admin/reports',
                icon: BarChart3,
                anyOf: ['report.view'],
            },
            {
                // TechnicianReportAccess: the technician role, or a manager placed in an organization.
                // The permission alone cannot tell, so the server shares `canTechnicianReport`.
                label: 'Kinerja Teknisi',
                href: '/admin/reports/technician',
                icon: LineChart,
                anyOf: ['report.technician'],
                flag: 'canTechnicianReport',
            },
            {
                label: 'KPI Penjualan',
                href: '/admin/reports/sales',
                icon: TrendingUp,
                anyOf: ['report.sales.view'],
            },
            {
                // The permission is global; the route also needs the effective company to be a Head,
                // which the server shares as `companyHierarchy.can_consolidate`.
                label: 'Metrik Konsolidasi',
                href: '/admin/reports/consolidated',
                icon: PieChart,
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
                icon: Building2,
                anyOf: ['company.manage'],
            },
            {
                label: 'Organisasi',
                href: '/admin/organizations',
                icon: Group,
                anyOf: ['organization.view'],
            },
            {
                label: 'Karyawan',
                href: '/admin/employees',
                icon: IdCard,
                anyOf: ['employee.view'],
            },
            {
                label: 'Kendaraan',
                href: '/admin/vehicles',
                icon: Truck,
                anyOf: ['vehicle.view'],
            },
            {
                label: 'Pengguna',
                href: '/admin/users',
                icon: UsersRound,
                anyOf: ['users.manage'],
            },
            {
                label: 'Peran',
                href: '/admin/roles',
                icon: ShieldCheck,
                anyOf: ['roles.manage'],
            },
            {
                label: 'Hak Akses',
                href: '/admin/permissions',
                icon: Key,
                anyOf: ['roles.manage'],
            },
            {
                label: 'Dokumen',
                href: '/admin/documents',
                icon: FileText,
                anyOf: ['system.setting'],
            },
            {
                label: 'Penomoran',
                href: '/admin/number-sequences',
                icon: Hash,
                anyOf: ['system.setting'],
            },
            {
                // The route answers 404 outside local/testing, which only the server knows.
                label: 'Komponen',
                href: '/admin/components',
                icon: LayoutGrid,
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
