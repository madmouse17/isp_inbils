import assert from 'node:assert/strict';
import { mkdtempSync, mkdirSync, writeFileSync, existsSync, readdirSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import test from 'node:test';

import { e2eArtifactPaths, settleE2EArtifacts } from './e2e-artifacts.mjs';

const base = mkdtempSync(join(tmpdir(), 'inbils-e2e-artifacts-'));
const reportDir = join(base, e2eArtifactPaths.reportDir);
const resultsDir = join(base, e2eArtifactPaths.resultsDir);
const lastRunFile = join(base, e2eArtifactPaths.lastRunFile);
const hiddenMarker = join(resultsDir, '.hidden-marker');

function seedArtifacts() {
    mkdirSync(reportDir, { recursive: true });
    mkdirSync(resultsDir, { recursive: true });
    writeFileSync(join(reportDir, 'index.html'), '<html></html>');
    writeFileSync(lastRunFile, '{"status":"ok"}');
    writeFileSync(hiddenMarker, 'secret');
}

test('settleE2EArtifacts keeps failure diagnostics and clears green artifacts', () => {
    seedArtifacts();
    assert.equal(settleE2EArtifacts(1, base), false);
    assert.equal(existsSync(reportDir), true);
    assert.equal(existsSync(resultsDir), true);
    assert.equal(existsSync(lastRunFile), true);
    assert.equal(existsSync(hiddenMarker), true);

    seedArtifacts();
    assert.equal(settleE2EArtifacts(0, base), true);
    assert.equal(existsSync(reportDir), false);
    assert.equal(existsSync(resultsDir), false);
    assert.equal(existsSync(lastRunFile), false);
    assert.equal(readdirSync(base).length, 0);
});
