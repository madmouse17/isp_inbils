import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { registerHooks } from 'node:module';

// permissionGroups.ts imports `@/lib/navigation`; teach Node the alias and the .ts extension.
const root = new URL('../../', import.meta.url);
registerHooks({
    resolve: (specifier, context, next) =>
        next(
            specifier.startsWith('@/') ? new URL(`${specifier.slice(2)}.ts`, root).href : specifier,
            context,
        ),
});

const {
    filterPermissions,
    formatGroup,
    formatPermission,
    groupPermissions,
    permissionGroupSection,
    setGroupPermissions,
    setPermission,
} = await import('./permissionGroups.ts');
const { PERMISSION_GROUP_LABELS, PERMISSION_LABELS } = await import('./permissionLabels.ts');

const permissions = [
    { id: 1, name: 'billing.view', group: 'billing' },
    { id: 2, name: 'spk.view', group: 'spk' },
    { id: 3, name: 'spk.create', group: 'spk' },
];

assert.deepEqual(Object.keys(groupPermissions(permissions)), ['spk', 'billing']);

// Sidebar order, then unknown groups alphabetically (literal: update when the sidebar moves).
const everyGroup = [
    'zebra',
    'announcement',
    'developer',
    'users',
    'roles',
    'report',
    'portal',
    'billing',
    'odn',
    'ipam',
    'network_asset',
    'location',
    'inventory',
    'service',
    'customer',
    'evaluation',
    'ticket',
    'spk',
    'dashboard',
    'system',
    'vehicle',
    'employee',
    'organization',
    'company',
    'alpha',
].map((group, id) => ({ id, name: `${group}.view`, group }));

// Sidebar order, then unknown groups alphabetically (`portal` has no sidebar entry since technician and
// customer logins went app-only, so it sorts with the unknown ones).
assert.deepEqual(Object.keys(groupPermissions(everyGroup)), [
    'dashboard',
    'ticket',
    'spk',
    'evaluation',
    'customer',
    'service',
    'announcement',
    'location',
    'network_asset',
    'ipam',
    'odn',
    'inventory',
    'billing',
    'report',
    'company',
    'organization',
    'employee',
    'vehicle',
    'users',
    'roles',
    'system',
    'developer',
    'alpha',
    'portal',
    'zebra',
]);

assert.equal(permissionGroupSection('dashboard'), null);
assert.equal(permissionGroupSection('ticket'), 'Operasional');
assert.equal(permissionGroupSection('service'), 'Pelanggan & Layanan');
assert.equal(permissionGroupSection('announcement'), 'Pelanggan & Layanan');
assert.equal(permissionGroupSection('network_asset'), 'Jaringan');
assert.equal(permissionGroupSection('inventory'), 'Inventaris');
assert.equal(permissionGroupSection('portal'), 'Lainnya');
assert.equal(permissionGroupSection('system'), 'Administrasi');
assert.equal(permissionGroupSection('zebra'), 'Lainnya');

// Every seeded permission group but `portal` must land under a sidebar section, never under "Lainnya",
// and every seeded group and permission must have its Indonesian label.
const seeder = readFileSync(
    new URL('../../../../database/seeders/RolePermissionSeeder.php', import.meta.url),
    'utf8',
);
const seededNames = new Set([...seeder.matchAll(/'([a-z_]+\.[a-z_.]+)'/g)].map(([, name]) => name));
const seededGroups = new Set([...seededNames].map((name) => name.split('.')[0]));
assert.ok(seededGroups.size > 20, 'the seeder permission list was found');
for (const group of seededGroups) {
    if (group !== 'portal') {
        assert.notEqual(
            permissionGroupSection(group),
            'Lainnya',
            `seeded group "${group}" has no sidebar section`,
        );
    }
    assert.ok(PERMISSION_GROUP_LABELS[group], `seeded group "${group}" has no label`);
}
for (const name of seededNames) {
    assert.ok(PERMISSION_LABELS[name], `seeded permission "${name}" has no label`);
}
assert.deepEqual(
    Object.keys(PERMISSION_LABELS).filter((name) => !seededNames.has(name)),
    [],
    'a label for a permission the seeder does not know',
);

// A name nobody described yet shows its code, never an invented English phrase.
assert.equal(formatPermission('spk.approve'), 'Setujui SPK');
assert.equal(formatPermission('odd.new_thing'), 'odd.new_thing');
assert.equal(formatGroup('network_asset'), 'Aset jaringan');
assert.equal(formatGroup('odd'), 'odd');

assert.deepEqual(setGroupPermissions([], permissions.slice(1), true), ['spk.view', 'spk.create']);
assert.deepEqual(setGroupPermissions(['spk.view', 'billing.view'], permissions.slice(1), false), [
    'billing.view',
]);
assert.deepEqual(setPermission(['spk.view'], 'spk.create', true), ['spk.view', 'spk.create']);
assert.deepEqual(setPermission(['spk.view'], 'spk.view', false), []);

// Search: name or label, case-insensitive, trimmed; blank keeps everything.
const searchable = [
    { id: 1, name: 'billing.invoice.export', group: 'billing' },
    { id: 2, name: 'billing.view', group: 'billing' },
    { id: 3, name: 'system.document.export', group: 'system' },
    { id: 4, name: 'users.two_factor.reset', group: 'users' },
];
const names = (query) => filterPermissions(searchable, query).map(({ name }) => name);

assert.deepEqual(
    names(''),
    searchable.map(({ name }) => name),
);
assert.deepEqual(
    names('   '),
    searchable.map(({ name }) => name),
);
assert.deepEqual(names('  EXPORT '), ['billing.invoice.export', 'system.document.export']);
assert.deepEqual(names('atur ulang 2fa'), ['users.two_factor.reset']);
assert.deepEqual(names('Two_Factor'), ['users.two_factor.reset']);
assert.deepEqual(names('nothing matches this'), []);
assert.deepEqual(Object.keys(groupPermissions(filterPermissions(searchable, 'export'))), [
    'billing',
    'system',
]);

// Select-all in a filtered view only touches the shown permissions.
const shown = filterPermissions(searchable, 'export');
assert.deepEqual(setGroupPermissions(['billing.view'], shown, true), [
    'billing.view',
    'billing.invoice.export',
    'system.document.export',
]);
assert.deepEqual(setGroupPermissions(['billing.view', 'system.document.export'], shown, false), [
    'billing.view',
]);

console.log('permissionGroups.selfcheck.mjs: all assertions passed');
