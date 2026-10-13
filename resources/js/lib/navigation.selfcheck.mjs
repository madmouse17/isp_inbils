/**
 * Node selfcheck for the sidebar navigation config (no test runner dependency).
 * Run: node --test resources/js/lib/navigation.selfcheck.mjs
 *
 * Two legs. The config must equal tests/Feature/fixtures/sidebar-destinations.json (the table
 * AdminMenuAuthorizationTest checks against the server), and the outcomes the owner approved in
 * .scratch/sidebar-ia/spec.md must hold. Expected values are literals, never computed from the config.
 */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { activeNavHref, navGroups, visibleNavGroups } from './navigation.ts';

const contract = JSON.parse(
    readFileSync(
        new URL('../../../tests/Feature/fixtures/sidebar-destinations.json', import.meta.url),
        'utf8',
    ),
).destinations;

const closed = {
    canMyWork: false,
    canConsolidate: false,
    developerGalleryEnabled: false,
    isActiveUser: true,
    canTechnicianReport: false,
    workQueues: {},
};
const view = (permissions, flags = {}) => visibleNavGroups({ ...closed, permissions, ...flags });
const outline = (groups) =>
    groups.map((group) => [group.title ?? null, group.items.map((i) => i.label)]);

const inventoryItems = [
    'Permintaan Barang',
    'Barang',
    'Kategori',
    'Satuan',
    'Stok',
    'Cari Barang',
    'Mutasi Stok',
];

test('config equals the destination table the server is checked against', () => {
    const items = navGroups.flatMap((group) =>
        group.items.map((item) => ({
            group: group.title ?? null,
            label: item.label,
            href: item.href,
            anyOf: item.anyOf ?? [],
            flag: item.flag ?? null,
        })),
    );

    assert.deepEqual(
        items,
        contract.map(({ group, label, href, anyOf, flag }) => ({
            group,
            label,
            href,
            anyOf,
            flag: flag ?? null,
        })),
    );
});

test('seven entries at most: six titled groups and a standalone Dashboard', () => {
    assert.ok(navGroups.length <= 7);
    assert.deepEqual(
        navGroups.map((group) => group.title ?? null),
        [
            null,
            'Operasional',
            'Pelanggan & Layanan',
            'Jaringan',
            'Inventaris',
            'Keuangan & Laporan',
            'Administrasi',
        ],
    );
    assert.deepEqual(
        navGroups[0].items.map((item) => item.label),
        ['Dasbor'],
    );

    const items = navGroups.flatMap((group) => group.items);
    assert.equal(new Set(items.map((item) => item.href)).size, items.length);
    assert.equal(new Set(items.map((item) => item.label)).size, items.length);
    assert.equal(new Set(items.map((item) => item.icon)).size, items.length, 'one icon per item');
    assert.ok(items.every((item) => item.href.startsWith('/admin/')));
    assert.ok(items.every((item) => (item.anyOf?.length ?? 0) > 0 || item.flag !== undefined));
});

test('a user with nothing sees nothing, and no group header outlives its items', () => {
    assert.deepEqual(view([]), []);
    assert.deepEqual(outline(view(['ticket.view'])), [['Operasional', ['Tiket']]]);
});

test('dashboard needs dashboard.view (decision 3a)', () => {
    assert.deepEqual(outline(view(['dashboard.view'])), [[null, ['Dasbor']]]);
    assert.ok(!outline(view(['ticket.view'])).some(([, labels]) => labels.includes('Dasbor')));
});

test('customer sees Dasbor and Tiket only; the portal invoices live in the app', () => {
    const customer = [
        'dashboard.view',
        'ticket.view',
        'ticket.create',
        'evaluation.customer.submit',
        'portal.invoice.view',
        'portal.invoice.pay',
    ];

    assert.deepEqual(outline(view(customer)), [
        [null, ['Dasbor']],
        ['Operasional', ['Tiket']],
    ]);
});

test('technician sees no Administration group and no service items', () => {
    const technician = [
        'dashboard.view',
        'customer.view',
        'network_asset.view',
        'location.view',
        'inventory.view',
        'inventory.request.view.own',
        'spk.view',
        'spk.start',
        'ticket.view',
        'ticket.start',
        'evaluation.view.own',
        'report.technician',
        'ipam.view',
        'odn.view',
    ];

    assert.deepEqual(outline(view(technician, { canMyWork: true, canTechnicianReport: true })), [
        [null, ['Dasbor']],
        ['Operasional', ['Pekerjaan Saya', 'SPK', 'Tiket', 'Evaluasi']],
        ['Pelanggan & Layanan', ['Pelanggan']],
        ['Jaringan', ['Lokasi', 'Aset Jaringan', 'Alamat IP', 'ODN']],
        ['Inventaris', inventoryItems],
        ['Keuangan & Laporan', ['Kinerja Teknisi']],
    ]);
});

test('manager keeps Approval and Dispatch but no administration (story 4)', () => {
    const manager = [
        'dashboard.view',
        'customer.view',
        'location.view',
        'service.view',
        'service.manage',
        'inventory.view',
        'inventory.request.view.all',
        'inventory.request.approve',
        'network_asset.view',
        'spk.view',
        'spk.assign',
        'spk.approve',
        'billing.view',
        'ticket.view',
        'ticket.assign',
        'evaluation.view',
        'report.view',
        'report.technician',
    ];
    const titles = view(manager).map((group) => group.title);
    const labels = view(manager).flatMap((group) => group.items.map((item) => item.label));

    assert.ok(!titles.includes('Administrasi'));
    assert.ok(
        ['Persetujuan', 'Penugasan', 'Paket Layanan'].every((label) => labels.includes(label)),
    );
    assert.ok(
        ['Pengguna', 'Peran', 'Hak Akses', 'Karyawan', 'Kendaraan', 'Dokumen'].every(
            (label) => !labels.includes(label),
        ),
    );
});

test('Permintaan Barang needs a request capability, inventory.view alone is not enough (5a)', () => {
    assert.deepEqual(outline(view(['inventory.view'])), [['Inventaris', inventoryItems.slice(1)]]);
    assert.deepEqual(outline(view(['inventory.request.view.own'])), [
        ['Inventaris', ['Permintaan Barang']],
    ]);
    assert.deepEqual(outline(view(['inventory.request.view.all'])), [
        ['Inventaris', ['Permintaan Barang']],
    ]);
});

test('service.manage alone opens every service item (6a)', () => {
    assert.deepEqual(outline(view(['service.manage'])), [
        [
            'Pelanggan & Layanan',
            ['Paket Layanan', 'Profil Bandwidth', 'Profil Kecepatan', 'Tingkat SLA'],
        ],
    ]);
});

test('system.setting puts Documents and Number Sequences under Administration only (7a)', () => {
    assert.deepEqual(outline(view(['system.setting'])), [
        ['Administrasi', ['Dokumen', 'Penomoran']],
    ]);
});

test('Komponen needs the permission and the environment flag (2a)', () => {
    const component = ['developer.components.view'];

    assert.deepEqual(view(component), []);
    assert.deepEqual(view([], { developerGalleryEnabled: true }), []);
    assert.deepEqual(outline(view(component, { developerGalleryEnabled: true })), [
        ['Administrasi', ['Komponen']],
    ]);
});

test('IP Addresses, ODN and NAS Routers each follow their own view permission', () => {
    assert.deepEqual(outline(view(['ipam.view'])), [['Jaringan', ['Alamat IP']]]);
    assert.deepEqual(outline(view(['odn.view'])), [['Jaringan', ['ODN']]]);
    assert.deepEqual(outline(view(['network_asset.nas.view'])), [['Jaringan', ['Router NAS']]]);
    // Managing or assigning alone opens no page: the pages are gated by view.
    assert.deepEqual(view(['ipam.manage', 'ipam.assign', 'odn.manage', 'odn.assign']), []);
});

test('Dispatch follows assign or reassign for an active user', () => {
    for (const permission of ['ticket.assign', 'ticket.reassign', 'spk.assign', 'spk.reassign']) {
        assert.deepEqual(outline(view([permission])), [['Operasional', ['Penugasan']]]);
    }
    assert.deepEqual(view(['ticket.assign'], { isActiveUser: false }), []);
});

test('Pekerjaan Saya follows the server flag, not a permission', () => {
    assert.deepEqual(outline(view([], { canMyWork: true })), [['Operasional', ['Pekerjaan Saya']]]);
    assert.deepEqual(view(['spk.start', 'ticket.start']), []);
});

test('Approval is offered for any approve or reject capability', () => {
    for (const permission of [
        'spk.approve',
        'spk.reject',
        'inventory.request.approve',
        'inventory.request.reject',
    ]) {
        assert.deepEqual(outline(view([permission])), [['Operasional', ['Persetujuan']]]);
    }
});

test('Evaluations accepts the own-scope capability', () => {
    assert.deepEqual(outline(view(['evaluation.view.own'])), [['Operasional', ['Evaluasi']]]);
});

test('Kinerja Teknisi needs report.technician and the server flag, the permission alone is not enough', () => {
    const report = ['report.technician'];

    assert.deepEqual(view(report), []);
    assert.deepEqual(view([], { canTechnicianReport: true }), []);
    assert.deepEqual(outline(view(report, { canTechnicianReport: true })), [
        ['Keuangan & Laporan', ['Kinerja Teknisi']],
    ]);
});

test('Hak Akses follows roles.manage, users.manage alone does not open it', () => {
    assert.deepEqual(outline(view(['roles.manage'])), [['Administrasi', ['Peran', 'Hak Akses']]]);
    assert.deepEqual(outline(view(['users.manage'])), [['Administrasi', ['Pengguna']]]);
});

test('Consolidated Metrics needs the permission and a Head company, which only the server knows', () => {
    const consolidate = ['report.consolidated.view'];

    assert.deepEqual(view(consolidate), []);
    assert.deepEqual(view([], { canConsolidate: true }), []);
    assert.deepEqual(outline(view(consolidate, { canConsolidate: true })), [
        ['Keuangan & Laporan', ['Metrik Konsolidasi']],
    ]);
});

test('an administrator sees all items in seven entries', () => {
    const everything = [...new Set(contract.flatMap((destination) => destination.anyOf))];
    const groups = view(everything, {
        canMyWork: true,
        canConsolidate: true,
        developerGalleryEnabled: true,
        canTechnicianReport: true,
    });

    assert.equal(groups.length, 7);
    assert.equal(groups.flatMap((group) => group.items).length, contract.length);
    assert.ok(groups.every((group) => group.items.length > 0));
});

test('queue badges appear only for a positive count, and Pekerjaan Saya only when eligible', () => {
    const permissions = ['spk.approve', 'ticket.assign'];
    const badges = (workQueues, flags = {}) =>
        Object.fromEntries(
            view(permissions, { canMyWork: true, workQueues, ...flags })
                .flatMap((group) => group.items)
                .map((item) => [item.label, item.badge]),
        );

    assert.deepEqual(
        badges({
            approvalPending: 3,
            dispatchUnassigned: 0,
            myWorkPending: 2,
            myWorkEligible: true,
        }),
        { Persetujuan: 3, Penugasan: undefined, 'Pekerjaan Saya': 2 },
    );
    assert.deepEqual(
        badges({
            approvalPending: null,
            dispatchUnassigned: 4,
            myWorkPending: 2,
            myWorkEligible: false,
        }),
        { Persetujuan: undefined, Penugasan: 4, 'Pekerjaan Saya': undefined },
    );
    assert.deepEqual(badges({ approvalPending: -1 }), {
        Persetujuan: undefined,
        Penugasan: undefined,
        'Pekerjaan Saya': undefined,
    });
});

test('exactly one item is active, the longest matching href (story 10)', () => {
    const groups = view([...new Set(contract.flatMap((destination) => destination.anyOf))], {
        canMyWork: true,
        canConsolidate: true,
        developerGalleryEnabled: true,
        canTechnicianReport: true,
    });
    const active = (url) => activeNavHref(groups, url);

    assert.equal(active('/admin/reports/technician'), '/admin/reports/technician');
    assert.equal(
        active('/admin/reports/technician?technician_id=7#top'),
        '/admin/reports/technician',
    );
    assert.equal(active('/admin/reports'), '/admin/reports');
    assert.equal(active('/admin/reports/business'), '/admin/reports');
    assert.equal(active('/admin/reports/consolidated'), '/admin/reports/consolidated');
    assert.equal(active('/admin/reports/'), '/admin/reports');
    assert.equal(active('/admin/users/12/edit'), '/admin/users');
    assert.equal(active('/admin/inventory/requests/5'), '/admin/inventory/requests');
    assert.equal(active('/admin/dashboard?tab=1'), '/admin/dashboard');
    assert.equal(active('/admin/ipam/prefixes/12'), '/admin/ipam/prefixes');
    assert.equal(active('/admin/odn/assets/3'), '/admin/odn');
    assert.equal(active('/admin/nas-devices/4'), '/admin/nas-devices');
});

test('a sibling path that only shares a prefix is not active', () => {
    const groups = view(['inventory.view', 'dashboard.view']);

    assert.equal(activeNavHref(groups, '/admin/products-archive'), null);
    assert.equal(activeNavHref(groups, '/admin/notifications'), null);
    assert.equal(activeNavHref([], '/admin/dashboard'), null);
});
