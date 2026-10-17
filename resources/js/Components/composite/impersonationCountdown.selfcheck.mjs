import assert from 'node:assert/strict';

const { formatCountdown, secondsLeft } = await import('./impersonationCountdown.ts');

const expires = '2026-10-17T10:30:00+00:00';
const at = (iso) => Date.parse(iso);

assert.equal(secondsLeft(expires, at('2026-10-17T10:00:00+00:00')), 1800);
assert.equal(secondsLeft(expires, at('2026-10-17T10:29:59.200+00:00')), 1);
assert.equal(secondsLeft(expires, at('2026-10-17T10:30:00+00:00')), 0);
assert.equal(secondsLeft(expires, at('2026-10-17T11:00:00+00:00')), 0);

assert.equal(formatCountdown(1800), '30:00');
assert.equal(formatCountdown(65), '01:05');
assert.equal(formatCountdown(0), '00:00');

console.log('impersonationCountdown selfcheck ok');
