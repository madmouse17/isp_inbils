/**
 * Self-check of the "Susun manual" layout (manualLayout.helpers.ts), no framework beyond node.
 * Run: node --test resources/js/Components/composite/hardware-panel/manualLayout.helpers.test.mjs
 */
import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    MANUAL_MAX_COUNT,
    MANUAL_MAX_LINES,
    MANUAL_PORT_LIMIT,
    manualKinds,
    manualLayout,
    manualNames,
    manualProblem,
    manualTotal,
} from './manualLayout.helpers.ts';

const line = (kind, count, prefix = '') => ({ kind, count, prefix });

describe('manualLayout', () => {
    it('makes one chassis row per line with the catalogue group keys and a ranged caption', () => {
        const layout = manualLayout([line('rj45', 24), line('sfp', 4)], 'switch');

        assert.deepEqual(layout, {
            rows: [
                [
                    {
                        group: 'ether',
                        kind: 'rj45',
                        count: 24,
                        stack: 2,
                        order: 'column',
                        name: 'ether{port}',
                        title: 'ether1-24',
                    },
                ],
                [
                    {
                        group: 'sfp',
                        kind: 'sfp',
                        count: 4,
                        name: 'sfp{port}',
                        title: 'sfp1-4',
                    },
                ],
            ],
        });
    });

    it('continues the numbering of a group over its lines', () => {
        const [, second] = manualLayout([line('rj45', 8), line('rj45', 8, 'lan')], 'router').rows;

        assert.equal(second[0].from, 9);
        assert.equal(second[0].name, 'lan{port}');
        assert.equal(second[0].title, 'lan9-16');
    });

    it('stacks a line from 13 ports, not before', () => {
        const [twelve, thirteen] = manualLayout([line('rj45', 12), line('sfp', 13)], 'switch').rows;

        assert.equal('stack' in twelve[0], false);
        assert.equal(thirteen[0].stack, 2);
    });

    it('captions a single port without a range', () => {
        const [row] = manualLayout([line('sfp', 1)], 'router').rows;

        assert.equal(row[0].title, 'sfp1');
    });

    it('keeps braces out of the prefix, so the server never sees a placeholder it did not expect', () => {
        const [row] = manualLayout([line('rj45', 2, ' po{slot}rt ')], 'switch').rows;

        assert.equal(row[0].name, 'poslotrt{port}');
    });

    it('names an ONT as the catalogue does: LAN, PON, TEL', () => {
        assert.deepEqual(manualNames('rj45', 'onu_ont'), { group: 'lan', prefix: 'LAN' });
        assert.deepEqual(manualNames('sc', 'onu_ont'), { group: 'pon', prefix: 'PON' });
        assert.deepEqual(manualNames('rj11', 'onu_ont'), { group: 'tel', prefix: 'TEL' });
        assert.deepEqual(manualNames('rj45', 'router'), { group: 'ether', prefix: 'ether' });
        assert.deepEqual(manualKinds('onu_ont'), ['rj45', 'sc', 'rj11']);
        assert.deepEqual(manualKinds('switch'), ['rj45', 'sfp']);
    });
});

describe('manualProblem', () => {
    it('accepts a layout within the limits', () => {
        assert.equal(manualProblem([line('rj45', 24), line('sfp', 4)]), null);
        assert.equal(manualTotal([line('rj45', 24), line('sfp', 4)]), 28);
    });

    it('refuses no lines, too many lines and a count outside 1 to 64', () => {
        assert.match(manualProblem([]), /sedikitnya satu baris/);
        assert.match(
            manualProblem(Array.from({ length: MANUAL_MAX_LINES + 1 }, () => line('rj45', 1))),
            /Paling banyak 8 baris/,
        );
        assert.match(manualProblem([line('rj45', 0)]), /1 sampai 64/);
        assert.match(manualProblem([line('rj45', MANUAL_MAX_COUNT + 1)]), /1 sampai 64/);
        assert.match(manualProblem([line('rj45', 2.5)]), /1 sampai 64/);
    });

    it('refuses more than 256 ports in total, as PanelLayout does', () => {
        const full = Array.from({ length: 4 }, () => line('sfp', 64));

        assert.equal(manualTotal(full), MANUAL_PORT_LIMIT);
        assert.equal(manualProblem(full), null);
        assert.match(manualProblem([...full, line('rj45', 1)]), /paling banyak 256 port; susunan ini 257/);
    });
});
