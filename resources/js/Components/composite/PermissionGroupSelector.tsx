import { Search } from 'lucide-react';
import { Fragment, useId, useMemo, useState } from 'react';
import { Badge, Checkbox, Input } from '@/Components/ui';
import { cn } from '@/lib/utils';
import {
    filterPermissions,
    formatPermission,
    groupPermissions,
    permissionGroupSection,
    setGroupPermissions,
    setPermission,
    type PermissionOption,
} from './permissionGroups';

export interface PermissionGroupSelectorProps {
    permissions: PermissionOption[];
    value: string[];
    onChange: (permissions: string[]) => void;
    error?: string;
}

export function PermissionGroupSelector({
    permissions,
    value,
    onChange,
    error,
}: PermissionGroupSelectorProps) {
    const searchId = useId();
    const [query, setQuery] = useState('');
    // While searching, every list below shows only the matches, so counts and select-all follow them.
    const grouped = useMemo(
        () => groupPermissions(filterPermissions(permissions, query)),
        [permissions, query],
    );
    const groups = Object.entries(grouped);
    const [activeGroup, setActiveGroup] = useState(groups[0]?.[0] ?? '');
    const currentGroup = grouped[activeGroup] ? activeGroup : (groups[0]?.[0] ?? '');
    const currentPermissions = grouped[currentGroup] ?? [];
    const searching = query.trim() !== '';
    const matchCount = groups.reduce((total, [, items]) => total + items.length, 0);

    return (
        <div className="space-y-2">
            <p className="text-sm text-muted-foreground">
                Select a category checkbox to select or clear all permissions in that category.
            </p>
            <div className="max-w-sm">
                <Input
                    id={searchId}
                    type="search"
                    label="Cari hak akses"
                    placeholder="Nama atau label, mis. export"
                    leftIcon={<Search className="h-4 w-4" aria-hidden="true" />}
                    value={query}
                    onChange={(event) => setQuery(event.target.value)}
                    // The selector lives inside the role form: Enter must not submit it.
                    onKeyDown={(event) => {
                        if (event.key === 'Enter') {
                            event.preventDefault();
                        }
                    }}
                />
            </div>
            {searching ? (
                <p role="status" className="text-sm text-muted-foreground">
                    {matchCount === 0
                        ? `Tidak ada hak akses yang cocok dengan "${query.trim()}". Hapus pencarian untuk melihat semua.`
                        : `${matchCount} hak akses cocok di ${groups.length} kategori.`}
                </p>
            ) : null}
            {matchCount === 0 ? null : (
                <div className="grid overflow-hidden rounded-lg border border-border md:grid-cols-[17rem_1fr]">
                    <div className="max-h-120 overflow-y-auto border-b border-border bg-muted/20 md:border-r md:border-b-0">
                        {groups.map(([group, items], index) => {
                            const selectedCount = items.filter((item) =>
                                value.includes(item.name),
                            ).length;
                            const allSelected = selectedCount === items.length;
                            const section = permissionGroupSection(group);
                            const showSection =
                                section !== null &&
                                (index === 0 ||
                                    section !== permissionGroupSection(groups[index - 1][0]));

                            return (
                                <Fragment key={group}>
                                    {showSection ? (
                                        <p className="px-3 pt-3 pb-1 text-sm font-semibold text-muted-foreground">
                                            {section}
                                        </p>
                                    ) : null}
                                    <div
                                        className={cn(
                                            'flex items-center gap-3 border-b border-border px-3 py-2 last:border-b-0',
                                            currentGroup === group && 'bg-muted',
                                        )}
                                    >
                                        <Checkbox
                                            aria-label={`Select all ${formatGroup(group)} permissions`}
                                            checked={allSelected}
                                            indeterminate={selectedCount > 0 && !allSelected}
                                            onCheckedChange={(checked) =>
                                                onChange(
                                                    setGroupPermissions(
                                                        value,
                                                        items,
                                                        checked === true,
                                                    ),
                                                )
                                            }
                                        />
                                        <button
                                            type="button"
                                            className="flex min-w-0 flex-1 items-center justify-between gap-2 rounded-sm text-left focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-hidden"
                                            onClick={() => setActiveGroup(group)}
                                        >
                                            <span className="truncate text-sm font-medium">
                                                {formatGroup(group)}
                                            </span>
                                            <Badge variant={allSelected ? 'success' : 'secondary'}>
                                                {selectedCount}/{items.length}
                                            </Badge>
                                        </button>
                                    </div>
                                </Fragment>
                            );
                        })}
                    </div>

                    <div className="min-h-72 p-4">
                        <div className="mb-4 flex items-center justify-between gap-3">
                            <div>
                                <h3 className="font-semibold">{formatGroup(currentGroup)}</h3>
                                <p className="text-xs text-muted-foreground">
                                    {currentPermissions.length} permissions
                                </p>
                            </div>
                        </div>
                        <div className="grid max-h-96 gap-3 overflow-y-auto pr-2 sm:grid-cols-2 xl:grid-cols-3">
                            {currentPermissions.map((permission) => (
                                <Checkbox
                                    key={permission.id}
                                    label={formatPermission(permission.name, currentGroup)}
                                    title={permission.name}
                                    checked={value.includes(permission.name)}
                                    onCheckedChange={(checked) =>
                                        onChange(
                                            setPermission(value, permission.name, checked === true),
                                        )
                                    }
                                />
                            ))}
                        </div>
                    </div>
                </div>
            )}
            {error ? <p className="text-sm text-red-700 dark:text-red-400">{error}</p> : null}
        </div>
    );
}

function formatGroup(group: string): string {
    if (group.length <= 4) {
        return group.toUpperCase();
    }

    return group.charAt(0).toUpperCase() + group.slice(1).replaceAll('_', ' ');
}
