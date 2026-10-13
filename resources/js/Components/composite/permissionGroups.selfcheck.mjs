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
    groupPermissions,
    permissionGroupSection,
    setGroupPermissions,
    setPermission,
} = await import('./permissionGroups.ts');

const permissions = [
    { id: 1, name: 'billing.view', group: 'billing' },
    { id: 2, name: 'spk.view', group: 'spk' },
    { id: 3, name: 'spk.create', group: 'spk' },
];

assert.deepEqual(Object.keys(groupPermissions(permissions)), ['spk', 'billing']);

// Sidebar order, then unknown groups alphabetically (literal: update when the sidebar moves).
const everyGroup = [
    'zebra',
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

assert.deepEqual(Object.keys(groupPermissions(everyGroup)), [
    'dashboard',
    'ticket',
    'spk',
    'evaluation',
    'customer',
    'service',
    'location',
    'network_asset',
    'ipam',
    'odn',
    'inventory',
    'billing',
    'portal',
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
    'zebra',
]);

assert.equal(permissionGroupSection('dashboard'), null);
assert.equal(permissionGroupSection('ticket'), 'Operasional');
assert.equal(permissionGroupSection('service'), 'Pelanggan & Layanan');
assert.equal(permissionGroupSection('network_asset'), 'Jaringan');
assert.equal(permissionGroupSection('inventory'), 'Inventaris');
assert.equal(permissionGroupSection('portal'), 'Keuangan & Laporan');
assert.equal(permissionGroupSection('system'), 'Administrasi');
assert.equal(permissionGroupSection('zebra'), 'Other');

// Every seeded permission group must land under a sidebar section, never under "Other".
const seeder = readFileSync(
    new URL('../../../../database/seeders/RolePermissionSeeder.php', import.meta.url),
    'utf8',
);
const seededGroups = new Set(
    [...seeder.matchAll(/'([a-z_]+)\.[a-z_.]+'/g)].map(([, group]) => group),
);
assert.ok(seededGroups.size > 20, 'the seeder permission list was found');
for (const group of seededGroups) {
    assert.notEqual(
        permissionGroupSection(group),
        'Other',
        `seeded group "${group}" has no sidebar section`,
    );
}

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
assert.deepEqual(names('two factor'), ['users.two_factor.reset']);
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
