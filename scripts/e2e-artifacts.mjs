import { rmSync } from 'node:fs';
import { join } from 'node:path';

export const e2eArtifactPaths = {
    reportDir: 'playwright-report',
    resultsDir: 'test-results',
    lastRunFile: 'test-results/.last-run.json',
};

export function settleE2EArtifacts(exitCode, baseDir = '.') {
    if (exitCode !== 0) return false;

    rmSync(join(baseDir, e2eArtifactPaths.reportDir), {
        force: true,
        recursive: true,
        maxRetries: 5,
        retryDelay: 100,
    });
    rmSync(join(baseDir, e2eArtifactPaths.resultsDir), {
        force: true,
        recursive: true,
        maxRetries: 5,
        retryDelay: 100,
    });

    return true;
}
