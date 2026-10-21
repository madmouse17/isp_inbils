import { Search } from 'lucide-react';
import { Fragment, useId, useMemo, useState } from 'react';
import { Badge, Checkbox, Input } from '@/Components/ui';
import { cn } from '@/lib/utils';
import {
    filterPermissions,
    formatGroup,
    formatPermission,
    groupPermissions,
    isGrantable,
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

/** Why a box is disabled; every such box points at its own line with `aria-describedby`. */
const NOT_HELD = 'Anda tidak memegang hak akses ini';

export function PermissionGroupSelector({
    permissions,
    value,
    onChange,
    error,
}: PermissionGroupSelectorProps) {
    const searchId = useId();
    const hintPrefix = useId();
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
    const currentLocked = currentPermissions.filter((permission) => !isGrantable(permission));
    const searching = query.trim() !== '';
    const matchCount = groups.reduce((total, [, items]) => total + items.length, 0);
    const anyLocked = permissions.some((permission) => !isGrantable(permission));

    return (
        <div className="space-y-2">
            <p className="text-sm text-muted-foreground">
                Centang kotak kategori untuk memilih atau mengosongkan semua hak akses di kategori
                itu.
                {anyLocked
                    ? ' Hak akses yang tidak bisa dicentang tidak Anda pegang, jadi tidak bisa Anda berikan ke peran.'
                    : ''}
            </p>
            <div className="max-w-sm">
                <Input
                    id={searchId}
                    type="search"
                    label="Cari hak akses"
                    placeholder="Nama atau label, mis. ekspor"
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
                            const grantable = items.filter(isGrantable);
                            const selectedCount = grantable.filter((item) =>
                                value.includes(item.name),
                            ).length;
                            const noneHeld = grantable.length === 0;
                            const allSelected = !noneHeld && selectedCount === grantable.length;
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
                                            aria-label={`Pilih semua hak akses ${formatGroup(group)}`}
                                            checked={allSelected}
                                            disabled={noneHeld}
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
                                            <Badge
                                                variant={
                                                    noneHeld
                                                        ? 'muted'
                                                        : allSelected
                                                          ? 'success'
                                                          : 'secondary'
                                                }
                                            >
                                                {noneHeld
                                                    ? 'Tidak dipegang'
                                                    : `${selectedCount}/${grantable.length}`}
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
                                    {currentPermissions.length} hak akses
                                    {currentLocked.length > 0
                                        ? `, ${currentLocked.length} tidak Anda pegang`
                                        : ''}
                                </p>
                            </div>
                        </div>
                        <div className="grid max-h-96 gap-3 overflow-y-auto pr-2 sm:grid-cols-2 xl:grid-cols-3">
                            {currentPermissions.map((permission) => {
                                const held = isGrantable(permission);
                                const hintId = `${hintPrefix}-${permission.name}`;

                                return (
                                    <div key={permission.name}>
                                        <Checkbox
                                            label={formatPermission(permission.name)}
                                            title={permission.name}
                                            checked={value.includes(permission.name)}
                                            disabled={!held}
                                            aria-describedby={held ? undefined : hintId}
                                            onCheckedChange={(checked) =>
                                                onChange(
                                                    setPermission(
                                                        value,
                                                        permission.name,
                                                        checked === true,
                                                    ),
                                                )
                                            }
                                        />
                                        {held ? null : (
                                            <p
                                                id={hintId}
                                                className="pl-6 text-xs text-muted-foreground"
                                            >
                                                {NOT_HELD}
                                            </p>
                                        )}
                                    </div>
                                );
                            })}
                        </div>
                    </div>
                </div>
            )}
            {error ? <p className="text-sm text-destructive-text">{error}</p> : null}
        </div>
    );
}
