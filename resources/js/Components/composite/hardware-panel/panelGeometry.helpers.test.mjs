/**
 * Self-check of the pure panel geometry (panelGeometry.helpers.ts), no framework beyond node.
 * Run: node --test resources/js/Components/composite/hardware-panel/panelGeometry.helpers.test.mjs
 */
import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    LEGEND_ORDER,
    PANEL_PORT_LIMIT,
    countStates,
    layoutPanel,
    layoutRack,
    layoutStatic,
    layoutTrace,
    minWidthPx,
    neighbour,
    pathStateKey,
    portDescription,
    portStateKey,
    tooltipLines,
} from './panelGeometry.helpers.ts';

const port = (key, number, extra = {}) => ({
    id: `${key}-${number}`,
    number,
    name: `${key}${number}`,
    state: {},
    ...extra,
});
/** A payload group holding the ports `from` to `from + count - 1`. */
const group = (key, kind, count, from = 1, role = 'port') => ({
    key,
    kind,
    role,
    label: key,
    ports: Array.from({ length: count }, (_, i) => port(key, from + i)),
});
const rj = (g, count, extra = {}) => ({ group: g, kind: 'rj45', count, ...extra });
const sfp = (g, count, extra = {}) => ({ group: g, kind: 'sfp', count, ...extra });
const byKey = (geometry, key) => geometry.ports.find((p) => p.key === key);
const near = (actual, expected, message) =>
    assert.ok(Math.abs(actual - expected) < 0.01, `${message}: ${actual} is not ${expected}`);

describe('port positions per layout row', () => {
    // MikroTik hAP ac2: power, 5 ports, usb, reset, wlan in one row.
    const hap = {
        rows: [
            [
                { fixture: 'power' },
                rj('ether', 5, { title: 'Ethernet' }),
                { fixture: 'usb' },
                { fixture: 'reset' },
                { fixture: 'wlan', title: 'WLAN 2,4/5 GHz' },
            ],
        ],
    };
    const geometry = layoutPanel(hap, [group('ether', 'rj45', 5)], null);

    it('puts a single row side by side, numbers below, in reading order', () => {
        assert.deepEqual(
            geometry.ports.map((p) => p.key),
            ['ether:1', 'ether:2', 'ether:3', 'ether:4', 'ether:5'],
        );
        assert.deepEqual(
            geometry.ports.map((p) => p.i),
            [0, 1, 2, 3, 4],
        );
        // chassis padding 6, power block 11 wide, block gap 6: the first cell starts at 23.
        assert.deepEqual(byKey(geometry, 'ether:1').cell, { x: 23, y: 3, w: 16, h: 17 });
        assert.deepEqual(byKey(geometry, 'ether:1').glyph, { x: 24, y: 3, w: 14, h: 12 });
        assert.equal(byKey(geometry, 'ether:2').cell.x, 39);
        assert.equal(byKey(geometry, 'ether:5').cell.x, 87);
        assert.ok(geometry.ports.every((p) => p.labelAt === 'bottom' && p.cell.y === 3));
    });

    it('sizes the chassis from its blocks and keeps the title under the block', () => {
        // 6 + 11 (power) + 80 (ports) + 14 (usb) + 11 (reset caption) + 48 (wlan caption) + 4 gaps of 6 + 6
        assert.equal(geometry.width, 200);
        assert.equal(geometry.height, 29);
        assert.deepEqual(geometry.bands, [{ y: 0, h: 29 }]);
        // a title may also use the 5 free units up to the next block
        assert.deepEqual(geometry.titles[1], { text: 'Ethernet', x: 23, y: 20, w: 85, h: 6 });
        assert.equal(geometry.fixtures.length, 4);
        assert.equal(geometry.rack, false);
    });

    it('widens a narrow block to the width of its title instead of overlapping the next one', () => {
        const wlan = geometry.titles.at(-1);
        assert.equal(wlan.text, 'WLAN 2,4/5 GHz');
        assert.equal(wlan.w, 48 + 5);
    });

    it('stacks two rows in zigzag order: odd numbers on top, numbers outside', () => {
        const stacked = layoutPanel(
            { rows: [[sfp('sfp', 8, { stack: 2 })]] },
            [group('sfp', 'sfp', 8)],
            null,
        );
        const top = byKey(stacked, 'sfp:1');
        const bottom = byKey(stacked, 'sfp:2');
        assert.equal(top.labelAt, 'top');
        assert.equal(bottom.labelAt, 'bottom');
        assert.equal(top.cell.x, bottom.cell.x);
        assert.ok(bottom.cell.y > top.cell.y);
        // a 1-unit gap between the two glyph rows, the cells themselves do not overlap
        assert.equal(bottom.glyph.y - (top.glyph.y + top.glyph.h), 1);
        assert.ok(bottom.cell.y >= top.cell.y + top.cell.h);
        assert.equal(byKey(stacked, 'sfp:3').cell.x, top.cell.x + 16);
        assert.equal(byKey(stacked, 'sfp:3').cell.y, top.cell.y);
        assert.equal(byKey(stacked, 'sfp:4').cell.y, bottom.cell.y);
        assert.equal(stacked.height, 3 + 29 + 3);
    });

    it('fills the top row first when the order is "row"', () => {
        const stacked = layoutPanel(
            { rows: [[sfp('s1', 8, { stack: 2, order: 'row' })]] },
            [group('s1', 'sfp', 8)],
            null,
        );
        const [one, four, five, eight] = ['s1:1', 's1:4', 's1:5', 's1:8'].map((k) =>
            byKey(stacked, k),
        );
        assert.equal(one.cell.y, four.cell.y);
        assert.ok(five.cell.y > one.cell.y);
        assert.equal(five.cell.x, one.cell.x);
        assert.equal(eight.cell.x, four.cell.x);
    });

    it('lays an odd stack in ceil(n/2) columns', () => {
        const stacked = layoutPanel(
            { rows: [[rj('e', 5, { stack: 2 })]] },
            [group('e', 'rj45', 5)],
            null,
        );
        assert.equal(byKey(stacked, 'e:5').cell.x, byKey(stacked, 'e:1').cell.x + 32);
        assert.equal(byKey(stacked, 'e:5').cell.y, byKey(stacked, 'e:1').cell.y);
        assert.equal(stacked.width, 12 + 3 * 16);
    });

    it('continues one group over several blocks with `from`', () => {
        const ten = layoutPanel(
            { rows: [[rj('ether', 5), rj('ether', 5, { from: 6 })]] },
            [group('ether', 'rj45', 10)],
            null,
        );
        assert.deepEqual(
            ten.ports.map((p) => p.number),
            [1, 2, 3, 4, 5, 6, 7, 8, 9, 10],
        );
        // block of 80, gap 6: ether:6 starts one block further
        assert.equal(byKey(ten, 'ether:6').cell.x, byKey(ten, 'ether:1').cell.x + 86);
    });

    it('stacks chassis rows with a 1-unit rule and centres a shorter block in its row', () => {
        const two = layoutPanel(
            { rows: [[sfp('a', 4)], [sfp('b', 2), { fixture: 'console' }]] },
            [group('a', 'sfp', 4), group('b', 'sfp', 2)],
            null,
        );
        assert.equal(two.bands.length, 2);
        // row 1: 3 + 14 + 3; row 2 holds a captioned console, so it adds a 6-unit title strip
        assert.deepEqual(two.bands, [
            { y: 0, h: 20 },
            { y: 21, h: 26 },
        ]);
        assert.equal(two.height, 47);
        assert.equal(byKey(two, 'a:1').cell.y, 3);
        assert.equal(byKey(two, 'b:1').cell.y, 21 + 3);
        // the 12-unit console sits in the middle of the 14-unit cages beside it
        assert.equal(two.fixtures[0].glyph.y, 21 + 4);
    });
});

describe('rack-mount bodies', () => {
    const olt = {
        rows: [
            [
                { fixture: 'power', title: 'Daya' },
                sfp('pon1', 16, { role: 'pon', slot: 1, title: 'Slot 1 · GTGH' }),
            ],
            [sfp('pon2', 16, { role: 'pon', slot: 2, title: 'Slot 2 · GTGH' })],
            [
                { fixture: 'console', title: 'Slot 3 · SMXA' },
                sfp('uplink', 2, { title: 'Uplink 10GE' }),
                { group: 'uplinkcu', kind: 'rj45', count: 2, title: 'Uplink GE RJ45' },
            ],
        ],
    };
    const groups = [
        group('pon1', 'sfp', 16, 1, 'pon'),
        group('pon2', 'sfp', 16, 1, 'pon'),
        group('uplink', 'sfp', 2),
        group('uplinkcu', 'rj45', 2),
    ];

    it('is at least 440 wide and as tall as its U', () => {
        const geometry = layoutPanel(olt, groups, 2);
        assert.equal(geometry.rack, true);
        assert.ok(geometry.width >= 440);
        near(geometry.height, 2 * 44.45, 'height of 2U');
        assert.equal(geometry.bands.length, 3);
        // the spare height is shared by the rows, the rules stay 1 unit
        near(geometry.bands[1].y, geometry.bands[0].h + 1, 'second row');
    });

    it('never makes a unit shorter than its content', () => {
        assert.ok(layoutPanel(olt, groups, 1).height > 44.45);
    });

    it('lets a desktop unit take its content size', () => {
        const geometry = layoutPanel(olt, groups, null);
        assert.equal(geometry.rack, false);
        assert.ok(geometry.width < 440);
    });
});

describe('PON naming, 0- and 1-based, and the ODN ordinal (design 3.5)', () => {
    const zte = {
        rows: [
            [sfp('pon1', 16, { role: 'pon', slot: 1, first: 1, name: 'gpon-olt_1/{slot}/{port}' })],
            [sfp('pon2', 16, { role: 'pon', slot: 2, first: 1, name: 'gpon-olt_1/{slot}/{port}' })],
        ],
    };
    const huawei = {
        rows: [
            [sfp('pon0', 16, { role: 'pon', slot: 0, first: 0, name: '0/{slot}/{port}' })],
            [sfp('pon1', 16, { role: 'pon', slot: 1, first: 0, name: '0/{slot}/{port}' })],
        ],
    };

    it('prints ZTE ports from 1 and numbers the ODN register by ordinal across slots', () => {
        const geometry = layoutPanel(
            zte,
            [group('pon1', 'sfp', 16, 1, 'pon'), group('pon2', 'sfp', 16, 1, 'pon')],
            2,
        );
        assert.deepEqual(
            ['pon1:1', 'pon1:16', 'pon2:1', 'pon2:16'].map((k) => [
                byKey(geometry, k).printed,
                byKey(geometry, k).ordinal,
            ]),
            [
                [1, 1],
                [16, 16],
                [1, 17],
                [16, 32],
            ],
        );
    });

    it('prints Huawei ports from 0 while the ordinal still starts at 1', () => {
        const geometry = layoutPanel(
            huawei,
            [group('pon0', 'sfp', 16, 1, 'pon'), group('pon1', 'sfp', 16, 1, 'pon')],
            2,
        );
        assert.deepEqual(
            ['pon0:1', 'pon0:16', 'pon1:1', 'pon1:16'].map((k) => [
                byKey(geometry, k).printed,
                byKey(geometry, k).ordinal,
            ]),
            [
                [0, 1],
                [15, 16],
                [0, 17],
                [15, 32],
            ],
        );
    });

    it('gives other ports no ordinal and counts hidden PON slots', () => {
        const mixed = {
            rows: [
                [sfp('pon1', 4, { role: 'pon' })],
                [sfp('pon2', 4, { role: 'pon' }), sfp('up', 2)],
            ],
        };
        // pon1 left out (no odn.view): pon2 keeps ordinals 5-8
        const geometry = layoutPanel(
            mixed,
            [group('pon2', 'sfp', 4, 1, 'pon'), group('up', 'sfp', 2)],
            null,
        );
        assert.equal(byKey(geometry, 'pon2:1').ordinal, 5);
        assert.equal(byKey(geometry, 'up:1').ordinal, null);
    });

    it('draws the same ports in a static mini panel', () => {
        const geometry = layoutStatic(zte, 2);
        assert.equal(geometry.ports.length, 32);
        assert.ok(geometry.ports.every((p) => p.group === -1 && p.port === -1));
        assert.equal(byKey(geometry, 'pon2:1').ordinal, 17);
    });
});

describe('what the payload does and does not hold', () => {
    it('leaves out a block whose group is missing and drops a row left empty', () => {
        const layout = { rows: [[sfp('pon1', 4, { role: 'pon' })], [sfp('up', 2)]] };
        const geometry = layoutPanel(layout, [group('up', 'sfp', 2)], null);
        assert.equal(geometry.bands.length, 1);
        assert.deepEqual(
            geometry.ports.map((p) => p.key),
            ['up:1', 'up:2'],
        );
    });

    it('keeps a hole where the payload lacks a port of a drawn block', () => {
        const partial = {
            ...group('e', 'rj45', 4),
            ports: [port('e', 1), port('e', 2), port('e', 4)],
        };
        const geometry = layoutPanel({ rows: [[rj('e', 4)]] }, [partial], null);
        assert.deepEqual(
            geometry.ports.map((p) => p.number),
            [1, 2, 4],
        );
        assert.equal(byKey(geometry, 'e:4').cell.x - byKey(geometry, 'e:2').cell.x, 32);
    });

    it('appends ports no block places as extra rows, so no register row is hidden', () => {
        const groups = [group('pon1', 'sfp', 20, 1, 'pon')];
        groups[0].label = 'Port PON di luar model';
        const geometry = layoutPanel({ rows: [[sfp('pon1', 16, { role: 'pon' })]] }, groups, null);
        assert.equal(geometry.ports.length, 20);
        assert.equal(geometry.bands.length, 2);
        assert.deepEqual(geometry.titles.at(-1).text, 'Port PON di luar model');
        const extra = byKey(geometry, 'pon1:17');
        assert.equal(extra.ordinal, null);
        assert.ok(extra.cell.y >= geometry.bands[1].y);
        // x:1 is drawn by its block; the other 33 make three rows of at most 16 under it
        const many = layoutPanel({ rows: [[sfp('x', 1)]] }, [group('x', 'sfp', 34)], null);
        assert.equal(many.bands.length, 4);
    });

    it('matches the payload port to its slot by group and number', () => {
        const groups = [group('a', 'rj45', 2), group('b', 'rj45', 3)];
        const geometry = layoutPanel({ rows: [[rj('a', 2), rj('b', 3)]] }, groups, null);
        const third = byKey(geometry, 'b:3');
        assert.equal(third.group, 1);
        assert.equal(third.port, 2);
    });
});

describe('virtualisation threshold', () => {
    const wide = (rows) => ({
        layout: { rows: Array.from({ length: rows }, (_, r) => [sfp(`g${r}`, 16)]) },
        groups: Array.from({ length: rows }, (_, r) => group(`g${r}`, 'sfp', 16)),
    });

    it('draws up to 256 ports and cuts the rest, saying how many', () => {
        assert.equal(PANEL_PORT_LIMIT, 256);
        const exact = wide(16);
        const atLimit = layoutPanel(exact.layout, exact.groups, null);
        assert.equal(atLimit.ports.length, 256);
        assert.equal(atLimit.truncated, 0);

        const over = wide(20);
        const cut = layoutPanel(over.layout, over.groups, null);
        assert.equal(cut.ports.length, 256);
        assert.equal(cut.truncated, 64);
        assert.deepEqual(
            cut.ports.map((p) => p.i),
            Array.from({ length: 256 }, (_, i) => i),
        );
    });

    it('applies the same cap to a static mini panel', () => {
        const over = wide(17);
        assert.equal(layoutStatic(over.layout, null).ports.length, 256);
        assert.equal(layoutStatic(over.layout, null).truncated, 16);
    });
});

describe('port state colour', () => {
    const known = { known: true };
    const unknown = { known: false, reason: 'disabled' };
    const key = (state, extra = {}, online = known) =>
        portStateKey(port('p', 1, { state, ...extra }), online);

    it('follows the priority unregistered > admin_down > isolir > online > offline > occupied > free', () => {
        const all = {
            unregistered: true,
            admin_down: true,
            isolir: true,
            online: true,
            occupied: true,
        };
        assert.equal(key(all), 'unregistered');
        assert.equal(key({ ...all, unregistered: undefined }), 'admin_down');
        assert.equal(key({ isolir: true, online: true, occupied: true }), 'isolir');
        assert.equal(key({ online: true, occupied: true }), 'online');
        assert.equal(
            key({ occupied: true }, { drop: { subscription: 'SUB-1', customer: 'Budi' } }),
            'offline',
        );
        assert.equal(key({ occupied: true }), 'occupied');
        assert.equal(key({}), 'free');
    });

    it('calls an occupied port offline only with subscribers behind it and a known online set', () => {
        const drop = { drop: { subscription: 'SUB-1', customer: 'Budi' } };
        assert.equal(key({ occupied: true }, { behind: { total: 3, online: 0 } }), 'offline');
        assert.equal(key({ occupied: true }, { behind: { total: 0, online: 0 } }), 'occupied');
        assert.equal(key({ occupied: true }, drop, unknown), 'occupied');
        assert.equal(key({ occupied: true }, { link: { asset: 'ODC-1', port: '1' } }), 'occupied');
    });

    it('colours a fibre path like its drop', () => {
        assert.equal(pathStateKey({ occupied: true }, known), 'offline');
        assert.equal(pathStateKey({ occupied: true, online: true }, known), 'online');
        assert.equal(pathStateKey({ occupied: true }, unknown), 'occupied');
        assert.equal(pathStateKey({ occupied: true, isolir: true }, known), 'isolir');
    });

    it('counts states and lists every key once in the legend', () => {
        assert.deepEqual(countStates(['online', 'free', 'online', 'isolir']), {
            online: 2,
            free: 1,
            isolir: 1,
        });
        assert.deepEqual([...LEGEND_ORDER].sort(), [
            'admin_down',
            'free',
            'isolir',
            'occupied',
            'offline',
            'online',
            'unregistered',
        ]);
    });
});

describe('what a port says', () => {
    const full = port('gpon', 4, {
        name: 'gpon-olt_1/1/4',
        label: 'ke CCR-INTI',
        link: { asset: 'ODC-UTARA-02', port: '1:8' },
        drop: { subscription: 'SUB-0012', customer: 'Budi' },
        behind: { total: 9, online: 0 },
    });

    it('builds the accessible name in the order of the design', () => {
        assert.equal(
            portDescription(full, 'Offline'),
            'gpon-olt_1/1/4, ke CCR-INTI: Offline, ke ODC-UTARA-02 1:8, pelanggan SUB-0012 Budi, 9 pelanggan, 0 online',
        );
        assert.equal(portDescription(port('e', 1, { name: 'ether1' }), 'Kosong'), 'ether1: Kosong');
    });

    it('builds the tooltip lines and names the ODN port of a PON port', () => {
        assert.deepEqual(tooltipLines(full, 17, 'Offline'), [
            'gpon-olt_1/1/4 · Offline',
            'PON 17 di ODN',
            'ke CCR-INTI',
            'ke ODC-UTARA-02 · 1:8',
            'SUB-0012 · Budi',
            '9 pelanggan · 0 online',
        ]);
        assert.deepEqual(tooltipLines(port('e', 1, { name: 'ether1' }), null, 'Kosong'), [
            'ether1 · Kosong',
        ]);
    });
});

describe('keyboard neighbour', () => {
    // two chassis rows: a stack of 2 x 3 columns, then a single row of 3
    const geometry = layoutPanel(
        { rows: [[sfp('a', 6, { stack: 2 })], [sfp('b', 3)]] },
        [group('a', 'sfp', 6), group('b', 'sfp', 3)],
        null,
    );
    const at = (key) => byKey(geometry, key).i;
    const go = (key, nav) => geometry.ports[neighbour(geometry, at(key), nav)].key;

    it('moves along the row band and stops at its ends', () => {
        assert.equal(go('a:1', 'ArrowRight'), 'a:3');
        assert.equal(go('a:3', 'ArrowRight'), 'a:5');
        assert.equal(go('a:5', 'ArrowRight'), 'a:5');
        assert.equal(go('a:2', 'ArrowRight'), 'a:4');
        assert.equal(go('a:3', 'ArrowLeft'), 'a:1');
        assert.equal(go('a:1', 'ArrowLeft'), 'a:1');
        assert.equal(go('b:1', 'ArrowRight'), 'b:2');
    });

    it('moves between the stacked rows and on to the next chassis row', () => {
        assert.equal(go('a:1', 'ArrowDown'), 'a:2');
        assert.equal(go('a:2', 'ArrowUp'), 'a:1');
        assert.equal(go('a:1', 'ArrowUp'), 'a:1');
        assert.equal(go('a:2', 'ArrowDown'), 'b:1');
        assert.equal(go('a:4', 'ArrowDown'), 'b:2');
        assert.equal(go('b:3', 'ArrowUp'), 'a:6');
        assert.equal(go('b:3', 'ArrowDown'), 'b:3');
    });

    it('jumps to the first and the last port', () => {
        assert.equal(go('b:2', 'Home'), 'a:1');
        assert.equal(go('a:3', 'End'), 'b:3');
    });

    it('finds a single row next to a stack of two', () => {
        const mixed = layoutPanel(
            { rows: [[rj('m', 1), sfp('s', 4, { stack: 2 })]] },
            [group('m', 'rj45', 1), group('s', 'sfp', 4)],
            null,
        );
        const from = byKey(mixed, 'm:1').i;
        assert.equal(mixed.ports[neighbour(mixed, from, 'ArrowRight')].key, 's:1');
        assert.equal(mixed.ports[neighbour(mixed, byKey(mixed, 's:1').i, 'ArrowLeft')].key, 'm:1');
        assert.equal(mixed.ports[neighbour(mixed, byKey(mixed, 's:2').i, 'ArrowLeft')].key, 'm:1');
    });

    it('stays put without ports or on an unknown port', () => {
        const empty = layoutPanel({ rows: [[sfp('x', 2)]] }, [], null);
        assert.equal(neighbour(empty, 0, 'ArrowRight'), 0);
        assert.equal(neighbour(geometry, 99, 'ArrowRight'), 99);
    });
});

describe('target size', () => {
    const geometry = layoutPanel({ rows: [[sfp('s', 16)]] }, [group('s', 'sfp', 16)], null);

    it('makes the smallest hit area at least the target on both sides', () => {
        assert.equal(geometry.minCell, 14);
        const fine = minWidthPx(geometry, 24);
        const coarse = minWidthPx(geometry, 44);
        assert.equal(fine, Math.ceil((geometry.width * 24) / 14));
        assert.equal(coarse, Math.ceil((geometry.width * 44) / 14));
        assert.ok(coarse > fine);
        // at that width one unit is 24 / 14 px, so the 14-unit cell is 24 px
        assert.ok((fine / geometry.width) * 14 >= 24 - 1e-9);
    });

    it('is bound by the narrowest cell of any kind', () => {
        const mixed = layoutPanel(
            { rows: [[rj('e', 2), { group: 's', kind: 'sc', count: 2 }]] },
            [group('e', 'rj45', 2), group('s', 'sc', 2)],
            null,
        );
        assert.equal(mixed.minCell, 11);
    });

    it('falls back to the width of a panel without ports', () => {
        const empty = layoutPanel({ rows: [] }, [], null);
        assert.equal(empty.ports.length, 0);
        assert.equal(minWidthPx(empty, 44), Math.ceil(empty.width));
        assert.ok(empty.width > 0 && empty.height > 0);
    });
});

describe('rack', () => {
    it('numbers U1 at the bottom and places devices by their top unit', () => {
        const item = (unit, height, id) => ({
            id,
            code: id,
            name: id,
            type: 'router',
            unit,
            height,
            layout: null,
        });
        const rack = layoutRack(42, [item(1, 1, 'low'), item(13, 2, 'mid')]);

        assert.equal(rack.slots.length, 42);
        assert.equal(rack.slots[0].unit, 42);
        assert.equal(rack.slots[41].unit, 1);
        near(rack.slots[0].y, 6, 'U42 sits right under the frame');
        near(rack.slots[41].y, 6 + 41 * 44.45, 'U1 sits at the bottom');
        near(rack.height, 12 + 42 * 44.45, 'height of 42U');
        assert.equal(rack.width, 480);

        assert.deepEqual(
            rack.items.map((i) => i.id),
            ['mid', 'low'],
        );
        near(rack.items[0].y, 6 + (42 - 14) * 44.45, 'a 2U device at U13-14');
        near(rack.items[0].h, 2 * 44.45, 'its height');
        near(rack.items[1].y, rack.slots[41].y, 'U1 device fills the bottom slot');
    });

    it('clamps a device that sticks out of a smaller rack instead of drawing it off the frame', () => {
        const rack = layoutRack(10, [
            { id: 'x', code: 'x', name: 'x', type: 'switch', unit: 9, height: 4, layout: null },
        ]);
        assert.equal(rack.items[0].unit, 9);
        assert.equal(rack.items[0].height, 2);
        near(rack.items[0].y, 6, 'top of a rack of 10');
    });
});

describe('trace chain', () => {
    const olt = { rows: [[sfp('pon1', 16, { role: 'pon' })]] };
    const odp = { rows: [[{ group: 's1', kind: 'sc', count: 8, role: 'splitter' }]] };
    const hops = [
        {
            asset: { id: 'a', code: 'OLT-1', name: 'OLT', type: 'olt' },
            label: 'pon1',
            layout: olt,
            lit: { group: 'pon1', number: 3 },
        },
        {
            asset: { id: 'b', code: 'ODP-1', name: 'ODP', type: 'odp' },
            label: 'ODP-1 · 1:8 · 2',
            layout: odp,
            lit: { group: 's1', number: 2 },
        },
        { asset: null, label: 'Budi', layout: null, lit: null },
    ];
    const trace = layoutTrace(hops);

    it('lays the hops left to right with a gap between cards', () => {
        assert.equal(trace.hops.length, 3);
        assert.equal(trace.width, 3 * 184 + 2 * 32);
        assert.deepEqual(
            trace.hops.map((h) => h.x),
            [0, 216, 432],
        );
        assert.ok(trace.hops.every((h) => h.card.h === trace.height));
    });

    it('scales each faceplate into its card and centres the lit port inside it', () => {
        const [first, second, last] = trace.hops;
        assert.ok(first.scale > 0 && first.scale <= 1.5);
        const lit = first.litCentre;
        assert.ok(lit.x > first.area.x && lit.x < first.area.x + first.area.w);
        assert.ok(lit.y > first.area.y && lit.y < first.area.y + first.area.h);
        const pon3 = first.geometry.ports.find((p) => p.key === 'pon1:3');
        near(lit.x, first.origin.x + (pon3.glyph.x + pon3.glyph.w / 2) * first.scale, 'lit x');
        assert.ok(second.litCentre.x > second.card.x);
        assert.equal(last.geometry, null);
        assert.equal(last.litCentre, null);
    });

    it('gives a hop without a lit port or a layout no centre and an empty chain no size', () => {
        const unlit = layoutTrace([{ ...hops[0], lit: { group: 'pon1', number: 99 } }]);
        assert.equal(unlit.hops[0].litCentre, null);
        assert.deepEqual(layoutTrace([]), { width: 0, height: 124, hops: [] });
    });
});
