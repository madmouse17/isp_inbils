import { expect, test as base } from '@playwright/test';
import type { Request, TestInfo } from '@playwright/test';

type Evidence = Record<string, string | number | boolean | null | Evidence>;

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

export const test = base.extend({
    page: async ({ page, baseURL }, use, testInfo) => {
        const appOrigin = new URL(baseURL ?? page.url()).origin;
        const failingEvidence: Evidence[] = [];
        const allowedEvidence: Evidence[] = [];
        const requestFrameUrls = new WeakMap<Request, string | null>();
        const requestStartTimes = new WeakMap<Request, number>();
        const requestLifecycleSequences = new WeakMap<Request, number>();
        const pendingRequestFailures: PendingRequestFailure[] = [];
        const lifecycleEvents: LifecycleEvent[] = [];
        const activePrefetches = new Set<Request>();
        const prefetchWaiters = new Set<() => void>();
        let lastPageUrl = page.url();
        let lifecycleSequence = 0;
        let activeTeardownCorrelation: AbortCorrelation | null = null;
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
            const meta = classify(url);

            addEvidence({
                type: 'console.error',
                url,
                origin: meta.origin,
                classification: meta.classification,
                text: message.text(),
                locationUrl: location.url || null,
                lineNumber: location.lineNumber,
                columnNumber: location.columnNumber,
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
                lifecycleSequenceAtStart:
                    requestLifecycleSequences.get(request) ?? lifecycleSequence,
                lifecycleSequenceAtFailure: lifecycleSequence,
                teardownCorrelation: activeTeardownCorrelation,
            });
        });
        page.on('response', (response) => {
            if (response.status() < 400) return;

            const request = response.request();
            const resourceType = request.resourceType();
            const meta = classify(response.url(), resourceType);
            const evidence = {
                type: 'http-error',
                url: response.url(),
                origin: meta.origin,
                classification: meta.classification,
                resourceType,
                method: request.method(),
                status: response.status(),
                statusText: response.statusText(),
            };

            addEvidence(evidence, meta.shouldFail || !externalNonCriticalTypes.has(resourceType));
        });

        await use(page);
        await waitForPrefetches(activePrefetches, prefetchWaiters);

        if (!page.isClosed()) {
            activeTeardownCorrelation = recordLifecycle({
                type: 'fixture-teardown',
                pageUrl: lastPageUrl,
            });
            await page.close({ runBeforeUnload: false }).catch(() => {});
        }

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

        await attachEvidence(testInfo, 'browser-failures.json', failingEvidence);
        await attachEvidence(testInfo, 'browser-allowed-failures.json', allowedEvidence);

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
    for (const resolve of waiters) {
        resolve();
    }

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

    return candidates[0] ? evidenceEvent(candidates[0]) : null;
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

function safePageUrl(page: { url(): string; isClosed(): boolean }, fallback: string) {
    return page.isClosed() ? fallback : page.url();
}

export { expect };
