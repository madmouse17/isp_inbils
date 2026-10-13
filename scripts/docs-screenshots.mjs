/**
 * `npm run docs:screenshots` — web screenshots for the user guide.
 *
 * Resets the shared `inbils_test` demo data (scripts/e2e-setup.mjs), then runs
 * scripts/docs-screenshots.spec.ts through the canonical runner so the same
 * sanitized server on :8010 and the same mutex as `npm run test:e2e` apply.
 * Screens come from scripts/docs-screenshots.json; PNGs land in
 * docs/user-guide/images/web/<slug>.png. Run `npm run build` first.
 */
import { spawnSync } from 'node:child_process';

const run = (script, args = []) => {
    const result = spawnSync(process.execPath, [script, ...args], { stdio: 'inherit' });
    if (result.status !== 0) process.exit(result.status ?? 1);
};

run('scripts/e2e-setup.mjs');
run('scripts/e2e-canonical.mjs', ['--config', 'scripts/docs-screenshots.config.ts']);
