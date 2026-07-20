import { expect, test as base } from '@playwright/test';
import type { Request } from '@playwright/test';

import { abortCorrelationFor, classifyRequestFailure, test as guarded } from './support/fixtures';

const test = base;
const appOrigin = 'http://127.0.0.1:8010';
const chunkUrl = `${appOrigin}/build/assets/app-test.js`;

test('@kanban-t_1d1f1f13 same-page app-origin build chunk abort fails closed', () => {
    const evidence = classifyRequestFailure({
        url: chunkUrl,
        appOrigin,
        resourceType: 'other',
        error: 'net::ERR_ABORTED',
        navigationMovedAway: false,
    });

    expect(evidence.classification).toBe('app-origin');
    expect(evidence.shouldFail).toBe(true);
});

test('@kanban-t_1d1f1f13 app-origin buffer exhaustion always fails', () => {
    const input = {
        url: chunkUrl,
        appOrigin,
        resourceType: 'other',
        error: 'net::ERR_NO_BUFFER_SPACE',
        navigationMovedAway: true,
        abortCorrelation: {
            type: 'inertia:finish',
            pageUrl: `${appOrigin}/admin/dashboard`,
            sequence: 1,
            visitCancelled: true,
            visitInterrupted: false,
        },
    };

    const evidence = classifyRequestFailure(input);

    expect(evidence.classification).toBe('app-origin');
    expect(evidence.shouldFail).toBe(true);
});

test('@kanban-t_1d1f1f13 moved frame without lifecycle evidence fails closed', () => {
    const evidence = classifyRequestFailure({
        url: chunkUrl,
        appOrigin,
        resourceType: 'other',
        error: 'net::ERR_ABORTED',
        navigationMovedAway: true,
    });

    expect(evidence.classification).toBe('app-origin');
    expect(evidence.shouldFail).toBe(true);
});

test('@kanban-t_1d1f1f13 inertia cancellation evidence allows app-origin abort', () => {
    const input = {
        url: chunkUrl,
        appOrigin,
        resourceType: 'other',
        error: 'net::ERR_ABORTED',
        navigationMovedAway: false,
        abortCorrelation: {
            type: 'inertia:finish',
            pageUrl: `${appOrigin}/admin/dashboard`,
            sequence: 1,
            visitCancelled: false,
            visitInterrupted: true,
        },
    };

    const evidence = classifyRequestFailure(input);

    expect(evidence.classification).toBe('app-origin-inertia-abort');
    expect(evidence.shouldFail).toBe(false);
    expect(evidence.correlation).toEqual(input.abortCorrelation);
});

test('@kanban-t_1d1f1f13 old navigation generation fails closed', () => {
    const failure = requestFailure({
        startedAt: 5_000,
        failedAt: 6_000,
        lifecycleSequenceAtStart: 2,
        lifecycleSequenceAtFailure: 2,
    });

    expect(
        abortCorrelationFor(failure, [
            {
                type: 'framenavigated',
                pageUrl: `${appOrigin}/admin/dashboard`,
                at: 5_500,
                sequence: 1,
            },
        ]),
    ).toBeNull();
});

test('@kanban-t_1d1f1f13 later document request allows app-origin abort after long delay', () => {
    const failure = requestFailure({
        startedAt: 1_000,
        failedAt: 4_000,
        lifecycleSequenceAtStart: 2,
        lifecycleSequenceAtFailure: 3,
    });
    const correlation = abortCorrelationFor(failure, [
        {
            type: 'document-request',
            pageUrl: `${appOrigin}/admin/spk`,
            at: 3_500,
            sequence: 3,
            visitUrl: `${appOrigin}/admin/spk`,
            visitMethod: 'GET',
        },
    ]);

    const evidence = classifyRequestFailure({
        url: chunkUrl,
        appOrigin,
        resourceType: 'other',
        error: 'net::ERR_ABORTED',
        navigationMovedAway: false,
        abortCorrelation: correlation,
    });

    expect(evidence.classification).toBe('app-origin-navigation-abort');
    expect(evidence.shouldFail).toBe(false);
    expect(evidence.correlation).toEqual({
        type: 'document-request',
        pageUrl: `${appOrigin}/admin/spk`,
        sequence: 3,
        visitUrl: `${appOrigin}/admin/spk`,
        visitMethod: 'GET',
    });
});

test('@kanban-t_1d1f1f13 later inertia request allows app-origin abort', () => {
    const failure = requestFailure({
        startedAt: 1_000,
        failedAt: 4_000,
        lifecycleSequenceAtStart: 2,
        lifecycleSequenceAtFailure: 3,
    });
    const correlation = abortCorrelationFor(failure, [
        {
            type: 'inertia-request',
            pageUrl: `${appOrigin}/admin/tickets`,
            at: 3_500,
            sequence: 3,
            visitUrl: `${appOrigin}/admin/tickets`,
            visitMethod: 'POST',
        },
    ]);

    expect(correlation).toEqual({
        type: 'inertia-request',
        pageUrl: `${appOrigin}/admin/tickets`,
        sequence: 3,
        visitUrl: `${appOrigin}/admin/tickets`,
        visitMethod: 'POST',
    });
});

test('@kanban-t_1d1f1f13 fixture teardown after failure fails closed', () => {
    const failure = requestFailure({
        startedAt: 1_000,
        failedAt: 1_010,
        lifecycleSequenceAtStart: 2,
        lifecycleSequenceAtFailure: 2,
    });

    expect(
        abortCorrelationFor(failure, [
            {
                type: 'fixture-teardown',
                pageUrl: `${appOrigin}/admin/dashboard`,
                at: 1_011,
                sequence: 3,
            },
        ]),
    ).toBeNull();
});

test('@kanban-t_1d1f1f13 teardown active at failure allows only ERR_ABORTED', () => {
    const failure = requestFailure({
        startedAt: 1_000,
        failedAt: 1_010,
        lifecycleSequenceAtStart: 2,
        lifecycleSequenceAtFailure: 2,
        teardownCorrelation: {
            type: 'fixture-teardown',
            pageUrl: `${appOrigin}/admin/dashboard`,
            sequence: 3,
        },
    });
    const correlation = abortCorrelationFor(failure, []);

    expect(correlation).toEqual({
        type: 'fixture-teardown',
        pageUrl: `${appOrigin}/admin/dashboard`,
        sequence: 3,
    });
    expect(
        classifyRequestFailure({
            url: chunkUrl,
            appOrigin,
            resourceType: 'other',
            error: 'net::ERR_ABORTED',
            navigationMovedAway: false,
            abortCorrelation: correlation,
        }).shouldFail,
    ).toBe(false);
    expect(
        classifyRequestFailure({
            url: chunkUrl,
            appOrigin,
            resourceType: 'other',
            error: 'net::ERR_NO_BUFFER_SPACE',
            navigationMovedAway: false,
            abortCorrelation: correlation,
        }).shouldFail,
    ).toBe(true);
});

function requestFailure(overrides = {}) {
    return {
        url: chunkUrl,
        origin: appOrigin,
        resourceType: 'other',
        method: 'GET',
        error: 'net::ERR_ABORTED',
        initialFrameUrl: `${appOrigin}/admin/dashboard`,
        currentFrameUrl: `${appOrigin}/admin/dashboard`,
        pageUrl: `${appOrigin}/admin/dashboard`,
        navigationMovedAway: false,
        startedAt: 1_000,
        failedAt: 1_000,
        requestPurpose: null,
        requestSecPurpose: 'prefetch',
        lifecycleSequenceAtStart: 0,
        lifecycleSequenceAtFailure: 0,
        ...overrides,
    };
}

guarded(
    '@kanban-t_1d1f1f13 browser navigation evidence allows app-origin abort',
    async ({ page, baseURL }) => {
        const scriptUrl = `${baseURL}/build/assets/e2e-navigation-cancel.js`;
        let releaseRoute!: () => void;
        const routeHit = new Promise<void>((resolve) => {
            releaseRoute = resolve;
        });
        const navigationEvents: Array<{ type: 'framenavigated'; pageUrl: string; at: number }> = [];
        let failedRequest!: Request;

        page.on('framenavigated', (frame) => {
            if (frame === page.mainFrame()) {
                navigationEvents.push({
                    type: 'framenavigated',
                    pageUrl: frame.url(),
                    at: Date.now(),
                });
            }
        });

        await page.route(`${baseURL}/`, (route) =>
            route.fulfill({
                body: '<!doctype html><title>fixture</title>',
                contentType: 'text/html',
            }),
        );
        await page.route(`${baseURL}/login`, (route) =>
            route.fulfill({
                body: '<!doctype html><title>login</title>',
                contentType: 'text/html',
            }),
        );
        await page.route(scriptUrl, async (route) => {
            await routeHit;
            await route
                .fulfill({ body: 'window.__neverLoaded = true;', contentType: 'text/javascript' })
                .catch(() => {});
        });

        await page.goto('/');
        const requestSeen = page.waitForRequest(scriptUrl);
        const failed = page.waitForEvent('requestfailed', (request) => request.url() === scriptUrl);
        await page.evaluate((url) => {
            const script = document.createElement('script');
            script.src = url;
            document.head.appendChild(script);
        }, scriptUrl);
        await requestSeen;
        const startedAt = Date.now();
        await page.goto('/login');
        failedRequest = await failed;
        releaseRoute();

        const navigation = navigationEvents.find((event) => event.at >= startedAt);
        expect(navigation).toBeTruthy();

        const input = {
            url: failedRequest.url(),
            appOrigin: new URL(baseURL!).origin,
            resourceType: failedRequest.resourceType(),
            error: failedRequest.failure()?.errorText ?? 'unknown',
            navigationMovedAway: true,
            abortCorrelation: {
                type: 'framenavigated',
                pageUrl: navigation!.pageUrl,
                sequence: 1,
            },
        };

        const evidence = classifyRequestFailure(input);

        expect(input.error).toBe('net::ERR_ABORTED');
        expect(evidence.classification).toBe('app-origin-navigation-abort');
        expect(evidence.shouldFail).toBe(false);
        expect(evidence.correlation).toEqual(input.abortCorrelation);
    },
);
