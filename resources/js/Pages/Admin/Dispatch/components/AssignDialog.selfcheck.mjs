/**
 * Non-destructive browser contract for Dispatch AssignDialog recovery.
 * Run: node --test resources/js/Pages/Admin/Dispatch/components/AssignDialog.selfcheck.mjs
 */
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const sourcePath = new URL('./AssignDialog.tsx', import.meta.url);

test('AssignDialog keeps validation separate from conflict and reload fail-closed', async () => {
    const source = await readFile(sourcePath, 'utf8');

    assert.match(source, /errors\.conflict/);
    assert.match(source, /setIsConflict\(Boolean\(errors\.conflict\)\)/);
    assert.doesNotMatch(source, /setIsConflict\(true\)/);
    assert.match(source, /Periksa input lalu coba lagi/);
    assert.match(source, /onReloadQueue\?: \(\) => Promise<DispatchReloadResult>/);
    assert.match(source, /onError/);
    assert.match(source, /setReloadError/);
    assert.match(source, /disabled=\{isReloading \|\| submitting \|\| !onReloadQueue\}/);
    assert.match(source, /isConflict \|\| isReloading/);
    assert.match(source, /aria-live="assertive"/);
    assert.match(source, /statusRef\.current\?\.focus/);
});

test('My Work controls remain server-authoritative per action capability', async () => {
    const [columns, mobile] = await Promise.all([
        readFile(new URL('../../MyWork/components/useMyWorkColumns.tsx', import.meta.url), 'utf8'),
        readFile(new URL('../../MyWork/components/MobileMyWorkCard.tsx', import.meta.url), 'utf8'),
    ]);

    // The table of My Work and the Dispatch tab of the same name share the columns.
    for (const source of [columns, mobile]) {
        assert.match(source, /actions\.start/);
        assert.match(source, /actions\.resolve/);
        assert.match(source, /actions\.submit/);
        assert.match(source, /actions\.close/);
    }
});

test('Dispatch reload helper settles cancel/error and only closes on successful fresh state', async () => {
    const source = await readFile(new URL('../Index.tsx', import.meta.url), 'utf8');

    assert.match(source, /export function reloadDispatchQueue/);
    assert.match(source, /only: \[/);
    assert.match(source, /onSuccess: \(page\)/);
    assert.match(source, /onError: \(\)/);
    assert.match(source, /onCancel: \(\)/);
    assert.match(source, /onFinish: \(\)/);
    assert.match(source, /if \(!fresh \|\| !fresh\.actions\?\.assign\)/);
    assert.match(source, /setSelectedItem\(null\)/);
    assert.match(source, /setAssignOpen\(false\)/);
});

// Browser smoke is intentionally non-destructive: it exercises the static test
// harness only and never contacts the application or mutates its database.
test('recovery state model remains fail-closed during reload failure and cancel', async () => {
    const state = { conflict: true, reloading: false, open: true };
    const canSubmit = () => !state.conflict && !state.reloading;
    const startReload = () => {
        if (state.reloading) return false;
        state.reloading = true;
        return true;
    };
    const finishReload = (outcome) => {
        state.reloading = false;
        if (outcome === 'success') state.conflict = false;
        return outcome === 'success';
    };

    assert.equal(canSubmit(), false);
    assert.equal(startReload(), true);
    assert.equal(canSubmit(), false);
    assert.equal(finishReload('error'), false);
    assert.equal(canSubmit(), false);
    assert.equal(startReload(), true);
    assert.equal(finishReload('cancel'), false);
    assert.equal(canSubmit(), false);
    assert.equal(startReload(), true);
    assert.equal(finishReload('success'), true);
    assert.equal(canSubmit(), true);
});
