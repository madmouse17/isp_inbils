import { expect, test as base } from '@playwright/test';
import type { Page, Request, TestInfo } from '@playwright/test';

type EvidenceValue = string | number | boolean | null | undefined | Evidence;
type Evidence = { [key: string]: EvidenceValue };

type AbortCorrelation = {
    type:
        | 'inertia:before'
        | 'inertia:start'
        | 'inertia:finish'
        | 'inertia:navigate'
        | 'inertia:prefetching'
        | 'document-request'
        | 'inertia-request'
        | 'framenavigated'
        | 'fixture-teardown'
        | 'page-close'
        | 'context-close';
    pageUrl: string;
    sequence: number;
    visitUrl?: string | null;
    visitMethod?: string | null;
    visitCancelled?: boolean;
    visitInterrupted?: boolean;
    visitCompleted?: boolean;
};

type RequestFailure = {
    url: string;
    appOrigin: string;
    resourceType: string;
    error: string;
    navigationMovedAway: boolean | null;
    abortCorrelation?: AbortCorrelation | null;
};

type PendingConsoleError = {
    url: string;
    location: { url: string; lineNumber?: number; columnNumber?: number };
    text: string;
    meta: { origin: string; classification: string; shouldFail: boolean };
};

type LifecycleEvent = AbortCorrelation & { at: number };

type PendingRequestFailure = Omit<RequestFailure, 'appOrigin' | 'abortCorrelation'> & {
    origin: string;
    method: string;
    initialFrameUrl: string | null;
    currentFrameUrl: string | null;
    pageUrl: string;
    startedAt: number;
    failedAt: number;
    requestPurpose: string | null;
    requestSecPurpose: string | null;
    lifecycleSequenceAtStart: number;
    lifecycleSequenceAtFailure: number;
    teardownCorrelation?: AbortCorrelation | null;
};

const externalNonCriticalTypes = new Set(['image', 'media', 'other']);

export type ExpectedHttpError = {
    url: string | RegExp;
    status: number;
    method: string;
};

export type MatchedResponseDetails = {
    url: string;
    status: number;
    method: string;
};

export type RegisteredExpectedHttpError = ExpectedHttpError & {
    matchedResponse?: boolean;
    matchedResponsesCount?: number;
    matchedResponseDetails?: MatchedResponseDetails;
};

export function registerExpectedHttpError(
    expectedErrors: RegisteredExpectedHttpError[],
    expectation: ExpectedHttpError,
): RegisteredExpectedHttpError {
    if (!expectation || typeof expectation !== 'object') {
        throw new Error('expectHttpError: expectation must be an object');
    }
    if (typeof expectation.url !== 'string' && !(expectation.url instanceof RegExp)) {
        throw new Error('expectHttpError: url must be provided as a non-empty string or RegExp');
    }
    if (typeof expectation.url === 'string' && !expectation.url.trim()) {
        throw new Error('expectHttpError: url must be provided as a non-empty string or RegExp');
    }
    if (expectation.url instanceof RegExp) {
        const src = expectation.url.source.trim();
        const cleanRegex = new RegExp(
            expectation.url.source,
            expectation.url.flags.replace(/[gy]/g, ''),
        );
        if (
            !src ||
            src === '(?:)' ||
            src === '^' ||
            src === '$' ||
            src === '.*' ||
            src === '.*?' ||
            src === '^.*$' ||
            cleanRegex.test('')
        ) {
            throw new Error('expectHttpError: url RegExp cannot be empty or match-all wildcard');
        }
    }
    if (
        typeof expectation.status !== 'number' ||
        !Number.isInteger(expectation.status) ||
        expectation.status < 400 ||
        expectation.status > 599
    ) {
        throw new Error(
            `expectHttpError: status must be a valid HTTP error status code (400-599), received: ${expectation.status}`,
        );
    }
    if (typeof expectation.method !== 'string' || !expectation.method.trim()) {
        throw new Error(
            `expectHttpError: method must be a valid HTTP method string (e.g. GET, POST, DELETE), received: ${expectation.method}`,
        );
    }
    const registered: RegisteredExpectedHttpError = {
        url: expectation.url,
        status: expectation.status,
        method: expectation.method.trim().toUpperCase(),
        matchedResponse: false,
        matchedResponsesCount: 0,
    };
    expectedErrors.push(registered);

    return registered;
}

export async function runGuardedPageSession({
    page,
    baseURL,
    testInfo,
    fn,
}: {
    page: Page;
    baseURL?: string;
    testInfo?: TestInfo;
    fn: (context: {
        page: Page;
        expectHttpError: (expectation: ExpectedHttpError) => void;
    }) => Promise<void>;
}): Promise<{ failingEvidence: Evidence[]; allowedEvidence: Evidence[] }> {
    const appOrigin = new URL(baseURL ?? page.url()).origin;
    const failingEvidence: Evidence[] = [];
    const allowedEvidence: Evidence[] = [];
    const expectedErrors: RegisteredExpectedHttpError[] = [];
    const requestFrameUrls = new WeakMap<Request, string | null>();
    const requestStartTimes = new WeakMap<Request, number>();
    const requestLifecycleSequences = new WeakMap<Request, number>();
    const pendingRequestFailures: PendingRequestFailure[] = [];
    const pendingConsoleErrors: PendingConsoleError[] = [];
    const lifecycleEvents: LifecycleEvent[] = [];
    const activePrefetches = new Set<Request>();
    const prefetchWaiters = new Set<() => void>();
    let lastPageUrl = page.url();
    let lifecycleSequence = 0;
    let activeTeardownCorrelation: AbortCorrelation | null = null;

    const expectHttpErrorRegistrar = (expectation: ExpectedHttpError) => {
        registerExpectedHttpError(expectedErrors, expectation);
    };

    (
        page as typeof page & { __e2eExpectHttpError?: (exp: ExpectedHttpError) => void }
    ).__e2eExpectHttpError = expectHttpErrorRegistrar;

    const classify = (url: string, resourceType?: string) => {
        const origin = originOf(url);
        const isAppOrigin = origin === appOrigin;
        const isJourneyBreaking =
            isAppOrigin ||
            ['document', 'script', 'stylesheet', 'font'].includes(resourceType ?? '');

        return {
            origin,
            classification: isAppOrigin
                ? 'app-origin'
                : isJourneyBreaking
                  ? 'external-journey-breaking'
                  : 'external-noncritical',
            shouldFail: isJourneyBreaking,
        };
    };

    const addEvidence = (evidence: Evidence, shouldFail = true) => {
        (shouldFail ? failingEvidence : allowedEvidence).push(evidence);
    };

    const recordLifecycle = (event: Omit<LifecycleEvent, 'at' | 'sequence'>) => {
        lifecycleSequence += 1;
        const recorded = { ...event, sequence: lifecycleSequence, at: Date.now() };
        lifecycleEvents.push(recorded);

        return evidenceEvent(recorded);
    };

    await page.exposeFunction(
        '__e2eRecordLifecycle',
        (event: Omit<LifecycleEvent, 'at' | 'sequence'>) => {
            recordLifecycle(event);
        },
    );

    await page.addInitScript(() => {
        const record = (event: Record<string, unknown>) =>
            (
                window as typeof window & {
                    __e2eRecordLifecycle?: (event: Record<string, unknown>) => void;
                }
            ).__e2eRecordLifecycle?.({ ...event, pageUrl: window.location.href });

        const visitMeta = (event: Event) => {
            const visit = (event as CustomEvent).detail?.visit ?? {};

            return {
                visitUrl: typeof visit.url === 'string' ? visit.url : null,
                visitMethod: typeof visit.method === 'string' ? visit.method : null,
            };
        };

        document.addEventListener('inertia:start', (event) =>
            record({ type: 'inertia:start', ...visitMeta(event) }),
        );
        document.addEventListener('inertia:finish', (event) => {
            const visit = (event as CustomEvent).detail?.visit ?? {};

            record({
                type: 'inertia:finish',
                ...visitMeta(event),
                visitCancelled: Boolean(visit.cancelled),
                visitInterrupted: Boolean(visit.interrupted),
                visitCompleted: Boolean(visit.completed),
            });
        });
        document.addEventListener('inertia:navigate', (event) =>
            record({ type: 'inertia:navigate', ...visitMeta(event) }),
        );
    });

    page.on('framenavigated', (frame) => {
        if (frame !== page.mainFrame()) return;

        lastPageUrl = frame.url();
        recordLifecycle({ type: 'framenavigated', pageUrl: lastPageUrl });
    });
    page.on('close', () => recordLifecycle({ type: 'page-close', pageUrl: lastPageUrl }));
    page.context().on('close', () =>
        recordLifecycle({ type: 'context-close', pageUrl: lastPageUrl }),
    );
    page.on('request', (request) => {
        const headers = request.headers();
        const method = request.method();
        const url = request.url();

        if (isDocumentNavigationRequest(request)) {
            recordLifecycle({
                type: 'document-request',
                pageUrl: url,
                visitUrl: url,
                visitMethod: method,
            });
        } else if (headers['x-inertia'] === 'true') {
            recordLifecycle({
                type: 'inertia-request',
                pageUrl: lastPageUrl,
                visitUrl: url,
                visitMethod: method,
            });
        }

        requestFrameUrls.set(request, frameUrlOf(request));
        requestStartTimes.set(request, Date.now());
        requestLifecycleSequences.set(request, lifecycleSequence);

        if (headers['sec-purpose'] === 'prefetch') {
            activePrefetches.add(request);
        }
    });
    page.on('pageerror', (error) =>
        addEvidence({
            type: 'pageerror',
            url: page.url(),
            origin: originOf(page.url()),
            classification: 'page-runtime',
            message: error.message,
            stack: error.stack ?? null,
        }),
    );
    page.on('console', (message) => {
        if (message.type() !== 'error') return;

        const location = message.location();
        const url = location.url || page.url();
        const text = message.text();
        const meta = classify(url);

        pendingConsoleErrors.push({
            url,
            location: {
                url: location.url || '',
                lineNumber: location.lineNumber,
                columnNumber: location.columnNumber,
            },
            text,
            meta,
        });
    });
    page.on('requestfinished', (request) => {
        activePrefetches.delete(request);
        notifyPrefetchWaiters(prefetchWaiters);
    });
    page.on('requestfailed', (request) => {
        const resourceType = request.resourceType();
        const initialFrameUrl = requestFrameUrls.get(request) ?? null;
        const currentFrameUrl = frameUrlOf(request);
        const navigationMovedAway = initialFrameUrl
            ? currentFrameUrl !== null && currentFrameUrl !== initialFrameUrl
            : null;
        const headers = request.headers();

        activePrefetches.delete(request);
        notifyPrefetchWaiters(prefetchWaiters);
        pendingRequestFailures.push({
            url: request.url(),
            origin: originOf(request.url()),
            resourceType,
            method: request.method(),
            error: request.failure()?.errorText ?? 'unknown',
            initialFrameUrl,
            currentFrameUrl,
            pageUrl: safePageUrl(page, lastPageUrl),
            navigationMovedAway,
            startedAt: requestStartTimes.get(request) ?? Date.now(),
            failedAt: Date.now(),
            requestPurpose: headers.purpose ?? null,
            requestSecPurpose: headers['sec-purpose'] ?? null,
            lifecycleSequenceAtStart: requestLifecycleSequences.get(request) ?? lifecycleSequence,
            lifecycleSequenceAtFailure: lifecycleSequence,
            teardownCorrelation: activeTeardownCorrelation,
        });
    });
    page.on('response', (response) => {
        if (response.status() < 400) return;

        const request = response.request();
        const resourceType = request.resourceType();
        const meta = classify(response.url(), resourceType);
        const responseUrl = response.url();
        const responseStatus = response.status();
        const responseMethod = request.method();

        const { isExpected } = assignResponseToExpectedErrors({
            expectedErrors,
            responseUrl,
            responseStatus,
            responseMethod,
            appOrigin,
        });

        const evidence = {
            type: 'http-error',
            url: responseUrl,
            origin: meta.origin,
            classification: meta.classification,
            resourceType,
            method: responseMethod,
            status: responseStatus,
            statusText: response.statusText(),
            expected: isExpected,
        };

        addEvidence(
            evidence,
            !isExpected && (meta.shouldFail || !externalNonCriticalTypes.has(resourceType)),
        );
    });

    await fn({ page, expectHttpError: expectHttpErrorRegistrar });
    await waitForPrefetches(activePrefetches, prefetchWaiters);

    if (!page.isClosed()) {
        activeTeardownCorrelation = recordLifecycle({
            type: 'fixture-teardown',
            pageUrl: lastPageUrl,
        });
        await page.close({ runBeforeUnload: false }).catch(() => {});
    }

    const evaluatedEvidence = evaluateHttpAndConsoleEvidence({
        expectedErrors,
        pendingConsoleErrors,
        pendingRequestFailures,
        appOrigin,
    });
    failingEvidence.push(...evaluatedEvidence.failingEvidence);
    allowedEvidence.push(...evaluatedEvidence.allowedEvidence);

    for (const failure of pendingRequestFailures) {
        const abortCorrelation = abortCorrelationFor(failure, lifecycleEvents);
        const classification = classifyRequestFailure({
            ...failure,
            appOrigin,
            abortCorrelation,
        });
        const meta = classify(failure.url, failure.resourceType);

        addEvidence(
            {
                type: 'requestfailed',
                url: failure.url,
                origin: failure.origin,
                classification: classification.classification,
                resourceType: failure.resourceType,
                method: failure.method,
                error: failure.error,
                initialFrameUrl: failure.initialFrameUrl,
                currentFrameUrl: failure.currentFrameUrl,
                pageUrl: failure.pageUrl,
                navigationMovedAway: failure.navigationMovedAway,
                requestPurpose: failure.requestPurpose,
                requestSecPurpose: failure.requestSecPurpose,
                correlation: classification.correlation ?? null,
            },
            classification.shouldFail ||
                (!meta.shouldFail && !externalNonCriticalTypes.has(failure.resourceType)),
        );
    }

    if (testInfo) {
        await attachEvidence(testInfo, 'browser-failures.json', failingEvidence);
        await attachEvidence(testInfo, 'browser-allowed-failures.json', allowedEvidence);
    }

    return { failingEvidence, allowedEvidence };
}

export const test = base.extend<{
    expectHttpError: (expectation: ExpectedHttpError) => void;
}>({
    expectHttpError: async ({ page }, use) => {
        const registrar = (
            page as typeof page & { __e2eExpectHttpError?: (exp: ExpectedHttpError) => void }
        ).__e2eExpectHttpError;
        if (!registrar) {
            throw new Error(
                'expectHttpError fixture used on a page where __e2eExpectHttpError was not initialized',
            );
        }
        await use((expectation: ExpectedHttpError) => {
            registrar(expectation);
        });
    },
    page: async ({ page, baseURL }, use, testInfo) => {
        const { failingEvidence } = await runGuardedPageSession({
            page,
            baseURL,
            testInfo,
            fn: async () => {
                await use(page);
            },
        });

        expect(failingEvidence, JSON.stringify(failingEvidence, null, 2)).toEqual([]);
    },
});

async function attachEvidence(testInfo: TestInfo, name: string, evidence: Evidence[]) {
    if (evidence.length === 0) return;

    await testInfo.attach(name, {
        body: JSON.stringify(evidence, null, 2),
        contentType: 'application/json',
    });
}

async function waitForPrefetches(activePrefetches: Set<Request>, waiters: Set<() => void>) {
    while (activePrefetches.size > 0) {
        await new Promise<void>((resolve) => {
            waiters.add(resolve);
        });
    }
}

function notifyPrefetchWaiters(waiters: Set<() => void>) {
    waiters.forEach((resolve) => {
        resolve();
    });

    waiters.clear();
}

export function classifyRequestFailure({
    url,
    appOrigin,
    resourceType,
    error,
    abortCorrelation,
}: RequestFailure) {
    const origin = originOf(url);
    const isAppOrigin = origin === appOrigin;
    const isJourneyBreaking =
        isAppOrigin || ['document', 'script', 'stylesheet', 'font'].includes(resourceType);
    const appOriginAbortClassification = appOriginAbortCorrelationClassification(
        isAppOrigin,
        error,
        abortCorrelation,
    );

    if (appOriginAbortClassification) {
        return {
            classification: appOriginAbortClassification,
            shouldFail: false,
            correlation: abortCorrelation,
        };
    }

    return {
        classification: isAppOrigin
            ? 'app-origin'
            : isJourneyBreaking
              ? 'external-journey-breaking'
              : 'external-noncritical',
        shouldFail: isJourneyBreaking || !externalNonCriticalTypes.has(resourceType),
    };
}

function appOriginAbortCorrelationClassification(
    isAppOrigin: boolean,
    error: string,
    abortCorrelation?: AbortCorrelation | null,
) {
    if (!isAppOrigin || error !== 'net::ERR_ABORTED' || !abortCorrelation) return null;

    if (abortCorrelation.type === 'inertia:finish') {
        return abortCorrelation.visitCancelled || abortCorrelation.visitInterrupted
            ? 'app-origin-inertia-abort'
            : null;
    }

    if (
        abortCorrelation.type === 'document-request' ||
        abortCorrelation.type === 'inertia-request' ||
        abortCorrelation.type === 'framenavigated' ||
        abortCorrelation.type === 'inertia:start' ||
        abortCorrelation.type === 'inertia:navigate'
    ) {
        return 'app-origin-navigation-abort';
    }

    if (
        abortCorrelation.type === 'fixture-teardown' ||
        abortCorrelation.type === 'page-close' ||
        abortCorrelation.type === 'context-close'
    ) {
        return 'app-origin-teardown-abort';
    }

    return null;
}

export function abortCorrelationFor(
    failure: PendingRequestFailure,
    lifecycleEvents: LifecycleEvent[],
) {
    if (failure.error !== 'net::ERR_ABORTED') return null;

    if (failure.teardownCorrelation) return failure.teardownCorrelation;

    const candidates = lifecycleEvents
        .filter(
            (event) =>
                event.sequence > failure.lifecycleSequenceAtStart &&
                event.sequence <= failure.lifecycleSequenceAtFailure,
        )
        .filter((event) =>
            Boolean(
                appOriginAbortCorrelationClassification(true, failure.error, evidenceEvent(event)),
            ),
        )
        .sort((a, b) => a.sequence - b.sequence);

    if (candidates[0]) return evidenceEvent(candidates[0]);

    // The old page can still ask for something (a font its lazy panel just rendered) while the next document is already
    // on its way: that request starts after the document request is recorded and is cancelled when the navigation
    // commits, before the frame URL changes. No event falls inside its own window then, but the navigation that was
    // pending when it failed (and did commit afterwards) is what cancelled it.
    const pending = lifecycleEvents
        .filter(
            (event) =>
                event.type === 'document-request' &&
                event.sequence <= failure.lifecycleSequenceAtFailure,
        )
        .sort((a, b) => b.sequence - a.sequence)[0];
    const commit = pending
        ? lifecycleEvents.find(
              (event) => event.type === 'framenavigated' && event.sequence > pending.sequence,
          )
        : undefined;

    return pending && commit && commit.sequence > failure.lifecycleSequenceAtFailure
        ? evidenceEvent(pending)
        : null;
}

export function assignResponseToExpectedErrors({
    expectedErrors,
    responseUrl,
    responseStatus,
    responseMethod,
    appOrigin,
}: {
    expectedErrors: RegisteredExpectedHttpError[];
    responseUrl: string;
    responseStatus: number;
    responseMethod: string;
    appOrigin: string;
}): {
    matchingExpectation?: RegisteredExpectedHttpError;
    isExpected: boolean;
    isAmbiguous: boolean;
} {
    const normalizedMethod = responseMethod.toUpperCase();

    // Match candidates with exactness rules:
    // - Response URL must match expectation URL (exact origin/path for string, or root-scoped regex)
    // - Candidate must be from appOrigin unless expected error explicitly specifies an off-origin URL
    // - Status and method must match exactly
    // - Unmatched expectations only (an already-matched expectation cannot be claimed again)
    const candidateIndexes = expectedErrors
        .map((exp, idx) => ({ exp, idx }))
        .filter(({ exp }) => {
            if (exp.matchedResponse) return false;
            const urlMatch = matchExpectedHttpUrl(exp.url, responseUrl, appOrigin);
            const statusMatch = exp.status === responseStatus;
            const methodMatch = exp.method.toUpperCase() === normalizedMethod;

            return urlMatch && statusMatch && methodMatch;
        })
        .map(({ idx }) => idx);

    let matchingExpectation: RegisteredExpectedHttpError | undefined;
    let isAmbiguous = false;

    if (candidateIndexes.length === 1) {
        matchingExpectation = expectedErrors[candidateIndexes[0]];
        matchingExpectation.matchedResponse = true;
        matchingExpectation.matchedResponsesCount =
            (matchingExpectation.matchedResponsesCount ?? 0) + 1;
        matchingExpectation.matchedResponseDetails = {
            url: responseUrl,
            status: responseStatus,
            method: normalizedMethod,
        };
    } else if (candidateIndexes.length > 1) {
        isAmbiguous = true;
        // Ambiguous expectations: mark matchedResponsesCount on all candidates so teardown fails closed
        for (const idx of candidateIndexes) {
            expectedErrors[idx].matchedResponsesCount =
                (expectedErrors[idx].matchedResponsesCount ?? 0) + 1;
        }
    }

    const isExpected = matchingExpectation !== undefined;

    return {
        matchingExpectation,
        isExpected,
        isAmbiguous,
    };
}

export function evaluateHttpAndConsoleEvidence({
    expectedErrors,
    pendingConsoleErrors,
    pendingRequestFailures = [],
    appOrigin,
}: {
    expectedErrors: RegisteredExpectedHttpError[];
    pendingRequestFailures?: Array<Pick<PendingRequestFailure, 'url' | 'resourceType' | 'error'>>;
    pendingConsoleErrors: Array<{
        url: string;
        location: { url: string; lineNumber?: number; columnNumber?: number };
        text: string;
        meta: { origin: string; classification: string; shouldFail: boolean };
    }>;
    appOrigin: string;
}): { failingEvidence: Evidence[]; allowedEvidence: Evidence[] } {
    const failingEvidence: Evidence[] = [];
    const allowedEvidence: Evidence[] = [];

    // Fail-closed enforcement for expectedErrors registration:
    // Every registered expectation MUST have matched exactly one response (matchedResponse === true and matchedResponsesCount === 1)
    for (const exp of expectedErrors) {
        const expLabel = JSON.stringify({
            url: typeof exp.url === 'string' ? exp.url : exp.url.source,
            status: exp.status,
            method: exp.method,
        });

        if (!exp.matchedResponse || (exp.matchedResponsesCount ?? 0) === 0) {
            failingEvidence.push({
                type: 'unmatched-expected-http-error',
                origin: appOrigin,
                classification: 'expected-scope-zero-match',
                expectation: expLabel,
                message: `Expected HTTP error was registered but never matched any response: ${expLabel}`,
            });
        } else if ((exp.matchedResponsesCount ?? 0) > 1) {
            failingEvidence.push({
                type: 'ambiguous-expected-http-error',
                origin: appOrigin,
                classification: 'expected-scope-multiple-matches',
                expectation: expLabel,
                message: `Expected HTTP error matched multiple responses (${exp.matchedResponsesCount}): ${expLabel}`,
            });
        }
    }

    // Process pending console errors against matched response details
    // Only expectations that actually matched a single response can correlate console errors,
    // using the exact response details (URL, status, method) recorded at match time.
    const matchedExpectations = expectedErrors.filter(
        (
            exp,
        ): exp is typeof exp & {
            matchedResponseDetails: MatchedResponseDetails;
        } =>
            Boolean(
                exp.matchedResponse &&
                exp.matchedResponsesCount === 1 &&
                exp.matchedResponseDetails,
            ),
    );

    // 1-to-1 correlation for matched console errors:
    // Track which matched expectation has already claimed a console error.
    const claimedExpectationIndexes = new Set<number>();
    const claimedExternalTileFailureIndexes = new Set<number>();
    const allowedExternalTileFailureIndexes = pendingRequestFailures
        .map((failure, idx) => ({ failure, idx }))
        .filter(({ failure }) => isAllowedExternalTileFailure(failure, appOrigin))
        .map(({ idx }) => idx);
    const externalTileConsoleMatchCount =
        allowedExternalTileFailureIndexes.length === 1
            ? pendingConsoleErrors.filter((consoleErr) =>
                  isExternalTileConsoleError(
                      consoleErr,
                      pendingRequestFailures[allowedExternalTileFailureIndexes[0]].url,
                      appOrigin,
                  ),
              ).length
            : 0;

    if (matchedExpectations.length > 0 && allowedExternalTileFailureIndexes.length > 0) {
        failingEvidence.push({
            type: 'ambiguous-console-correlation',
            origin: appOrigin,
            classification: 'ambiguous-expected-and-external-tile-console-correlation',
            message:
                'Expected HTTP console evidence and eligible OpenStreetMap tile console evidence cannot be allowed together',
            matchedExpectationCount: matchedExpectations.length,
            eligibleExternalTileFailureCount: allowedExternalTileFailureIndexes.length,
        });
    }

    if (allowedExternalTileFailureIndexes.length > 1) {
        failingEvidence.push({
            type: 'requestfailed',
            origin: appOrigin,
            classification: 'ambiguous-external-tile-request-failure',
            message: `Multiple eligible OpenStreetMap tile request failures were observed (${allowedExternalTileFailureIndexes.length})`,
        });
    } else if (
        allowedExternalTileFailureIndexes.length === 1 &&
        externalTileConsoleMatchCount !== 1
    ) {
        failingEvidence.push({
            type: 'console.error',
            origin: appOrigin,
            classification: 'external-tile-console-correlation',
            message: `Expected exactly one console error for the eligible OpenStreetMap tile failure, observed ${externalTileConsoleMatchCount}`,
        });
    }

    for (const consoleErr of pendingConsoleErrors) {
        const matchingCandidateIndexes: number[] = [];

        matchedExpectations.forEach((exp, idx) => {
            const details = exp.matchedResponseDetails;

            // Status must match as an exact token/phrase (not substring or incidental number)
            const statusMatches = matchConsoleStatus(consoleErr.text, details.status);

            // Exact correlation requires URL from matched response details
            const urlMatches = matchConsoleUrl(
                details.url,
                consoleErr.url,
                consoleErr.text,
                consoleErr.location.url,
            );

            // Method must match as an exact token (or narrow browser-default grammar)
            const methodMatches = matchConsoleMethod(consoleErr.text, details.method);

            if (statusMatches && urlMatches && methodMatches) {
                matchingCandidateIndexes.push(idx);
            }
        });

        const matchingExternalTileFailureIndexes = allowedExternalTileFailureIndexes.filter((idx) =>
            isExternalTileConsoleError(consoleErr, pendingRequestFailures[idx].url, appOrigin),
        );

        if (
            matchingCandidateIndexes.length === 1 &&
            matchingExternalTileFailureIndexes.length === 0
        ) {
            const matchedIdx = matchingCandidateIndexes[0];
            if (!claimedExpectationIndexes.has(matchedIdx)) {
                // First correlated console error for this matched expectation -> allowed
                claimedExpectationIndexes.add(matchedIdx);
                allowedEvidence.push({
                    type: 'console.error',
                    url: consoleErr.url,
                    origin: consoleErr.meta.origin,
                    classification: consoleErr.meta.classification,
                    text: consoleErr.text,
                    locationUrl: consoleErr.location.url || null,
                    lineNumber: consoleErr.location.lineNumber,
                    columnNumber: consoleErr.location.columnNumber,
                    expected: true,
                    matchedResponseDetails: matchedExpectations[matchedIdx].matchedResponseDetails,
                });
            } else {
                // Extra correlated console error for an already-claimed expectation -> fail closed!
                failingEvidence.push({
                    type: 'console.error',
                    url: consoleErr.url,
                    origin: consoleErr.meta.origin,
                    classification: 'extra-expected-console-error',
                    text: consoleErr.text,
                    locationUrl: consoleErr.location.url || null,
                    lineNumber: consoleErr.location.lineNumber,
                    columnNumber: consoleErr.location.columnNumber,
                });
            }
        } else {
            if (
                matchingCandidateIndexes.length === 0 &&
                matchingExternalTileFailureIndexes.length === 1 &&
                externalTileConsoleMatchCount === 1
            ) {
                const failureIdx = matchingExternalTileFailureIndexes[0];
                if (!claimedExternalTileFailureIndexes.has(failureIdx)) {
                    claimedExternalTileFailureIndexes.add(failureIdx);
                    allowedEvidence.push({
                        type: 'console.error',
                        url: consoleErr.url,
                        origin: consoleErr.meta.origin,
                        classification: consoleErr.meta.classification,
                        text: consoleErr.text,
                        locationUrl: consoleErr.location.url || null,
                        lineNumber: consoleErr.location.lineNumber,
                        columnNumber: consoleErr.location.columnNumber,
                        expected: true,
                        matchedRequestFailure: pendingRequestFailures[failureIdx].url,
                    });
                    continue;
                }
            }

            // Fail closed if zero or ambiguous expected/request correlation, or an extra error.
            failingEvidence.push({
                type: 'console.error',
                url: consoleErr.url,
                origin: consoleErr.meta.origin,
                classification:
                    matchingCandidateIndexes.length > 1
                        ? 'ambiguous-expected-console-error'
                        : matchingExternalTileFailureIndexes.length > 1
                          ? 'ambiguous-external-tile-console-error'
                          : matchingExternalTileFailureIndexes.length === 1
                            ? 'extra-external-tile-console-error'
                            : consoleErr.meta.classification,
                text: consoleErr.text,
                locationUrl: consoleErr.location.url || null,
                lineNumber: consoleErr.location.lineNumber,
                columnNumber: consoleErr.location.columnNumber,
            });
        }
    }

    return { failingEvidence, allowedEvidence };
}

function normalizePath(path: string): string {
    return path.replace(/\/+$/, '') || '/';
}

export function matchExpectedHttpUrl(
    expectedUrl: string | RegExp,
    actualUrl: string,
    appOrigin: string,
): boolean {
    try {
        const actualParsed = new URL(actualUrl);

        if (expectedUrl instanceof RegExp) {
            // Strip stateful global/sticky flags to prevent lastIndex state mutations
            const cleanRegex = new RegExp(
                expectedUrl.source,
                expectedUrl.flags.replace(/[gy]/g, ''),
            );

            const sourceWithoutAnchor = expectedUrl.source.replace(/^\\?\^/, '');
            const hasExplicitScheme = /^[a-z]+:(?:\\?\/){2}/i.test(sourceWithoutAnchor);

            if (actualParsed.origin !== appOrigin) {
                // For off-origin responses:
                // Regex MUST explicitly have an off-origin scheme and match full actualUrl.
                // It must NOT match same-origin relative path.
                if (!hasExplicitScheme) {
                    return false;
                }
                const matchesFullUrl = cleanRegex.test(actualUrl);
                const matchesRelative =
                    cleanRegex.test(actualParsed.pathname) ||
                    cleanRegex.test(actualParsed.pathname + actualParsed.search);

                return matchesFullUrl && !matchesRelative;
            }

            // For same-origin (appOrigin):
            // If regex has explicit scheme, it must match full actualUrl
            if (hasExplicitScheme) {
                return cleanRegex.test(actualUrl);
            }

            // For relative regex on same-origin:
            // Match must be ROOT-SCOPED against the path.
            // If actual URL has search params, regex must match pathname + search starting at root.
            // If actual URL has no search params, regex must match pathname starting at root.
            // Both must be full root matches (match at index 0 and cover whole target string).
            const targetString =
                actualParsed.search !== ''
                    ? actualParsed.pathname + actualParsed.search
                    : actualParsed.pathname;

            const execResult = cleanRegex.exec(targetString);
            if (!execResult || execResult.index !== 0) {
                return false;
            }

            return execResult[0] === targetString;
        }

        if (expectedUrl.startsWith('http://') || expectedUrl.startsWith('https://')) {
            const expectedParsed = new URL(expectedUrl);
            return (
                actualParsed.origin === expectedParsed.origin &&
                normalizePath(actualParsed.pathname) === normalizePath(expectedParsed.pathname) &&
                actualParsed.search === expectedParsed.search
            );
        }

        // Relative expected URL: must be a root-relative path (starting with /) or valid relative URL
        // It must match appOrigin and exact normalized pathname + query
        const expectedParsed = new URL(expectedUrl, appOrigin);
        return (
            actualParsed.origin === appOrigin &&
            normalizePath(actualParsed.pathname) === normalizePath(expectedParsed.pathname) &&
            actualParsed.search === expectedParsed.search
        );
    } catch {
        return false;
    }
}

export function matchConsoleStatus(consoleText: string, expectedStatus: number): boolean {
    const statusStr = String(expectedStatus);

    // 1. Check for conflicting status codes in error phrases (e.g. status 404 vs status 500)
    const statusPhraseRegex =
        /\b(?:status(?:[\s_-]*(?:code|of))?|http(?:[\s_-]*status)?|http\/\d(?:\.\d)?|error)\s*[:=]?\s*(4\d\d|5\d\d)\b/gi;
    const foundPhraseStatuses = new Set<string>();
    let statusPhraseMatch: RegExpExecArray | null = null;
    while ((statusPhraseMatch = statusPhraseRegex.exec(consoleText)) !== null) {
        if (statusPhraseMatch[1]) {
            foundPhraseStatuses.add(statusPhraseMatch[1]);
        }
    }
    if (
        foundPhraseStatuses.size > 0 &&
        (!foundPhraseStatuses.has(statusStr) || foundPhraseStatuses.size > 1)
    ) {
        return false;
    }

    // 2. Reject incidental numeric units like "500 ms", "500ms", "500 px", "500 bytes", "500 kb" etc.
    const incidentalUnitPattern = new RegExp(
        `\\b${statusStr}\\s*(?:ms|s|sec|seconds?|px|em|rem|bytes?|b|kb|mb|gb|items?|rows?|records?|lines?)\\b`,
        'i',
    );
    if (incidentalUnitPattern.test(consoleText) && foundPhraseStatuses.size === 0) {
        return false;
    }

    // 3. Browser-default error line: "Failed to load resource: the server responded with a status of 500 (Internal Server Error)"
    const browserDefaultPattern = new RegExp(
        `Failed to load resource:\\s*(?:the server responded with a status of|status of)\\s*${statusStr}\\b`,
        'i',
    );
    if (browserDefaultPattern.test(consoleText)) {
        return true;
    }

    // 4. Explicit status phrases: "status of 500", "status code 500", "status 500", "HTTP 500", "HTTP/1.1 500", "status: 500", "status=500"
    const phrasePattern = new RegExp(
        `\\b(?:status(?:[\\s_-]*(?:code|of))?|http(?:[\\s_-]*status)?|http\\/\\d(?:\\.\\d)?)\\s*[:=]?\\s*${statusStr}\\b`,
        'i',
    );
    if (phrasePattern.test(consoleText)) {
        return true;
    }

    // 5. Browser error status with status text or parentheses: "500 (Internal Server Error)", "[status: 500]"
    const statusParenPattern = new RegExp(`\\b${statusStr}\\s*\\([^)]*\\)`);
    if (statusParenPattern.test(consoleText)) {
        return true;
    }

    const bracketPattern = new RegExp(
        `\\[[^\\]]*status[\\s_-]*[:=]?\\s*${statusStr}[^\\]]*\\]`,
        'i',
    );
    if (bracketPattern.test(consoleText)) {
        return true;
    }

    // 6. Explicit Error code: "Error: 500 (Internal Server Error)" or "error 500:"
    const errorPrefixPattern = new RegExp(`\\berror\\s*[:=]?\\s*${statusStr}\\b`, 'i');
    if (errorPrefixPattern.test(consoleText)) {
        return true;
    }

    return false;
}

export function matchConsoleMethod(consoleText: string, expectedMethod: string): boolean {
    if (!expectedMethod || typeof expectedMethod !== 'string') {
        return false;
    }
    const targetMethod = expectedMethod.trim().toUpperCase();
    if (!targetMethod) {
        return false;
    }

    // 1. Extract exact HTTP method tokens present in the console message
    const exactMethodTokens = (
        consoleText.match(/\b(GET|POST|PUT|PATCH|DELETE|OPTIONS|HEAD)\b/gi) || []
    ).map((token) => token.toUpperCase());

    // 2. Reject pseudo-method words like "DELETED", "POSTED", "GETTING" if exact token is absent
    const pseudoMethodTokens =
        consoleText.match(/\b(GET|POST|PUT|PATCH|DELETE|OPTIONS|HEAD)[a-zA-Z]+\b/gi) || [];
    if (pseudoMethodTokens.length > 0 && !exactMethodTokens.includes(targetMethod)) {
        return false;
    }

    // 3. If exact HTTP method tokens are present:
    // Every method token must match targetMethod (mixed methods like GET + DELETE fail closed)
    if (exactMethodTokens.length > 0) {
        const allMatch = exactMethodTokens.every((token) => token === targetMethod);
        return allMatch;
    }

    // 4. If method is completely absent from the message text:
    // Only allow for genuine narrow browser-default error grammar:
    // "Failed to load resource: the server responded with a status of ..."
    const browserDefaultPattern =
        /Failed to load resource:\s*(?:the server responded with a status of|status of)\s*\d{3}\b/i;
    if (browserDefaultPattern.test(consoleText)) {
        return true;
    }

    // Any arbitrary custom text without method token fails closed
    return false;
}

export function matchConsoleUrl(
    targetUrl: string,
    consoleUrl: string,
    consoleText: string,
    locationUrl: string,
): boolean {
    const targetParsed = (() => {
        try {
            return new URL(targetUrl);
        } catch {
            return null;
        }
    })();

    const checkCandidate = (cand: string): boolean => {
        if (!cand) return false;
        try {
            const candParsed = new URL(cand);
            return (
                candParsed.origin === targetParsed?.origin &&
                normalizePath(candParsed.pathname) ===
                    normalizePath(targetParsed?.pathname ?? '') &&
                candParsed.search === targetParsed?.search
            );
        } catch {
            return false;
        }
    };

    if (consoleUrl && checkCandidate(consoleUrl)) {
        return true;
    }
    if (locationUrl && checkCandidate(locationUrl)) {
        return true;
    }

    // In console text, extract full URLs via regex and check exact match against targetUrl
    const urlMatches = consoleText.match(/https?:\/\/[^\s"'`<>\]\)]+/g) || [];
    for (const urlInText of urlMatches) {
        if (checkCandidate(urlInText)) {
            return true;
        }
    }

    // Check if relative path was printed in text
    if (targetParsed) {
        const normalizedTarget = normalizePath(targetParsed.pathname);
        const pathMatches = consoleText.match(/(?:^|\s|\[|\()(\/[^\s"'`<>\]\)]*)/g) || [];
        for (const rawPath of pathMatches) {
            const cleanedPath = rawPath.replace(/^[\[\(\s]+/, '');
            const qIdx = cleanedPath.indexOf('?');
            const pName = qIdx !== -1 ? cleanedPath.slice(0, qIdx) : cleanedPath;
            const pQuery = qIdx !== -1 ? cleanedPath.slice(qIdx) : '';
            if (normalizePath(pName) === normalizedTarget && pQuery === targetParsed.search) {
                return true;
            }
        }
    }

    return false;
}

function evidenceEvent(event: LifecycleEvent): AbortCorrelation {
    return {
        type: event.type,
        pageUrl: event.pageUrl,
        sequence: event.sequence,
        ...('visitUrl' in event ? { visitUrl: event.visitUrl ?? null } : {}),
        ...('visitMethod' in event ? { visitMethod: event.visitMethod ?? null } : {}),
        ...(event.type === 'inertia:finish'
            ? {
                  visitCancelled: event.visitCancelled,
                  visitInterrupted: event.visitInterrupted,
                  visitCompleted: event.visitCompleted,
              }
            : {}),
    };
}

function isDocumentNavigationRequest(request: Request) {
    return (
        request.resourceType() === 'document' &&
        request.isNavigationRequest() &&
        request.frame() === request.frame().page().mainFrame()
    );
}

function frameUrlOf(request: Request) {
    try {
        return request.frame().url();
    } catch {
        return null;
    }
}

function originOf(url: string) {
    try {
        return new URL(url).origin;
    } catch {
        return 'unknown';
    }
}

function isAllowedExternalTileFailure(
    failure: Pick<PendingRequestFailure, 'url' | 'resourceType' | 'error'>,
    appOrigin: string,
): boolean {
    return (
        originOf(failure.url) !== appOrigin &&
        isOpenStreetMapTileUrl(failure.url) &&
        failure.resourceType === 'image' &&
        failure.error === 'net::ERR_FAILED'
    );
}

function isExternalTileConsoleError(
    consoleErr: PendingConsoleError,
    tileUrl: string,
    appOrigin: string,
): boolean {
    if (originOf(tileUrl) === appOrigin || !isOpenStreetMapTileUrl(tileUrl)) return false;

    return (
        [consoleErr.url, consoleErr.location.url].some((url) => url === tileUrl) ||
        consoleTextHasExactUrl(consoleErr.text, tileUrl)
    );
}

function isOpenStreetMapTileUrl(url: string): boolean {
    try {
        const parsed = new URL(url);
        const isOpenStreetMapHost =
            parsed.hostname === 'tile.openstreetmap.org' ||
            parsed.hostname.endsWith('.tile.openstreetmap.org');

        return (
            parsed.protocol === 'https:' && isOpenStreetMapHost && parsed.pathname.endsWith('.png')
        );
    } catch {
        return false;
    }
}

function consoleTextHasExactUrl(text: string, targetUrl: string): boolean {
    let searchStart = 0;

    while (searchStart < text.length) {
        const matchIndex = text.indexOf(targetUrl, searchStart);
        if (matchIndex === -1) return false;

        const before = text[matchIndex - 1];
        const after = text[matchIndex + targetUrl.length];
        const beforeIsUrlCharacter = isUrlBoundaryCharacter(before);
        const afterIsUrlCharacter = isUrlBoundaryCharacter(after);

        if (!beforeIsUrlCharacter && !afterIsUrlCharacter) return true;
        searchStart = matchIndex + targetUrl.length;
    }

    return false;
}

function isUrlBoundaryCharacter(character: string | undefined): boolean {
    return (
        character !== undefined &&
        "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789._~:/?#[\\]@!$&'()*+,;=%-".includes(
            character,
        )
    );
}

function safePageUrl(page: { url(): string; isClosed(): boolean }, fallback: string) {
    return page.isClosed() ? fallback : page.url();
}

export { expect };
