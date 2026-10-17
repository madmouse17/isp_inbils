import { expect, test as base } from '@playwright/test';
import type { Request } from '@playwright/test';

import {
    abortCorrelationFor,
    assignResponseToExpectedErrors,
    classifyRequestFailure,
    evaluateHttpAndConsoleEvidence,
    matchConsoleMethod,
    matchConsoleStatus,
    matchConsoleUrl,
    matchExpectedHttpUrl,
    registerExpectedHttpError,
    runGuardedPageSession,
    test as guarded,
} from './support/fixtures';
import type { RegisteredExpectedHttpError } from './support/fixtures';

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
            type: 'inertia:finish' as const,
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
            type: 'inertia:finish' as const,
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
                type: 'framenavigated' as const,
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

test('@kanban-t_c80cae29 zero-match expected error fails closed in teardown audit', () => {
    const result = evaluateHttpAndConsoleEvidence({
        expectedErrors: [
            {
                url: '/admin/users/999',
                status: 500,
                method: 'DELETE',
                matchedResponse: false,
                matchedResponsesCount: 0,
            },
        ],
        pendingConsoleErrors: [],
        appOrigin,
    });

    expect(result.failingEvidence.length).toBe(1);
    expect(result.failingEvidence[0].type).toBe('unmatched-expected-http-error');
    expect(result.failingEvidence[0].classification).toBe('expected-scope-zero-match');
    expect(result.allowedEvidence).toEqual([]);
});

test('@kanban-t_c80cae29 exactly matched response and console error are allowed', () => {
    const result = evaluateHttpAndConsoleEvidence({
        expectedErrors: [
            {
                url: '/admin/users/123',
                status: 500,
                method: 'DELETE',
                matchedResponse: true,
                matchedResponsesCount: 1,
                matchedResponseDetails: {
                    url: `${appOrigin}/admin/users/123`,
                    status: 500,
                    method: 'DELETE',
                },
            },
        ],
        pendingConsoleErrors: [
            {
                url: `${appOrigin}/admin/users/123`,
                location: { url: `${appOrigin}/build/assets/app.js`, lineNumber: 10 },
                text: `Failed to load resource: the server responded with a status of 500 () [DELETE ${appOrigin}/admin/users/123]`,
                meta: { origin: appOrigin, classification: 'app-origin', shouldFail: true },
            },
        ],
        appOrigin,
    });

    expect(result.failingEvidence).toEqual([]);
    expect(result.allowedEvidence.length).toBe(1);
    expect(result.allowedEvidence[0].type).toBe('console.error');
    expect(result.allowedEvidence[0].expected).toBe(true);
});

test('@kanban-t_c80cae29 unexpected extra console error or wrong method fails closed', () => {
    const result = evaluateHttpAndConsoleEvidence({
        expectedErrors: [
            {
                url: '/admin/users/123',
                status: 500,
                method: 'DELETE',
                matchedResponse: true,
                matchedResponsesCount: 1,
                matchedResponseDetails: {
                    url: `${appOrigin}/admin/users/123`,
                    status: 500,
                    method: 'DELETE',
                },
            },
        ],
        pendingConsoleErrors: [
            // Exact matching one
            {
                url: `${appOrigin}/admin/users/123`,
                location: { url: `${appOrigin}/build/assets/app.js`, lineNumber: 10 },
                text: `Failed to load resource: the server responded with a status of 500 () [DELETE ${appOrigin}/admin/users/123]`,
                meta: { origin: appOrigin, classification: 'app-origin', shouldFail: true },
            },
            // Extra unexpected console error for different url/method
            {
                url: `${appOrigin}/admin/users/other`,
                location: { url: `${appOrigin}/build/assets/app.js`, lineNumber: 25 },
                text: `Uncaught (in promise) Error: 500 on POST ${appOrigin}/admin/users/other`,
                meta: { origin: appOrigin, classification: 'app-origin', shouldFail: true },
            },
        ],
        appOrigin,
    });

    expect(result.allowedEvidence.length).toBe(1);
    expect(result.failingEvidence.length).toBe(1);
    expect(result.failingEvidence[0].type).toBe('console.error');
    expect(result.failingEvidence[0].text).toContain('/admin/users/other');
});

test('@kanban-t_c80cae29 ambiguous console error matching multiple expectations fails closed', () => {
    const result = evaluateHttpAndConsoleEvidence({
        expectedErrors: [
            {
                url: '/admin/users/123',
                status: 500,
                method: 'DELETE',
                matchedResponse: true,
                matchedResponsesCount: 1,
                matchedResponseDetails: {
                    url: `${appOrigin}/admin/users/123`,
                    status: 500,
                    method: 'DELETE',
                },
            },
            {
                url: '/admin/users/123',
                status: 500,
                method: 'DELETE',
                matchedResponse: true,
                matchedResponsesCount: 1,
                matchedResponseDetails: {
                    url: `${appOrigin}/admin/users/123`,
                    status: 500,
                    method: 'DELETE',
                },
            },
        ],
        pendingConsoleErrors: [
            {
                url: `${appOrigin}/admin/users/123`,
                location: { url: `${appOrigin}/build/assets/app.js`, lineNumber: 10 },
                text: `Failed to load resource: the server responded with a status of 500 () [DELETE ${appOrigin}/admin/users/123]`,
                meta: { origin: appOrigin, classification: 'app-origin', shouldFail: true },
            },
        ],
        appOrigin,
    });

    expect(result.allowedEvidence).toEqual([]);
    expect(result.failingEvidence.length).toBe(1);
    expect(result.failingEvidence[0].classification).toBe('ambiguous-expected-console-error');
});

test('@kanban-t_19c3d597 matchExpectedHttpUrl handles exact path, trailing slashes, queries, and prefixes', () => {
    // Relative path matching against appOrigin
    expect(
        matchExpectedHttpUrl('/admin/users/123', `${appOrigin}/admin/users/123`, appOrigin),
    ).toBe(true);
    expect(
        matchExpectedHttpUrl('/admin/users/123/', `${appOrigin}/admin/users/123`, appOrigin),
    ).toBe(true);
    expect(
        matchExpectedHttpUrl('/admin/users/123', `${appOrigin}/admin/users/123/`, appOrigin),
    ).toBe(true);

    // Prefix/subpath mismatch: must NOT loosely match
    expect(matchExpectedHttpUrl('/admin/users/1', `${appOrigin}/admin/users/123`, appOrigin)).toBe(
        false,
    );
    expect(matchExpectedHttpUrl('/admin/users', `${appOrigin}/admin/users/123`, appOrigin)).toBe(
        false,
    );
    expect(matchExpectedHttpUrl('/admin/users/123', `${appOrigin}/admin/users`, appOrigin)).toBe(
        false,
    );

    // Query parameter sensitivity
    expect(
        matchExpectedHttpUrl(
            '/admin/users?active=1',
            `${appOrigin}/admin/users?active=1`,
            appOrigin,
        ),
    ).toBe(true);
    expect(
        matchExpectedHttpUrl(
            '/admin/users?active=1',
            `${appOrigin}/admin/users?active=2`,
            appOrigin,
        ),
    ).toBe(false);
    expect(
        matchExpectedHttpUrl('/admin/users', `${appOrigin}/admin/users?active=1`, appOrigin),
    ).toBe(false);

    // Full URL exact string matching
    expect(
        matchExpectedHttpUrl(
            `${appOrigin}/admin/users/123`,
            `${appOrigin}/admin/users/123`,
            appOrigin,
        ),
    ).toBe(true);
    expect(
        matchExpectedHttpUrl(
            `${appOrigin}/admin/users/123`,
            `http://other-domain.com/admin/users/123`,
            appOrigin,
        ),
    ).toBe(false);
});

test('@kanban-t_3d181513 matchExpectedHttpUrl isolates off-origin requests and enforces origin-scoped regex', () => {
    const offOrigin = 'https://external-api.com';

    // Relative path expectation only matches appOrigin, not off-origin
    expect(matchExpectedHttpUrl('/api/v1/data', `${offOrigin}/api/v1/data`, appOrigin)).toBe(false);
    expect(matchExpectedHttpUrl('/api/v1/data', `${appOrigin}/api/v1/data`, appOrigin)).toBe(true);

    // Explicit off-origin string expectation matches off-origin URL
    expect(
        matchExpectedHttpUrl(`${offOrigin}/api/v1/data`, `${offOrigin}/api/v1/data`, appOrigin),
    ).toBe(true);

    // Canonical/relative regex expectation only matches appOrigin, fails-closed on off-origin URL with same path
    expect(matchExpectedHttpUrl(/\/api\/v1\/data/, `${offOrigin}/api/v1/data`, appOrigin)).toBe(
        false,
    );
    expect(matchExpectedHttpUrl(/\/api\/v1\/data/, `${appOrigin}/api/v1/data`, appOrigin)).toBe(
        true,
    );
    expect(
        matchExpectedHttpUrl(
            /\/admin\/users\/\d+$/,
            `https://evil.example/admin/users/123`,
            appOrigin,
        ),
    ).toBe(false);
    expect(
        matchExpectedHttpUrl(/\/admin\/users\/\d+$/, `${appOrigin}/admin/users/123`, appOrigin),
    ).toBe(true);

    // Explicit off-origin regex expectation matches off-origin URL
    expect(
        matchExpectedHttpUrl(
            new RegExp(`^${offOrigin.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}/api/v1/data`),
            `${offOrigin}/api/v1/data`,
            appOrigin,
        ),
    ).toBe(true);
    expect(
        matchExpectedHttpUrl(
            /^https:\/\/external-api\.com\/api\/v1\/data/,
            `${offOrigin}/api/v1/data`,
            appOrigin,
        ),
    ).toBe(true);

    // Mismatched regex pattern
    expect(matchExpectedHttpUrl(/\/users\/\d+/, `${offOrigin}/api/v1/data`, appOrigin)).toBe(false);
});

test('@kanban-t_488f0943 registerExpectedHttpError runtime helper validates types and parameters', () => {
    const list: RegisteredExpectedHttpError[] = [];

    // Valid registration
    const registered = registerExpectedHttpError(list, {
        url: '/admin/users/1',
        status: 500,
        method: 'delete',
    });
    expect(registered.method).toBe('DELETE');
    expect(registered.matchedResponse).toBe(false);
    expect(registered.matchedResponsesCount).toBe(0);
    expect(list.length).toBe(1);

    // Negative: missing/invalid URL (non-string/non-RegExp, empty, spaces)
    expect(() => registerExpectedHttpError(list, { url: '', status: 500, method: 'GET' })).toThrow(
        /url must be provided/,
    );
    expect(() =>
        registerExpectedHttpError(list, { url: '   ', status: 500, method: 'GET' }),
    ).toThrow(/url must be provided/);
    expect(() =>
        registerExpectedHttpError(list, {
            url: null as unknown as string,
            status: 500,
            method: 'GET',
        }),
    ).toThrow(/url must be provided/);
    expect(() =>
        registerExpectedHttpError(list, {
            url: 123 as unknown as string,
            status: 500,
            method: 'GET',
        }),
    ).toThrow(/url must be provided/);
    expect(() =>
        registerExpectedHttpError(list, {
            url: {} as unknown as string,
            status: 500,
            method: 'GET',
        }),
    ).toThrow(/url must be provided/);

    // Negative: invalid RegExp (empty or match-all wildcards)
    expect(() =>
        registerExpectedHttpError(list, { url: /(?:)/, status: 500, method: 'GET' }),
    ).toThrow(/url RegExp cannot be empty or match-all wildcard/);
    expect(() => registerExpectedHttpError(list, { url: /^/, status: 500, method: 'GET' })).toThrow(
        /url RegExp cannot be empty or match-all wildcard/,
    );
    expect(() => registerExpectedHttpError(list, { url: /$/, status: 500, method: 'GET' })).toThrow(
        /url RegExp cannot be empty or match-all wildcard/,
    );
    expect(() =>
        registerExpectedHttpError(list, { url: /.*/, status: 500, method: 'GET' }),
    ).toThrow(/url RegExp cannot be empty or match-all wildcard/);
    expect(() =>
        registerExpectedHttpError(list, { url: /^.*$/, status: 500, method: 'GET' }),
    ).toThrow(/url RegExp cannot be empty or match-all wildcard/);
    expect(() =>
        registerExpectedHttpError(list, { url: /a*/, status: 500, method: 'GET' }),
    ).toThrow(/url RegExp cannot be empty or match-all wildcard/);

    // Stateful global/sticky RegExp with non-zero lastIndex: must not bypass empty/match-all rejection
    const statefulMatchAll = /a*/g;
    statefulMatchAll.lastIndex = 5;
    expect(() =>
        registerExpectedHttpError(list, { url: statefulMatchAll, status: 500, method: 'GET' }),
    ).toThrow(/url RegExp cannot be empty or match-all wildcard/);

    const stickyMatchAll = /a*/y;
    stickyMatchAll.lastIndex = 3;
    expect(() =>
        registerExpectedHttpError(list, { url: stickyMatchAll, status: 500, method: 'GET' }),
    ).toThrow(/url RegExp cannot be empty or match-all wildcard/);

    // Non-object expectation
    expect(() =>
        registerExpectedHttpError(
            list,
            null as unknown as { url: string; status: number; method: string },
        ),
    ).toThrow(/expectation must be an object/);

    // Negative: invalid status (<400, >599, non-integer)
    expect(() =>
        registerExpectedHttpError(list, { url: '/test', status: 200, method: 'GET' }),
    ).toThrow(/status must be a valid HTTP error status code/);
    expect(() =>
        registerExpectedHttpError(list, { url: '/test', status: 600, method: 'GET' }),
    ).toThrow(/status must be a valid HTTP error status code/);
    expect(() =>
        registerExpectedHttpError(list, { url: '/test', status: 500.5, method: 'GET' }),
    ).toThrow(/status must be a valid HTTP error status code/);

    // Negative: invalid/missing method
    expect(() =>
        registerExpectedHttpError(list, { url: '/test', status: 500, method: '' }),
    ).toThrow(/method must be a valid HTTP method string/);
    expect(() =>
        registerExpectedHttpError(list, { url: '/test', status: 500, method: '   ' }),
    ).toThrow(/method must be a valid HTTP method string/);
});

test('@kanban-t_488f0943 assignResponseToExpectedErrors executes exact match, zero match, wrong status, wrong method, duplicate, and ambiguity', () => {
    // 1. Exact unique match
    const exp1: RegisteredExpectedHttpError = {
        url: '/admin/users/10',
        status: 500,
        method: 'DELETE',
        matchedResponse: false,
        matchedResponsesCount: 0,
    };
    const res1 = assignResponseToExpectedErrors({
        expectedErrors: [exp1],
        responseUrl: `${appOrigin}/admin/users/10`,
        responseStatus: 500,
        responseMethod: 'DELETE',
        appOrigin,
    });
    expect(res1.isExpected).toBe(true);
    expect(res1.isAmbiguous).toBe(false);
    expect(exp1.matchedResponse).toBe(true);
    expect(exp1.matchedResponsesCount).toBe(1);
    expect(exp1.matchedResponseDetails).toEqual({
        url: `${appOrigin}/admin/users/10`,
        status: 500,
        method: 'DELETE',
    });

    // 2. Extra/duplicate response matching the same already-claimed expectation (matchedResponse === true)
    const resDuplicate = assignResponseToExpectedErrors({
        expectedErrors: [exp1],
        responseUrl: `${appOrigin}/admin/users/10`,
        responseStatus: 500,
        responseMethod: 'DELETE',
        appOrigin,
    });
    // Already-claimed expectation cannot be claimed again -> isExpected is false
    expect(resDuplicate.isExpected).toBe(false);
    expect(resDuplicate.matchingExpectation).toBeUndefined();

    // 3. Wrong status (500 expected vs 5000 or 404 actual)
    const exp2: RegisteredExpectedHttpError = {
        url: '/admin/users/20',
        status: 500,
        method: 'DELETE',
        matchedResponse: false,
        matchedResponsesCount: 0,
    };
    const resWrongStatus = assignResponseToExpectedErrors({
        expectedErrors: [exp2],
        responseUrl: `${appOrigin}/admin/users/20`,
        responseStatus: 404,
        responseMethod: 'DELETE',
        appOrigin,
    });
    expect(resWrongStatus.isExpected).toBe(false);
    expect(exp2.matchedResponse).toBe(false);
    expect(exp2.matchedResponsesCount).toBe(0);

    // 4. Wrong method (DELETE expected vs GET actual)
    const resWrongMethod = assignResponseToExpectedErrors({
        expectedErrors: [exp2],
        responseUrl: `${appOrigin}/admin/users/20`,
        responseStatus: 500,
        responseMethod: 'GET',
        appOrigin,
    });
    expect(resWrongMethod.isExpected).toBe(false);
    expect(exp2.matchedResponse).toBe(false);

    // 5. Ambiguous / duplicate expectations for the exact same route/status/method
    const expDupA: RegisteredExpectedHttpError = {
        url: '/admin/users/30',
        status: 500,
        method: 'DELETE',
        matchedResponse: false,
        matchedResponsesCount: 0,
    };
    const expDupB: RegisteredExpectedHttpError = {
        url: '/admin/users/30',
        status: 500,
        method: 'DELETE',
        matchedResponse: false,
        matchedResponsesCount: 0,
    };
    const resAmbiguous = assignResponseToExpectedErrors({
        expectedErrors: [expDupA, expDupB],
        responseUrl: `${appOrigin}/admin/users/30`,
        responseStatus: 500,
        responseMethod: 'DELETE',
        appOrigin,
    });
    expect(resAmbiguous.isExpected).toBe(false);
    expect(resAmbiguous.isAmbiguous).toBe(true);
    expect(expDupA.matchedResponsesCount).toBe(1);
    expect(expDupB.matchedResponsesCount).toBe(1);
});

test('@kanban-t_488f0943 matchConsoleStatus isolates token exactness (500 vs 5000 and status only in URL)', () => {
    // Exact phrase and status code
    expect(matchConsoleStatus('Failed to load resource: status of 500 ()', 500)).toBe(true);
    expect(matchConsoleStatus('Server error: HTTP 500 (Internal Server Error)', 500)).toBe(true);
    expect(matchConsoleStatus('status: 500', 500)).toBe(true);
    expect(matchConsoleStatus('status=500', 500)).toBe(true);
    expect(matchConsoleStatus('Error: 500 (Internal Server Error)', 500)).toBe(true);

    // Isolated token 500 in message
    expect(matchConsoleStatus('Server returned error 500 occurred in controller', 500)).toBe(true);

    // Negative: 5000 must NOT match 500
    expect(matchConsoleStatus('Failed to load resource: status of 5000 ()', 500)).toBe(false);
    expect(matchConsoleStatus('HTTP 5000', 500)).toBe(false);
    expect(matchConsoleStatus('Server returned error 5000', 500)).toBe(false);
    expect(matchConsoleStatus('Timeout after 5000 ms', 500)).toBe(false);

    // Negative: 500 appearing ONLY inside URL path or query
    expect(
        matchConsoleStatus(
            `Failed to load resource: ${appOrigin}/admin/users/500/details [GET]`,
            500,
        ),
    ).toBe(false);
    expect(
        matchConsoleStatus(`Failed to load resource: ${appOrigin}/api/items?limit=500 [GET]`, 500),
    ).toBe(false);
    expect(matchConsoleStatus('Resource at /admin/reports/500 failed to load', 500)).toBe(false);
});

test('@kanban-t_488f0943 matchConsoleMethod enforces exact token and narrow browser-default (arbitrary absent fails closed)', () => {
    // Exact method matches
    expect(matchConsoleMethod('Failed to load resource [DELETE /admin/users/123]', 'DELETE')).toBe(
        true,
    );
    expect(matchConsoleMethod('POST request failed on /admin/users', 'POST')).toBe(true);
    expect(matchConsoleMethod('HTTP GET returned 404', 'GET')).toBe(true);

    // Narrow browser-default error line ("Failed to load resource: the server responded with a status of 500") is allowed
    expect(
        matchConsoleMethod(
            'Failed to load resource: the server responded with a status of 500 (Internal Server Error)',
            'DELETE',
        ),
    ).toBe(true);
    expect(
        matchConsoleMethod(
            'Failed to load resource: the server responded with a status of 404 (Not Found)',
            'GET',
        ),
    ).toBe(true);

    // Arbitrary text where method is absent fails closed
    expect(matchConsoleMethod('Error on /admin/users/123: 500 Internal Server Error', 'GET')).toBe(
        false,
    );
    expect(
        matchConsoleMethod(
            'Arbitrary failure status 500 at http://127.0.0.1:8010/admin/users/123',
            'DELETE',
        ),
    ).toBe(false);

    // Negative: DELETED token must not match DELETE
    expect(matchConsoleMethod('User was DELETED from server with error 500', 'DELETE')).toBe(false);

    // Negative: wrong method token (POST present, expecting DELETE)
    expect(matchConsoleMethod('Failed to load resource [POST /admin/users/123]', 'DELETE')).toBe(
        false,
    );
});

test('@kanban-t_488f0943 matchExpectedHttpUrl rejects stateful RegExp g/y, mixed scheme, and prepended path', () => {
    // Stateful regex g flag stripped / normalized without side effect across calls
    const globalRegex = new RegExp('/admin/users/\\d+', 'g');
    expect(matchExpectedHttpUrl(globalRegex, `${appOrigin}/admin/users/123`, appOrigin)).toBe(true);
    expect(matchExpectedHttpUrl(globalRegex, `${appOrigin}/admin/users/123`, appOrigin)).toBe(true);
    expect(matchExpectedHttpUrl(globalRegex, `${appOrigin}/admin/users/123`, appOrigin)).toBe(true);

    // Stateful regex y (sticky) flag
    const stickyRegex = new RegExp('/admin/users/\\d+', 'y');
    expect(matchExpectedHttpUrl(stickyRegex, `${appOrigin}/admin/users/123`, appOrigin)).toBe(true);

    // Mixed scheme mismatch (http vs https)
    expect(
        matchExpectedHttpUrl(
            `https://127.0.0.1:8010/admin/users/123`,
            `${appOrigin}/admin/users/123`,
            appOrigin,
        ),
    ).toBe(false);

    // Prepended path / prefix mismatch
    expect(
        matchExpectedHttpUrl(
            '/admin/users/123',
            `${appOrigin}/v1/prefix/admin/users/123`,
            appOrigin,
        ),
    ).toBe(false);
    expect(matchExpectedHttpUrl('/users/123', `${appOrigin}/admin/users/123`, appOrigin)).toBe(
        false,
    );
    expect(
        matchExpectedHttpUrl(`${appOrigin}/users/123`, `${appOrigin}/admin/users/123`, appOrigin),
    ).toBe(false);
});

guarded(
    '@kanban-t_488f0943 guarded fixture page event listeners execute runtime response-assignment and console correlation',
    async ({ page, baseURL, expectHttpError }) => {
        const errorPath = '/admin/simulated-error-500';
        const errorUrl = `${baseURL}${errorPath}`;

        expectHttpError({
            url: errorPath,
            status: 500,
            method: 'DELETE',
        });

        await page.route(errorUrl, (route) =>
            route.fulfill({
                status: 500,
                contentType: 'application/json',
                body: JSON.stringify({ message: 'Internal Server Error' }),
            }),
        );

        await page.route(`${baseURL}/`, (route) =>
            route.fulfill({
                status: 200,
                contentType: 'text/html',
                body: '<!doctype html><title>guarded test</title>',
            }),
        );

        await page.goto('/');

        // Trigger response listener (via in-page fetch)
        await page.evaluate(async (url) => {
            // Fetch to trigger response listener on routed errorUrl
            try {
                await fetch(url, { method: 'DELETE' });
            } catch {
                // ignore in page context
            }
        }, errorUrl);
    },
);

test('@kanban-t_f9b7cd83 guarded session fails closed on unexpected requestfailed event', async ({
    page,
    baseURL,
}) => {
    const brokenUrl = `${baseURL}/build/assets/non-existent-chunk-${Date.now()}.js`;
    await page.route(`${baseURL}/`, (route) =>
        route.fulfill({
            status: 200,
            contentType: 'text/html',
            body: '<!doctype html><title>unexpected failure test</title>',
        }),
    );
    await page.goto('/');

    const result = await runGuardedPageSession({
        page,
        baseURL,
        fn: async () => {
            await page.evaluate(async (url) => {
                try {
                    await fetch(url);
                } catch {
                    // net fetch error
                }
            }, brokenUrl);
            await page.waitForLoadState('networkidle').catch(() => {});
            await page.waitForTimeout(200);
        },
    });

    expect(result.failingEvidence.length).toBeGreaterThanOrEqual(1);
    expect(result.failingEvidence.some((e) => e.type === 'requestfailed')).toBe(true);
});

test('@kanban-t_f9b7cd83 guarded session fails closed on unexpected pageerror event', async ({
    page,
    baseURL,
}) => {
    await page.route(`${baseURL}/`, (route) =>
        route.fulfill({
            status: 200,
            contentType: 'text/html',
            body: '<!doctype html><title>pageerror test</title>',
        }),
    );
    await page.goto('/');

    const result = await runGuardedPageSession({
        page,
        baseURL,
        fn: async () => {
            await page.evaluate(() => {
                setTimeout(() => {
                    throw new Error('Simulated unhandled page crash');
                }, 10);
            });
            await page.waitForTimeout(100);
        },
    });

    expect(result.failingEvidence.length).toBeGreaterThanOrEqual(1);
    expect(result.failingEvidence.some((e) => e.type === 'pageerror')).toBe(true);
});

test('@kanban-t_f9b7cd83 guarded session fails closed on unexpected HTTP 500 when not registered', async ({
    page,
    baseURL,
}) => {
    const errorPath = '/admin/unexpected-error-500';
    const errorUrl = `${baseURL}${errorPath}`;

    await page.route(errorUrl, (route) =>
        route.fulfill({
            status: 500,
            contentType: 'application/json',
            body: JSON.stringify({ error: 'Server exploded' }),
        }),
    );

    await page.route(`${baseURL}/`, (route) =>
        route.fulfill({
            status: 200,
            contentType: 'text/html',
            body: '<!doctype html><title>unexpected 500</title>',
        }),
    );
    await page.goto('/');

    const result = await runGuardedPageSession({
        page,
        baseURL,
        fn: async () => {
            await page.evaluate(async (url) => {
                try {
                    await fetch(url, { method: 'GET' });
                } catch {}
            }, errorUrl);
            await page.waitForTimeout(100);
        },
    });

    // In browser context, fetch 500 triggers http-error and browser console error
    expect(result.failingEvidence.length).toBeGreaterThanOrEqual(1);
    expect(result.failingEvidence.some((e) => e.type === 'http-error')).toBe(true);
});

test('@kanban-t_f9b7cd83 guarded session fails closed on duplicate expected HTTP response', async ({
    page,
    baseURL,
}) => {
    const errorPath = '/admin/single-use-error';
    const errorUrl = `${baseURL}${errorPath}`;

    await page.route(errorUrl, (route) =>
        route.fulfill({
            status: 500,
            contentType: 'application/json',
            body: JSON.stringify({ error: 'Server exploded' }),
        }),
    );

    await page.route(`${baseURL}/`, (route) =>
        route.fulfill({
            status: 200,
            contentType: 'text/html',
            body: '<!doctype html><title>duplicate response test</title>',
        }),
    );
    await page.goto('/');

    const result = await runGuardedPageSession({
        page,
        baseURL,
        fn: async ({ expectHttpError: register }) => {
            register({
                url: errorPath,
                status: 500,
                method: 'DELETE',
            });

            // Fire twice! First consumes, second is unexpected extra!
            await page.evaluate(async (url) => {
                try {
                    await fetch(url, { method: 'DELETE' });
                    await fetch(url, { method: 'DELETE' });
                } catch {}
            }, errorUrl);
            await page.waitForTimeout(100);
        },
    });

    expect(result.failingEvidence.length).toBeGreaterThanOrEqual(1);
    expect(result.failingEvidence.some((e) => e.type === 'http-error')).toBe(true);
});

test('@kanban-t_f9b7cd83 guarded session fails closed on prepended path for canonical regex', async ({
    page,
    baseURL,
}) => {
    const evilPath = '/evil/admin/users/123';
    const evilUrl = `${baseURL}${evilPath}`;

    await page.route(evilUrl, (route) =>
        route.fulfill({
            status: 500,
            contentType: 'application/json',
            body: JSON.stringify({ error: 'Evil endpoint' }),
        }),
    );

    await page.route(`${baseURL}/`, (route) =>
        route.fulfill({
            status: 200,
            contentType: 'text/html',
            body: '<!doctype html><title>prepended regex</title>',
        }),
    );
    await page.goto('/');

    const result = await runGuardedPageSession({
        page,
        baseURL,
        fn: async ({ expectHttpError: register }) => {
            register({
                url: /\/admin\/users\/\d+$/,
                status: 500,
                method: 'DELETE',
            });

            await page.evaluate(async (url) => {
                try {
                    await fetch(url, { method: 'DELETE' });
                } catch {}
            }, evilUrl);
            await page.waitForTimeout(100);
        },
    });

    // Fails closed because evil path was not expected (unexpected response + unmatched expectation)
    expect(result.failingEvidence.length).toBeGreaterThanOrEqual(2);
    expect(result.failingEvidence.some((e) => e.type === 'http-error')).toBe(true);
    expect(result.failingEvidence.some((e) => e.type === 'unmatched-expected-http-error')).toBe(
        true,
    );
});

test('@kanban-t_f9b7cd83 guarded session fails closed on extra query param for canonical regex', async ({
    page,
    baseURL,
}) => {
    const queryPath = '/admin/users/123?extra=1';
    const queryUrl = `${baseURL}${queryPath}`;

    await page.route(queryUrl, (route) =>
        route.fulfill({
            status: 500,
            contentType: 'application/json',
            body: JSON.stringify({ error: 'Query endpoint' }),
        }),
    );

    await page.route(`${baseURL}/`, (route) =>
        route.fulfill({
            status: 200,
            contentType: 'text/html',
            body: '<!doctype html><title>query regex</title>',
        }),
    );
    await page.goto('/');

    const result = await runGuardedPageSession({
        page,
        baseURL,
        fn: async ({ expectHttpError: register }) => {
            register({
                url: /\/admin\/users\/\d+$/,
                status: 500,
                method: 'DELETE',
            });

            await page.evaluate(async (url) => {
                try {
                    await fetch(url, { method: 'DELETE' });
                } catch {}
            }, queryUrl);
            await page.waitForTimeout(100);
        },
    });

    // Fails closed because query URL did not match regex pattern anchored to end of path (unexpected response + unmatched expectation)
    expect(result.failingEvidence.length).toBeGreaterThanOrEqual(2);
    expect(result.failingEvidence.some((e) => e.type === 'http-error')).toBe(true);
    expect(result.failingEvidence.some((e) => e.type === 'unmatched-expected-http-error')).toBe(
        true,
    );
});

test('@kanban-t_ced3c267 guarded session fails closed on stateful match-all regex registrar', async ({
    page,
    baseURL,
}) => {
    await page.route(`${baseURL}/`, (route) =>
        route.fulfill({
            status: 200,
            contentType: 'text/html',
            body: '<!doctype html><title>stateful regex registrar</title>',
        }),
    );
    await page.goto('/');

    const statefulGlobal = /a*/g;
    statefulGlobal.lastIndex = 5;
    let thrownErrorGlobal: Error | null = null;

    const statefulSticky = /a*/y;
    statefulSticky.lastIndex = 3;
    let thrownErrorSticky: Error | null = null;

    const result = await runGuardedPageSession({
        page,
        baseURL,
        fn: async ({ expectHttpError: register }) => {
            try {
                register({
                    url: statefulGlobal,
                    status: 500,
                    method: 'GET',
                });
            } catch (err) {
                thrownErrorGlobal = err as Error;
            }

            try {
                register({
                    url: statefulSticky,
                    status: 500,
                    method: 'GET',
                });
            } catch (err) {
                thrownErrorSticky = err as Error;
            }
        },
    });

    expect(thrownErrorGlobal).not.toBeNull();
    expect((thrownErrorGlobal as Error | null)?.message).toMatch(
        /url RegExp cannot be empty or match-all wildcard/,
    );
    expect(statefulGlobal.lastIndex).toBe(5);

    expect(thrownErrorSticky).not.toBeNull();
    expect((thrownErrorSticky as Error | null)?.message).toMatch(
        /url RegExp cannot be empty or match-all wildcard/,
    );
    expect(statefulSticky.lastIndex).toBe(3);

    expect(result.failingEvidence).toEqual([]);
});

test('@kanban-t_ced3c267 guarded session fails closed on incidental console error message', async ({
    page,
    baseURL,
}) => {
    const errorPath = '/admin/users/123';
    const errorUrl = `${baseURL}${errorPath}`;

    await page.route(errorUrl, (route) =>
        route.fulfill({
            status: 500,
            contentType: 'application/json',
            body: JSON.stringify({ error: 'Server Error' }),
        }),
    );

    await page.route(`${baseURL}/`, (route) =>
        route.fulfill({
            status: 200,
            contentType: 'text/html',
            body: '<!doctype html><title>incidental console</title>',
        }),
    );
    await page.goto('/');

    const result = await runGuardedPageSession({
        page,
        baseURL,
        fn: async ({ expectHttpError: register }) => {
            register({
                url: errorPath,
                status: 500,
                method: 'DELETE',
            });

            // Trigger valid HTTP error response and also emit incidental console error
            await page.evaluate(async (url) => {
                try {
                    await fetch(url, { method: 'DELETE' });
                } catch {}
                console.error('Retry after 500 ms');
            }, errorUrl);
            await page.waitForTimeout(100);
        },
    });

    // Incidental console error ("Retry after 500 ms") must fail closed in evaluateHttpAndConsoleEvidence
    expect(result.failingEvidence.length).toBeGreaterThanOrEqual(1);
    expect(
        result.failingEvidence.some(
            (e) => e.type === 'console.error' && (e.text as string).includes('Retry after 500 ms'),
        ),
    ).toBe(true);
});

test('@kanban-t_ced3c267 guarded session fails closed on mixed-status console error message', async ({
    page,
    baseURL,
}) => {
    const errorPath = '/admin/users/123';
    const errorUrl = `${baseURL}${errorPath}`;

    await page.route(errorUrl, (route) =>
        route.fulfill({
            status: 500,
            contentType: 'application/json',
            body: JSON.stringify({ error: 'Server Error' }),
        }),
    );

    await page.route(`${baseURL}/`, (route) =>
        route.fulfill({
            status: 200,
            contentType: 'text/html',
            body: '<!doctype html><title>mixed status console</title>',
        }),
    );
    await page.goto('/');

    const result = await runGuardedPageSession({
        page,
        baseURL,
        fn: async ({ expectHttpError: register }) => {
            register({
                url: errorPath,
                status: 500,
                method: 'DELETE',
            });

            // Trigger valid HTTP error response and emit mixed-status console message
            await page.evaluate(async (url) => {
                try {
                    await fetch(url, { method: 'DELETE' });
                } catch {}
                console.error(
                    `Failed to load resource [DELETE ${url}]: status 404 then status 500`,
                );
            }, errorUrl);
            await page.waitForTimeout(100);
        },
    });

    // Mixed status in console error fails closed
    expect(result.failingEvidence.length).toBeGreaterThanOrEqual(1);
    expect(
        result.failingEvidence.some(
            (e) =>
                e.type === 'console.error' &&
                (e.text as string).includes('status 404 then status 500'),
        ),
    ).toBe(true);
});

test('@kanban-t_ced3c267 guarded session fails closed on mixed-method console error message', async ({
    page,
    baseURL,
}) => {
    const errorPath = '/admin/users/123';
    const errorUrl = `${baseURL}${errorPath}`;

    await page.route(errorUrl, (route) =>
        route.fulfill({
            status: 500,
            contentType: 'application/json',
            body: JSON.stringify({ error: 'Server Error' }),
        }),
    );

    await page.route(`${baseURL}/`, (route) =>
        route.fulfill({
            status: 200,
            contentType: 'text/html',
            body: '<!doctype html><title>mixed method console</title>',
        }),
    );
    await page.goto('/');

    const result = await runGuardedPageSession({
        page,
        baseURL,
        fn: async ({ expectHttpError: register }) => {
            register({
                url: errorPath,
                status: 500,
                method: 'DELETE',
            });

            // Trigger valid HTTP error response and emit mixed-method console message
            await page.evaluate(async (url) => {
                try {
                    await fetch(url, { method: 'DELETE' });
                } catch {}
                console.error(`Failed to load resource [${url}]: status 500 for GET then DELETE`);
            }, errorUrl);
            await page.waitForTimeout(100);
        },
    });

    // Mixed method tokens in console error fail closed
    expect(result.failingEvidence.length).toBeGreaterThanOrEqual(1);
    expect(
        result.failingEvidence.some(
            (e) =>
                e.type === 'console.error' &&
                (e.text as string).includes('status 500 for GET then DELETE'),
        ),
    ).toBe(true);
});

test('@kanban-t_1ba38de2 allows exactly one correlated OpenStreetMap tile failure', () => {
    const tileUrl = 'https://tile.openstreetmap.org/12/1234/5678.png';
    const result = evaluateHttpAndConsoleEvidence({
        expectedErrors: [],
        pendingRequestFailures: [{ url: tileUrl, resourceType: 'image', error: 'net::ERR_FAILED' }],
        pendingConsoleErrors: [
            {
                url: tileUrl,
                location: { url: `${appOrigin}/build/assets/app.js` },
                text: `Failed to load resource: net::ERR_FAILED ${tileUrl}`,
                meta: {
                    origin: 'https://tile.openstreetmap.org',
                    classification: 'external-noncritical',
                    shouldFail: false,
                },
            },
        ],
        appOrigin,
    });

    expect(result.failingEvidence).toEqual([]);
    expect(result.allowedEvidence).toHaveLength(1);
    expect(result.allowedEvidence[0].matchedRequestFailure).toBe(tileUrl);
});

test('@kanban-t_1ba38de2 rejects same-origin, generic, wrong, duplicate, and overlapping tile evidence', () => {
    const tileUrl = 'https://tile.openstreetmap.org/12/1234/5678.png';
    const consoleError = (
        url: string,
        text = `Failed to load resource: net::ERR_FAILED ${url}`,
    ) => ({
        url,
        location: { url: `${appOrigin}/build/assets/app.js` },
        text,
        meta: {
            origin: new URL(url).origin,
            classification: 'external-noncritical',
            shouldFail: false,
        },
    });
    const evaluate = (
        pendingRequestFailures: Array<{ url: string; resourceType: string; error: string }>,
        pendingConsoleErrors = [consoleError(tileUrl)],
    ) =>
        evaluateHttpAndConsoleEvidence({
            expectedErrors: [],
            pendingRequestFailures,
            pendingConsoleErrors,
            appOrigin,
        });

    expect(
        evaluate(
            [
                {
                    url: `${appOrigin}/map/12/1234/5678.png`,
                    resourceType: 'image',
                    error: 'net::ERR_FAILED',
                },
            ],
            [consoleError(`${appOrigin}/map/12/1234/5678.png`)],
        ).failingEvidence,
    ).not.toEqual([]);
    expect(
        evaluate(
            [
                {
                    url: 'https://cdn.example.test/tile.png',
                    resourceType: 'image',
                    error: 'net::ERR_FAILED',
                },
            ],
            [consoleError('https://cdn.example.test/tile.png')],
        ).failingEvidence,
    ).not.toEqual([]);
    expect(
        evaluate([{ url: tileUrl, resourceType: 'script', error: 'net::ERR_FAILED' }])
            .failingEvidence,
    ).not.toEqual([]);
    expect(
        evaluate([{ url: tileUrl, resourceType: 'image', error: 'net::ERR_ABORTED' }])
            .failingEvidence,
    ).not.toEqual([]);
    expect(
        evaluate([
            { url: tileUrl, resourceType: 'image', error: 'net::ERR_FAILED' },
            {
                url: 'https://a.tile.openstreetmap.org/12/1234/5678.png',
                resourceType: 'image',
                error: 'net::ERR_FAILED',
            },
        ]).failingEvidence,
    ).not.toEqual([]);
    expect(
        evaluate(
            [{ url: tileUrl, resourceType: 'image', error: 'net::ERR_FAILED' }],
            [consoleError(tileUrl), consoleError(tileUrl)],
        ).failingEvidence,
    ).not.toEqual([]);
    expect(
        evaluate(
            [{ url: tileUrl, resourceType: 'image', error: 'net::ERR_FAILED' }],
            [
                consoleError(tileUrl),
                consoleError(
                    `${appOrigin}/api/other`,
                    `Unrelated external console error for ${appOrigin}/api/other`,
                ),
            ],
        ).failingEvidence,
    ).not.toEqual([]);

    const expectedErrors: RegisteredExpectedHttpError[] = [
        {
            url: '/map/expected',
            status: 500,
            method: 'GET',
            matchedResponse: true,
            matchedResponsesCount: 1,
            matchedResponseDetails: {
                url: `${appOrigin}/map/expected`,
                status: 500,
                method: 'GET',
            },
        },
    ];
    expect(
        evaluateHttpAndConsoleEvidence({
            expectedErrors,
            pendingRequestFailures: [
                { url: tileUrl, resourceType: 'image', error: 'net::ERR_FAILED' },
            ],
            pendingConsoleErrors: [
                consoleError(tileUrl),
                {
                    url: `${appOrigin}/map/expected`,
                    location: { url: `${appOrigin}/build/assets/app.js` },
                    text: `Failed to load resource: the server responded with a status of 500 [GET ${appOrigin}/map/expected]`,
                    meta: { origin: appOrigin, classification: 'app-origin', shouldFail: true },
                },
            ],
            appOrigin,
        }).failingEvidence,
    ).not.toEqual([]);
});

test('@kanban-t_ced3c267 guarded session fails closed on arbitrary method-absent console error message', async ({
    page,
    baseURL,
}) => {
    const errorPath = '/admin/users/123';
    const errorUrl = `${baseURL}${errorPath}`;

    await page.route(errorUrl, (route) =>
        route.fulfill({
            status: 500,
            contentType: 'application/json',
            body: JSON.stringify({ error: 'Server Error' }),
        }),
    );

    await page.route(`${baseURL}/`, (route) =>
        route.fulfill({
            status: 200,
            contentType: 'text/html',
            body: '<!doctype html><title>method absent console</title>',
        }),
    );
    await page.goto('/');

    const result = await runGuardedPageSession({
        page,
        baseURL,
        fn: async ({ expectHttpError: register }) => {
            register({
                url: errorPath,
                status: 500,
                method: 'DELETE',
            });

            // Trigger valid HTTP error response and emit arbitrary method-absent console message
            await page.evaluate(async (url) => {
                try {
                    await fetch(url, { method: 'DELETE' });
                } catch {}
                console.error(`Arbitrary failure status 500 at ${url}`);
            }, errorUrl);
            await page.waitForTimeout(100);
        },
    });

    // Method-absent custom text fails closed
    expect(result.failingEvidence.length).toBeGreaterThanOrEqual(1);
    expect(
        result.failingEvidence.some(
            (e) =>
                e.type === 'console.error' &&
                (e.text as string).includes('Arbitrary failure status 500'),
        ),
    ).toBe(true);
});
