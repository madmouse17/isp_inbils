import assert from 'node:assert/strict';

const { deadlineState, targetHoursText } = await import('./deadline.ts');

const deadline = '2026-03-02T11:00:00+00:00';
const at = (iso) => Date.parse(iso);
const state = (now, soon) => deadlineState(deadline, at(now), soon);

// Time left: a started minute counts, minutes and hours join, days take over from 24 hours.
assert.deepEqual(state('2026-03-02T08:55:00+00:00'), { tone: 'neutral', label: 'sisa 2 j 5 mnt' });
assert.deepEqual(state('2026-03-02T09:00:00+00:00'), { tone: 'neutral', label: 'sisa 2 j' });
assert.deepEqual(state('2026-03-02T10:19:00+00:00'), { tone: 'neutral', label: 'sisa 41 mnt' });
assert.deepEqual(state('2026-03-02T10:59:30+00:00'), { tone: 'warning', label: 'sisa 1 mnt' });
assert.deepEqual(state('2026-03-01T08:00:00+00:00'), { tone: 'neutral', label: 'sisa 1 hr 3 j' });
assert.deepEqual(state('2026-02-28T11:00:00+00:00'), { tone: 'neutral', label: 'sisa 2 hr' });

// The warning starts at the soon limit, inclusive, counted in the minutes the label shows (a started minute counts).
assert.deepEqual(state('2026-03-02T10:30:00+00:00'), { tone: 'warning', label: 'sisa 30 mnt' });
assert.deepEqual(state('2026-03-02T10:30:01+00:00'), { tone: 'warning', label: 'sisa 30 mnt' });
assert.deepEqual(state('2026-03-02T10:29:59+00:00'), { tone: 'neutral', label: 'sisa 31 mnt' });
assert.equal(state('2026-03-02T10:29:00+00:00').tone, 'neutral');
assert.equal(state('2026-03-02T10:29:00+00:00', 45).tone, 'warning');
assert.equal(state('2026-03-02T10:29:00+00:00', 5).tone, 'neutral');

// Passed: whole minutes, danger, the exact moment already counts as passed.
assert.deepEqual(state('2026-03-02T11:12:00+00:00'), { tone: 'danger', label: 'lewat 12 mnt' });
assert.deepEqual(state('2026-03-02T11:12:59+00:00'), { tone: 'danger', label: 'lewat 12 mnt' });
assert.deepEqual(state('2026-03-02T14:00:00+00:00'), { tone: 'danger', label: 'lewat 3 j' });
assert.deepEqual(state('2026-03-02T14:05:00+00:00'), { tone: 'danger', label: 'lewat 3 j 5 mnt' });
assert.deepEqual(state('2026-03-02T11:00:00+00:00'), { tone: 'danger', label: 'lewat < 1 mnt' });
assert.deepEqual(state('2026-03-02T11:00:30+00:00'), { tone: 'danger', label: 'lewat < 1 mnt' });
assert.deepEqual(state('2026-03-04T12:00:00+00:00'), { tone: 'danger', label: 'lewat 2 hr 1 j' });

// A moment with a zone offset is the same moment.
assert.deepEqual(deadlineState('2026-03-02T18:00:00+07:00', at('2026-03-02T10:19:00+00:00')), {
    tone: 'neutral',
    label: 'sisa 41 mnt',
});

// The hours a target was counted from.
assert.equal(targetHoursText(6, false), '6 jam');
assert.equal(targetHoursText(6, true), '6 jam ÷ 2 karena Mendesak = 3 jam');
assert.equal(targetHoursText(3, true), '3 jam ÷ 2 karena Mendesak = 2 jam (dibulatkan ke atas)');
assert.equal(targetHoursText(1, true), '1 jam ÷ 2 karena Mendesak = 1 jam (dibulatkan ke atas)');

console.log('deadline selfcheck ok');
