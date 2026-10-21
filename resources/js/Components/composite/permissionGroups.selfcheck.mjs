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
    isGrantable,
    permissionGroupSection,
    setGroupPermissions,
    setPermission,
    splitGrantable,
} = await import('./permissionGroups.ts');
const { PERMISSION_GROUP_LABELS, PERMISSION_LABELS } = await import('./permissionLabels.ts');

const permissions = [
    { name: 'billing.view', group: 'billing' },
    { name: 'spk.view', group: 'spk' },
    { name: 'spk.create', group: 'spk' },
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
    'app',
].map((group) => ({ name: `${group}.view`, group }));

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
    'app',
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
assert.equal(permissionGroupSection('app'), 'Lainnya');
assert.equal(permissionGroupSection('system'), 'Administrasi');
assert.equal(permissionGroupSection('zebra'), 'Lainnya');

// Every permission group of the catalogue but `portal` and `app` must land under a sidebar section, never
// under "Lainnya" (those two classify the account: they open no menu), and every group and permission must
// have its Indonesian label, and no label may outlive its permission.
const catalogue = readFileSync(
    new URL('../../../../app/Support/PermissionCatalogue.php', import.meta.url),
    'utf8',
);
const constantNames = (constant) => {
    const body = catalogue.match(new RegExp(`const ${constant}\\s*=\\s*\\[([\\s\\S]*?)\\];`))?.[1];
    assert.ok(body !== undefined, `PermissionCatalogue::${constant} was found`);

    return [...body.matchAll(/'([a-z_]+\.[a-z_.]+)'/g)].map(([, name]) => name);
};
const cataloguedNames = new Set(constantNames('ALL'));
const cataloguedGroups = new Set([...cataloguedNames].map((name) => name.split('.')[0]));
assert.ok(cataloguedGroups.size > 20, 'the catalogue permission list was found');
for (const group of cataloguedGroups) {
    if (group !== 'portal' && group !== 'app') {
        assert.notEqual(
            permissionGroupSection(group),
            'Lainnya',
            `catalogue group "${group}" has no sidebar section`,
        );
    }
    assert.ok(PERMISSION_GROUP_LABELS[group], `catalogue group "${group}" has no label`);
}
for (const name of cataloguedNames) {
    assert.ok(PERMISSION_LABELS[name], `catalogue permission "${name}" has no label`);
}
assert.deepEqual(
    Object.keys(PERMISSION_LABELS).filter((name) => !cataloguedNames.has(name)),
    [],
    'a label for a permission the catalogue does not know',
);
for (const name of constantNames('UNGUARDED')) {
    assert.ok(cataloguedNames.has(name), `unguarded permission "${name}" is in the catalogue`);
}

// The five markers of the tenant roles: named in words, not by their codes.
assert.equal(formatPermission('app.web'), 'Masuk aplikasi web (back-office)');
assert.equal(formatPermission('app.technician'), 'Teknisi lapangan (aplikasi Teknisi)');
assert.equal(formatPermission('portal.access'), 'Akun pelanggan (portal dan aplikasi Pelanggan)');
assert.equal(formatPermission('evaluation.evaluable'), 'Dapat dinilai di Evaluasi');
assert.equal(
    formatPermission('report.technician.team'),
    'Laporan kinerja teknisi: unit organisasi sendiri',
);
assert.equal(formatGroup('app'), 'Akses aplikasi');

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

// A permission the user may not give is never selected or cleared by select-all; absent `grantable` means he may.
const mixed = [
    { name: 'spk.view', group: 'spk', grantable: true },
    { name: 'spk.approve', group: 'spk', grantable: false },
    { name: 'spk.create', group: 'spk' },
];
assert.deepEqual(mixed.map(isGrantable), [true, false, true]);
assert.deepEqual(setGroupPermissions([], mixed, true), ['spk.view', 'spk.create']);
assert.deepEqual(setGroupPermissions(['spk.view', 'spk.approve'], mixed, false), ['spk.approve']);
assert.deepEqual(splitGrantable(['spk.view', 'spk.approve', 'odd.unknown'], mixed), {
    granted: ['spk.view'],
    skipped: ['spk.approve', 'odd.unknown'],
});
assert.deepEqual(splitGrantable([], mixed), { granted: [], skipped: [] });

// Search: name or label, case-insensitive, trimmed; blank keeps everything.
const searchable = [
    { name: 'billing.invoice.export', group: 'billing' },
    { name: 'billing.view', group: 'billing' },
    { name: 'system.document.export', group: 'system' },
    { name: 'users.two_factor.reset', group: 'users' },
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
