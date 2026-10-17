import type { Company } from '@/types';

/** Capability group of a card, in the order the server returns them. */
export type DashboardGroup = 'operations' | 'approvals' | 'warehouse' | 'finance';

/** The server sends `neutral` whenever the count is 0. */
export type DashboardSeverity = 'neutral' | 'info' | 'warning' | 'danger';

interface SectionBase<K extends string, G extends DashboardGroup> {
    key: K;
    group: G;
    /** Indonesian copy from the server. */
    title: string;
    /** Absolute URL of a page the actor can open. */
    href: string;
    severity: DashboardSeverity;
}

interface CountSection<K extends string, G extends DashboardGroup> extends SectionBase<K, G> {
    count: number;
}

export interface SlaTicketItem {
    id: string;
    code: string;
    title: string;
    priority: 'low' | 'medium' | 'high' | 'urgent';
    status: 'open' | 'assigned' | 'on_progress';
    /** ISO 8601, UTC. */
    sla_deadline: string;
    href: string;
}

export type DashboardSection =
    | (CountSection<'my_work', 'operations'> & {
          /** `waiting_action` overlaps `in_progress`, so the parts do not add up to `count`. */
          breakdown: { assigned: number; in_progress: number; waiting_action: number };
      })
    | (CountSection<'dispatch_unassigned', 'operations'> & {
          breakdown: { tickets: number; spk: number };
      })
    /** At most five items, oldest deadline first; empty when the count is 0. */
    | (CountSection<'sla_breached', 'operations'> & { items: SlaTicketItem[] })
    | CountSection<'approvals', 'approvals'>
    | CountSection<'warehouse_requests', 'warehouse'>
    /** `count` is awaiting receipt plus exceptions. */
    | (CountSection<'return_queue', 'warehouse'> & { breakdown: { exceptions: number } })
    /** Stock rows (product x location), as the Stocks list shows them. */
    | CountSection<'low_stock', 'warehouse'>
    /** `outstanding` is a decimal string with two decimals, for example "1550000.50". */
    | (CountSection<'overdue_invoices', 'finance'> & { outstanding: string });

export interface DashboardNotification {
    id: string;
    type: string;
    title: string;
    message: string;
    created_at: string | null;
    read_at: string | null;
    detail_url: string | null;
}

export interface DashboardActivityItem {
    id: string;
    log_name: string | null;
    description: string;
    created_at: string | null;
}

export interface DashboardAdminModule {
    name: string;
    href: string;
    count: number;
}

export interface DashboardAdminStats {
    userCount: number;
    activeUserCount: number;
    /** Global: Spatie roles are not company scoped. */
    roleCount: number;
    /** Global: Spatie permissions are not company scoped. */
    permissionCount: number;
    /** At most ten. */
    recentActivity: DashboardActivityItem[];
    /** Only the modules whose view permission the actor holds. */
    modules: DashboardAdminModule[];
}

export interface DashboardProps {
    company: Company | null;
    /** Ordered by group. Empty means the actor has no queue to work. */
    sections: DashboardSection[];
    /** `data` holds the latest five; `unreadCount` is the full count. */
    notifications: { data: DashboardNotification[]; unreadCount: number };
    /** The key is absent unless the actor holds users.manage, roles.manage or company.manage. */
    admin?: DashboardAdminStats;
}
