import assert from 'node:assert/strict';

const { renderTemplate, validateTemplate } = await import('./numberTemplate.ts');

const now = new Date('2026-10-03T00:30:00+07:00'); // 2 Oct 17:30 UTC, 3 Oct in Jakarta
const base = {
    timeZone: 'Asia/Jakarta',
    prefix: 'INV',
    companyCode: 'PST',
    nextNumber: 7,
    resetPeriod: 'never',
};
const render = (template, extra = {}) => renderTemplate({ ...base, template, ...extra }, now);

assert.equal(render('{PREFIX}'), 'INV');
assert.equal(render('{YYYY}'), '2026');
assert.equal(render('{YY}'), '26');
assert.equal(render('{MM}'), '10');
assert.equal(render('{DD}'), '03');
assert.equal(render('{COMPANY}'), 'PST');
assert.equal(render('{SEQ:1}'), '7');
assert.equal(render('{SEQ:5}'), '00007');
assert.equal(render('{SEQ:2}', { nextNumber: 12345 }), '12345', 'padding never truncates');
assert.equal(render('{PREFIX}-{YYYY}-{SEQ:5}'), 'INV-2026-00007');
assert.equal(render('{PREFIX}-{YYYY}{MM}{DD}-{SEQ:4}'), 'INV-20261003-0007');
assert.equal(render('{COMPANY}/{YY}/{MM}/{SEQ:3}.x_1'), 'PST/26/10/007.x_1');
assert.equal(
    render('{DD}', { timeZone: 'UTC' }),
    '02',
    'dates follow the company zone, not the clock',
);
assert.equal(render('{YYYY}{MM}{DD}', { timeZone: 'Pacific/Auckland' }), '20261003');
assert.equal(render('{PREFIX}-{SEQ:5}', { prefix: '' }), '-00007');
assert.equal(render('{BOGUS}-{SEQ:3}'), '{BOGUS}-007', 'unknown tokens stay literal');

assert.equal(validateTemplate('{PREFIX}-{YYYY}-{SEQ:5}', 'yearly'), null);
assert.equal(validateTemplate('{PREFIX}-{SEQ:5}', 'never'), null);
assert.equal(validateTemplate('{PREFIX}-{YY}{MM}-{SEQ:4}', 'monthly'), null);
assert.equal(validateTemplate('{YYYY}{MM}{DD}-{SEQ:4}', 'daily'), null);
assert.match(validateTemplate('{PREFIX}', 'never'), /tepat satu/);
assert.match(validateTemplate('{SEQ:3}-{SEQ:3}', 'never'), /tepat satu/);
assert.match(validateTemplate('{SEQ:0}', 'never'), /tidak dikenal/);
assert.match(validateTemplate('{SEQ:11}', 'never'), /tidak dikenal/);
assert.match(validateTemplate('{SEQ}', 'never'), /tidak dikenal/);
assert.match(validateTemplate('{NOPE}-{SEQ:3}', 'never'), /tidak dikenal/);
assert.match(validateTemplate('A B-{SEQ:3}', 'never'), /huruf, angka/);
assert.match(validateTemplate('{PREFIX}-{SEQ:5}', 'yearly'), /{YYYY} atau {YY}/);
assert.match(validateTemplate('{YYYY}-{SEQ:5}', 'monthly'), /{MM}/);
assert.match(validateTemplate('{YYYY}{MM}-{SEQ:5}', 'daily'), /{DD}/);

console.log('numberTemplate selfcheck ok');
