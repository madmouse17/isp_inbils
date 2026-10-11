import {
    ArchiveBoxIcon,
    BanknotesIcon,
    BriefcaseIcon,
    BuildingOfficeIcon,
    ChartBarIcon,
    ChatBubbleLeftRightIcon,
    ClipboardDocumentCheckIcon,
    ClipboardDocumentIcon,
    ClipboardDocumentListIcon,
    HomeIcon,
    KeyIcon,
    MapPinIcon,
    ServerStackIcon,
    ShieldCheckIcon,
    Squares2X2Icon,
    TagIcon,
    TruckIcon,
    UsersIcon,
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
type NavFlag = 'canMyWork' | 'canConsolidate' | 'developerGalleryEnabled' | 'isActiveUser';

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
                label: 'Dashboard',
                href: '/admin/dashboard',
                icon: HomeIcon,
                anyOf: ['dashboard.view'],
            },
        ],
    },
    {
        id: 'company-admin',
        title: 'Company/Admin',
        items: [
            {
                label: 'Organization',
                href: '/admin/organizations',
                icon: BuildingOfficeIcon,
                anyOf: ['organization.view'],
            },
            {
                label: 'Company',
                href: '/admin/company/profile',
                icon: BuildingOfficeIcon,
                anyOf: ['company.manage'],
            },
            {
                label: 'Users',
                href: '/admin/users',
                icon: UsersIcon,
                anyOf: ['users.manage'],
            },
            {
                label: 'Roles',
                href: '/admin/roles',
                icon: ShieldCheckIcon,
                anyOf: ['roles.manage'],
            },
            {
                label: 'Permissions',
                href: '/admin/permissions',
                icon: KeyIcon,
                anyOf: ['users.manage'],
            },
            {
                label: 'Documents',
                href: '/admin/documents',
                icon: ClipboardDocumentIcon,
                anyOf: ['system.setting'],
            },
            {
                label: 'Number Sequences',
                href: '/admin/number-sequences',
                icon: KeyIcon,
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
    {
        id: 'crm-operations',
        title: 'CRM/Operations',
        items: [
            {
                label: 'Customers',
                href: '/admin/customers',
                icon: BriefcaseIcon,
                anyOf: ['customer.view'],
            },
            {
                label: 'Locations',
                href: '/admin/locations',
                icon: MapPinIcon,
                anyOf: ['location.view'],
            },
            {
                label: 'Employees',
                href: '/admin/employees',
                icon: UsersIcon,
                anyOf: ['employee.view'],
            },
            {
                label: 'Vehicles',
                href: '/admin/vehicles',
                icon: TruckIcon,
                anyOf: ['vehicle.view'],
            },
        ],
    },
    {
        id: 'service',
        title: 'Service',
        // The four service policies accept `service.manage` as well as `service.view`.
        items: [
            {
                label: 'Service',
                href: '/admin/service-packages',
                icon: TagIcon,
                anyOf: ['service.view', 'service.manage'],
            },
            {
                label: 'Bandwidth Profiles',
                href: '/admin/bandwidth-profiles',
                icon: WrenchScrewdriverIcon,
                anyOf: ['service.view', 'service.manage'],
            },
            {
                label: 'Speed Profiles',
                href: '/admin/speed-profiles',
                icon: WrenchScrewdriverIcon,
                anyOf: ['service.view', 'service.manage'],
            },
            {
                label: 'SLA Tiers',
                href: '/admin/sla-tiers',
                icon: WrenchScrewdriverIcon,
                anyOf: ['service.view', 'service.manage'],
            },
        ],
    },
    {
        id: 'inventory',
        title: 'Inventory',
        items: [
            {
                // InventoryRequestPolicy::viewAny; `inventory.view` alone does not open it.
                label: 'Permintaan Barang',
                href: '/admin/inventory/requests',
                icon: ClipboardDocumentListIcon,
                anyOf: ['inventory.request.view.own', 'inventory.request.view.all'],
            },
            {
                label: 'Inventory',
                href: '/admin/products',
                icon: TagIcon,
                anyOf: ['inventory.view'],
            },
            {
                label: 'Categories',
                href: '/admin/categories',
                icon: ArchiveBoxIcon,
                anyOf: ['inventory.view'],
            },
            {
                label: 'Units',
                href: '/admin/units',
                icon: ArchiveBoxIcon,
                anyOf: ['inventory.view'],
            },
            {
                label: 'Stocks',
                href: '/admin/stocks',
                icon: ArchiveBoxIcon,
                anyOf: ['inventory.view'],
            },
            {
                label: 'Item Finder',
                href: '/admin/inventory/find',
                icon: ArchiveBoxIcon,
                anyOf: ['inventory.view'],
            },
            {
                label: 'Stock Movements',
                href: '/admin/stock-movements',
                icon: ArchiveBoxIcon,
                anyOf: ['inventory.view'],
            },
        ],
    },
    {
        id: 'network-work-orders',
        title: 'Network/Work Orders',
        items: [
            {
                label: 'Network Assets',
                href: '/admin/network-assets',
                icon: ServerStackIcon,
                anyOf: ['network_asset.view'],
            },
            {
                label: 'SPK',
                href: '/admin/spk',
                icon: ClipboardDocumentListIcon,
                anyOf: ['spk.view'],
            },
            {
                label: 'Approval',
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
                // DispatchQueueQuery::isEligible also requires an active user.
                label: 'Dispatch',
                href: '/admin/dispatch',
                icon: BriefcaseIcon,
                anyOf: ['ticket.assign', 'ticket.reassign', 'spk.assign', 'spk.reassign'],
                flag: 'isActiveUser',
                badge: ({ workQueues }) => positive(workQueues.dispatchUnassigned),
            },
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
        ],
    },
    {
        id: 'finance-reports',
        title: 'Finance/Reports',
        items: [
            {
                label: 'Billing',
                href: '/admin/invoices',
                icon: BanknotesIcon,
                anyOf: ['billing.view'],
            },
            {
                label: 'Tunggakan',
                href: '/admin/billing/receivables',
                icon: BanknotesIcon,
                anyOf: ['billing.view'],
            },
            {
                label: 'Tagihan Saya',
                href: '/portal/invoices',
                icon: BanknotesIcon,
                anyOf: ['portal.invoice.view'],
            },
            {
                label: 'Ticketing',
                href: '/admin/tickets',
                icon: ChatBubbleLeftRightIcon,
                anyOf: ['ticket.view'],
            },
            {
                label: 'Evaluations',
                href: '/admin/evaluations',
                icon: ClipboardDocumentListIcon,
                anyOf: ['evaluation.view', 'evaluation.view.own'],
            },
            {
                label: 'Reports',
                href: '/admin/reports',
                icon: ChartBarIcon,
                anyOf: ['report.view'],
            },
            {
                // shortcut: the route gate only. ReportController also needs the technician role or,
                // for managers, an organization; upgrade by sharing a server-computed flag.
                label: 'Technician Performance',
                href: '/admin/reports/technician',
                icon: ChartBarIcon,
                anyOf: ['report.technician'],
            },
            {
                label: 'Sales KPI',
                href: '/admin/reports/sales',
                icon: ChartBarIcon,
                anyOf: ['report.sales.view'],
            },
            {
                // The permission is global; the route also needs the effective company to be a Head,
                // which the server shares as `companyHierarchy.can_consolidate`.
                label: 'Consolidated Metrics',
                href: '/admin/reports/consolidated',
                icon: ChartBarIcon,
                anyOf: ['report.consolidated.view'],
                flag: 'canConsolidate',
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
