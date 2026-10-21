import {
    PERMISSION_GROUP_LABELS,
    PERMISSION_LABELS,
} from '@/Components/composite/permissionLabels';
import { navGroups } from '@/lib/navigation';

export interface PermissionOption {
    name: string;
    group: string;
    /** `false` when the user may not give this permission to a role (he does not hold it); absent means he may. */
    grantable?: boolean;
}

export function isGrantable(permission: PermissionOption): boolean {
    return permission.grantable !== false;
}

/** Splits permission names into those the user may give and those he may not (or that nobody knows). */
export function splitGrantable(
    names: readonly string[],
    permissions: PermissionOption[],
): { granted: string[]; skipped: string[] } {
    const grantable = new Set(permissions.filter(isGrantable).map(({ name }) => name));

    return {
        granted: names.filter((name) => grantable.has(name)),
        skipped: names.filter((name) => !grantable.has(name)),
    };
}

const OTHER_SECTION = 'Lainnya';

/**
 * Permission groups in sidebar order, each tied to the sidebar group that first reaches it.
 * An item contributes the prefix of its first `anyOf` permission; flag-only items contribute none.
 * A standalone sidebar entry has no title, so its section is `null`: the editor shows no heading.
 */

const sidebarSections = new Map<string, string | null>();

for (const navGroup of navGroups) {
    for (const item of navGroup.items) {
        const prefix = item.anyOf?.[0].split('.')[0];

        if (!prefix || sidebarSections.has(prefix)) {
            continue;
        }

        sidebarSections.set(prefix, navGroup.title ?? null);
    }
}

const permissionGroupOrder = [...sidebarSections.keys()];

/** Heading shown above a permission group in the editor: its sidebar group title, `null` for none. */
export function permissionGroupSection(group: string): string | null {
    return sidebarSections.has(group) ? (sidebarSections.get(group) ?? null) : OTHER_SECTION;
}

/** What a group is called on screen; a group nobody named yet shows its code. */
export function formatGroup(group: string): string {
    return PERMISSION_GROUP_LABELS[group] ?? group;
}

/** What a permission lets a role do, in words; a permission nobody described yet shows its code. */
export function formatPermission(permission: string): string {
    return PERMISSION_LABELS[permission] ?? permission;
}

/** Permissions whose name or label contains the trimmed query, case-insensitive; a blank query keeps all. */
export function filterPermissions(
    permissions: PermissionOption[],
    query: string,
): PermissionOption[] {
    const needle = query.trim().toLowerCase();

    if (needle === '') {
        return permissions;
    }

    return permissions.filter(
        (permission) =>
            permission.name.toLowerCase().includes(needle) ||
            formatPermission(permission.name).toLowerCase().includes(needle),
    );
}

export function groupPermissions(permissions: PermissionOption[]) {
    const grouped = permissions.reduce<Record<string, PermissionOption[]>>((groups, permission) => {
        groups[permission.group] = [...(groups[permission.group] ?? []), permission];

        return groups;
    }, {});

    return Object.fromEntries(
        Object.entries(grouped).sort(([left], [right]) => {
            const leftIndex = permissionGroupOrder.indexOf(left);
            const rightIndex = permissionGroupOrder.indexOf(right);

            if (leftIndex === -1 && rightIndex === -1) {
                return left.localeCompare(right);
            }

            if (leftIndex === -1) {
                return 1;
            }

            if (rightIndex === -1) {
                return -1;
            }

            return leftIndex - rightIndex;
        }),
    );
}

/** Selects or clears the grantable permissions of a list; one the user may not give is never touched. */
export function setGroupPermissions(
    selected: string[],
    permissions: PermissionOption[],
    checked: boolean,
): string[] {
    const next = new Set(selected);

    for (const permission of permissions.filter(isGrantable)) {
        if (checked) {
            next.add(permission.name);
        } else {
            next.delete(permission.name);
        }
    }

    return [...next];
}

export function setPermission(selected: string[], permission: string, checked: boolean): string[] {
    const next = new Set(selected);

    if (checked) {
        next.add(permission);
    } else {
        next.delete(permission);
    }

    return [...next];
}
