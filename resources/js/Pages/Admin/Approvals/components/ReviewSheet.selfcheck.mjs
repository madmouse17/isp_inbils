/**
 * Behavioral & contract test for ReviewSheet in a real headless browser.
 * Validates: CTA click, in-flight state, failure fail-closed, success closing/synchronization.
 * Run: node --test resources/js/Pages/Admin/Approvals/components/ReviewSheet.selfcheck.mjs
 */
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import { chromium } from '@playwright/test';
import esbuild from 'esbuild';

const reviewSheetPath = new URL('./ReviewSheet.tsx', import.meta.url);
const indexPath = new URL('../Index.tsx', import.meta.url);
const mobileCardPath = new URL('./MobileItemCard.tsx', import.meta.url);
const utilsPath = new URL('./utils.ts', import.meta.url);

function resolvePath(base) {
    const exts = ['', '.tsx', '.ts', '.jsx', '.js', '/index.tsx', '/index.ts'];
    for (const ext of exts) {
        const p = base + ext;
        if (fs.existsSync(p) && fs.statSync(p).isFile()) return p;
    }
    return null;
}

test('ReviewSheet.tsx and Index.tsx static contract and interface validation', async () => {
    const reviewSheetSource = await readFile(reviewSheetPath, 'utf8');
    const indexSource = await readFile(indexPath, 'utf8');
    const mobileCardSource = await readFile(mobileCardPath, 'utf8');
    const utilsSource = await readFile(utilsPath, 'utf8');

    // 1. Export contracts
    assert.match(reviewSheetSource, /export function ReviewSheet/);
    assert.match(reviewSheetSource, /export interface RefreshQueueResult/);

    // 2. Action permission gating
    assert.match(reviewSheetSource, /item\.actions\.approve/);
    assert.match(reviewSheetSource, /item\.actions\.reject/);

    // 3. Stale error handling and explicit reload lifecycle state
    assert.match(reviewSheetSource, /const \[isStale, setIsStale\] = useState\(false\);/);
    assert.match(reviewSheetSource, /const \[isReloading, setIsReloading\] = useState\(false\);/);
    assert.match(reviewSheetSource, /setIsReloading\(true\)/);

    // 4. In-flight and stale fail-closed button contracts
    assert.match(reviewSheetSource, /disabled=\{submitting \|\| isStale \|\| isReloading\}/);
    assert.match(
        reviewSheetSource,
        /disabled=\{\s*submitting \|\| isStale \|\| isReloading \|\| !rejectReason\.trim\(\)\s*\}/,
    );
    assert.match(reviewSheetSource, /loading=\{isReloading\}/);
    assert.match(reviewSheetSource, /Memuat ulang\.\.\./);
    assert.match(reviewSheetSource, /Muat ulang antrean/);

    // 5. Index contract: provides asynchronous queue reload and unselects closed item
    assert.match(indexSource, /handleRefreshQueue/);
    assert.match(indexSource, /router\.reload/);
    assert.match(indexSource, /setSelectedItem\(null\)/);
    assert.match(indexSource, /onRefreshQueue=\{handleRefreshQueue\}/);

    // 6. Remediation contract: idempotent terminal settlement handling
    assert.match(indexSource, /export (?:const|function) reloadQueue/);
    assert.match(indexSource, /onFinish/);
    assert.match(indexSource, /onCancel/);

    // 7. Composite ID and row identity contracts
    assert.match(utilsSource, /export function getItemCompositeId/);
    assert.match(indexSource, /getItemCompositeId\(selectedItem\)/);
    assert.match(indexSource, /getRowId=\{getItemCompositeId\}/);
    assert.match(indexSource, /rowKey="composite_id"/);
    assert.match(indexSource, /key=\{getItemCompositeId\(item\)\}/);
    assert.match(reviewSheetSource, /key=\{getItemCompositeId\(item\)\}/);

    // 8. Honest affordance contracts for view capability
    assert.match(indexSource, /row\.actions\?\.view && row\.detail_url/);
    assert.match(mobileCardSource, /item\.actions\?\.view && item\.detail_url/);
    assert.match(reviewSheetSource, /item\.actions\.view && item\.detail_url/);
});

test('ReviewSheet behavioral browser lifecycle in Chromium: CTA click, in-flight, failure, and synchronization', async () => {
    // 1. Bundle ReviewSheet and React harness in-memory with esbuild
    const bundle = await esbuild.build({
        stdin: {
            contents: `
                import React, { useState } from 'react';
                import ReactDOM from 'react-dom/client';
                import { ReviewSheet } from './resources/js/Pages/Admin/Approvals/components/ReviewSheet';

                window.route = (name, id) => '/admin/spk/' + id + '/approve';

                window.mountHarness = (initialItem) => {
                    function Harness() {
                        const [open, setOpen] = useState(true);
                        const [item, setItem] = useState(initialItem);

                        window.__setItem = setItem;
                        window.__setOpen = setOpen;

                        return React.createElement(ReviewSheet, {
                            open,
                            onOpenChange: (val) => {
                                setOpen(val);
                                if (!val) setItem(null);
                                window.__lastOpenChange = val;
                            },
                            item,
                            onRefreshQueue: () => window.__onRefreshQueue?.(),
                        });
                    }

                    const root = ReactDOM.createRoot(document.getElementById('root'));
                    window.__harnessRoot = root;
                    root.render(React.createElement(Harness));
                };
            `,
            resolveDir: process.cwd(),
            loader: 'tsx',
        },
        bundle: true,
        format: 'iife',
        write: false,
        define: { 'process.env.NODE_ENV': '"test"' },
        plugins: [
            {
                name: 'mock-inertia',
                setup(build) {
                    build.onResolve({ filter: /^@inertiajs\/react$/ }, () => ({
                        path: 'inertia-mock',
                        namespace: 'mock',
                    }));
                    build.onLoad({ filter: /.*/, namespace: 'mock' }, () => ({
                        contents:
                            'export const router = { post: (url, data, opts) => window.__routerPost?.(url, data, opts) }; export const Link = (props) => props.children; export const usePage = () => ({ props: { company: { settings: { date_format: "d/m/Y" } } } });',
                        loader: 'js',
                    }));
                },
            },
        ],
    });

    const jsCode = bundle.outputFiles[0].text;
    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage();

    try {
        await page.setContent(
            `<!DOCTYPE html><html><body><div id="root"></div><script>${jsCode}</script></body></html>`,
        );

        const testItem = {
            id: 101,
            code: 'SPK-TEST-001',
            title: 'Pemasangan Drop Cable Baru',
            type: 'installation',
            priority: 'high',
            status: 'waiting_review',
            age_seconds: 3600,
            submitted_at: '2026-09-08T10:00:00Z',
            scheduled_date: '2026-09-09',
            detail_url: '/admin/spk/101',
            requester: { id: 2, name: 'Budi Santoso' },
            summary: { count: 2, quantity: 5, label: 'Kabel Drop & ONT' },
            actions: { approve: true, reject: true },
            evidence: [],
            materials: [],
            notes: [],
        };

        await page.evaluate((it) => {
            window.__routerPost = (url, data, opts) => {
                opts.onStart?.();
                opts.onError?.({
                    error: 'Status SPK telah berubah dari sesi lain (stale conflict).',
                });
                opts.onFinish?.();
            };
            window.mountHarness(it);
        }, testItem);

        const approveBtn = page.locator('button:has-text("Setujui")');
        const tolakBtn = page.locator('button:has-text("Tolak")');

        // Initial state: actions enabled
        assert.equal(await approveBtn.isDisabled(), false, 'Approve initially enabled');
        assert.equal(await tolakBtn.isDisabled(), false, 'Tolak initially enabled');

        // Trigger submission error -> conflict stale state
        await approveBtn.click();

        // Error alert and CTA button appear
        const errorAlert = page.locator('[role="alert"]').first();
        await errorAlert.waitFor();
        const reloadBtn = page.locator('button:has-text("Muat ulang antrean")');
        await reloadBtn.waitFor();

        // Fail-closed verification: actions must be disabled
        assert.equal(await approveBtn.isDisabled(), true, 'Approve disabled on stale');
        assert.equal(await tolakBtn.isDisabled(), true, 'Tolak disabled on stale');

        // In-flight verification
        await page.evaluate(() => {
            window.__onRefreshQueue = () => {
                return new Promise((resolve) => {
                    window.__resolveReloadPromise = resolve;
                });
            };
        });

        await reloadBtn.click();

        const inFlightBtn = page.locator('button:has-text("Memuat ulang...")');
        await inFlightBtn.waitFor();
        assert.equal(await inFlightBtn.isDisabled(), true, 'Reload CTA disabled in-flight');
        assert.equal(await approveBtn.isDisabled(), true, 'Approve remains disabled in-flight');
        assert.equal(await tolakBtn.isDisabled(), true, 'Tolak remains disabled in-flight');

        // Test failure scenario: reload fails -> stays fail-closed
        await page.evaluate(() => {
            window.__resolveReloadPromise({
                success: false,
                errorMessage: 'Koneksi gagal saat memuat ulang antrean.',
            });
        });

        await inFlightBtn.waitFor({ state: 'detached' });
        await page
            .locator('[role="alert"]:has-text("Koneksi gagal saat memuat ulang antrean.")')
            .first()
            .waitFor();
        assert.equal(
            await approveBtn.isDisabled(),
            true,
            'Approve stays disabled on reload failure',
        );
        assert.equal(await tolakBtn.isDisabled(), true, 'Tolak stays disabled on reload failure');
        assert.equal(await reloadBtn.isDisabled(), false, 'Reload CTA re-enabled to allow retry');

        // Test success scenario 1: item completed/gone from queue -> sheet closes
        await reloadBtn.click();
        await inFlightBtn.waitFor();

        await page.evaluate(() => {
            window.__resolveReloadPromise({ success: true, freshItem: null });
        });

        await page.waitForFunction(() => window.__lastOpenChange === false);

        // Test success scenario 2: item synchronized with fresh actionable state
        const freshItem = {
            ...testItem,
            title: 'Pemasangan Drop Cable Baru (Tersinkronisasi)',
        };

        await page.evaluate((fresh) => {
            window.__setItem(fresh);
            window.__setOpen(true);
            window.__lastOpenChange = true;
            window.__routerPost = (url, data, opts) => {
                opts.onStart?.();
                opts.onError?.({ error: 'Konflik kedua.' });
                opts.onFinish?.();
            };
        }, freshItem);

        await approveBtn.waitFor();
        await approveBtn.click();
        await reloadBtn.waitFor();

        await reloadBtn.click();
        await inFlightBtn.waitFor();

        await page.evaluate((fresh) => {
            window.__setItem(fresh);
            window.__resolveReloadPromise({ success: true, freshItem: fresh });
        }, freshItem);

        await inFlightBtn.waitFor({ state: 'detached' });
        assert.equal(
            await approveBtn.isDisabled(),
            false,
            'Approve re-enabled after successful reload with fresh actionable item',
        );
        const freshTitle = await page
            .locator('text=Pemasangan Drop Cable Baru (Tersinkronisasi)')
            .textContent();
        assert.ok(
            freshTitle.includes('Tersinkronisasi'),
            'Fresh synchronized item title is rendered in sheet',
        );
    } finally {
        await browser.close();
    }
});

test('Index.tsx reloadQueue callback integration and terminal settlement: finish-only, cancel, error, and idempotent recovery', async () => {
    const bundle = await esbuild.build({
        stdin: {
            contents: `
                import React, { useState } from 'react';
                import ReactDOM from 'react-dom/client';
                import { ReviewSheet } from './resources/js/Pages/Admin/Approvals/components/ReviewSheet';
                import { reloadQueue } from './resources/js/Pages/Admin/Approvals/Index';

                window.__ReviewSheet = ReviewSheet;
                window.__reloadQueue = reloadQueue;
                window.route = (name, id) => '/admin/spk/' + id + '/approve';

                window.mountIntegratedHarness = (initialItem) => {
                    function IntegratedHarness() {
                        const [open, setOpen] = useState(true);
                        const [item, setItem] = useState(initialItem);

                        window.__setItem = setItem;
                        window.__setOpen = setOpen;

                        return React.createElement(ReviewSheet, {
                            open,
                            onOpenChange: (val) => {
                                setOpen(val);
                                if (!val) setItem(null);
                                window.__lastOpenChange = val;
                            },
                            item,
                            onRefreshQueue: () => reloadQueue({
                                router: window.__integratedRouter,
                                selectedItem: item,
                                setSelectedItem: setItem,
                                setReviewOpen: setOpen,
                            }),
                        });
                    }

                    const root = ReactDOM.createRoot(document.getElementById('root'));
                    window.__harnessRoot = root;
                    root.render(React.createElement(IntegratedHarness));
                };
            `,
            resolveDir: process.cwd(),
            loader: 'tsx',
        },
        bundle: true,
        format: 'iife',
        write: false,
        define: { 'process.env.NODE_ENV': '"test"' },
        plugins: [
            {
                name: 'resolve-aliases',
                setup(build) {
                    build.onResolve({ filter: /^@\// }, (args) => {
                        const target = path.join(process.cwd(), 'resources/js', args.path.slice(2));
                        const resolved = resolvePath(target);
                        if (resolved) return { path: resolved };
                        return null;
                    });
                    build.onResolve({ filter: /^@inertiajs\/react$/ }, () => ({
                        path: 'inertia-mock',
                        namespace: 'mock',
                    }));
                    build.onLoad({ filter: /.*/, namespace: 'mock' }, () => ({
                        contents: `
                            export const router = { post: (url, data, opts) => window.__routerPost?.(url, data, opts), reload: (opts) => window.__routerReload?.(opts) };
                            export const Link = (props) => props.children;
                            export const useForm = () => ({ data: {}, setData: () => {}, post: () => {}, processing: false, errors: {}, reset: () => {} });
                            export const usePage = () => ({ props: { auth: { user: { id: 1, name: 'Test' } }, ziggy: {} } });
                            export const Head = () => null;
                        `,
                        loader: 'js',
                    }));
                    build.onResolve({ filter: /^ziggy-js$/ }, () => ({
                        path: 'ziggy-mock',
                        namespace: 'mock',
                    }));
                    build.onLoad({ filter: /.*/, namespace: 'mock' }, () => ({
                        contents: 'export default function route() { return "/test"; }',
                        loader: 'js',
                    }));
                },
            },
        ],
    });

    const jsCode = bundle.outputFiles[0].text;
    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage();

    try {
        await page.setContent(
            `<!DOCTYPE html><html><body><div id="root"></div><script>${jsCode}</script></body></html>`,
        );

        const sampleItem = {
            id: 202,
            code: 'SPK-INT-002',
            title: 'Instalasi Bounded Reload Test',
            type: 'installation',
            priority: 'medium',
            status: 'waiting_review',
            age_seconds: 1200,
            submitted_at: '2026-09-08T11:00:00Z',
            scheduled_date: '2026-09-09',
            detail_url: '/admin/spk/202',
            requester: { id: 3, name: 'Siti Rahma' },
            summary: { count: 1, quantity: 1, label: 'Modem ONT' },
            actions: { approve: true, reject: true },
            evidence: [],
            materials: [],
            notes: [],
        };

        // 1. Behavioral check: finish-only must settle in <100ms without hang (Reviewer reproducer)
        const finishOnlyResult = await page.evaluate(async (it) => {
            const fakeRouter = {
                reload: (opts) => {
                    // Simulates transport failure / Inertia finish without error
                    opts.onFinish?.();
                },
            };
            const reloadPromise = window.__reloadQueue({
                router: fakeRouter,
                selectedItem: it,
                setSelectedItem: () => {},
                setReviewOpen: () => {},
            });
            return await Promise.race([
                reloadPromise,
                new Promise((_, reject) =>
                    setTimeout(() => reject(new Error('TIMEOUT_UNSETTLED')), 100),
                ),
            ]);
        }, sampleItem);

        assert.equal(
            finishOnlyResult.success,
            false,
            'finish-only returns success:false without hanging',
        );
        assert.ok(finishOnlyResult.errorMessage, 'finish-only provides non-empty error message');

        // 2. Behavioral check: cancel must settle cleanly
        const cancelResult = await page.evaluate(async (it) => {
            const fakeRouter = {
                reload: (opts) => {
                    opts.onCancel?.();
                    opts.onFinish?.();
                },
            };
            return await window.__reloadQueue({
                router: fakeRouter,
                selectedItem: it,
                setSelectedItem: () => {},
                setReviewOpen: () => {},
            });
        }, sampleItem);

        assert.equal(cancelResult.success, false, 'cancel returns success:false');

        // 3. Behavioral check: onError must settle cleanly
        const errorResult = await page.evaluate(async (it) => {
            const fakeRouter = {
                reload: (opts) => {
                    opts.onError?.({ error: 'Validasi server gagal' });
                    opts.onFinish?.();
                },
            };
            return await window.__reloadQueue({
                router: fakeRouter,
                selectedItem: it,
                setSelectedItem: () => {},
                setReviewOpen: () => {},
            });
        }, sampleItem);

        assert.equal(errorResult.success, false, 'onError returns success:false');

        // 4. Behavioral check: synchronous exception must settle cleanly
        const exceptionResult = await page.evaluate(async (it) => {
            const fakeRouter = {
                reload: () => {
                    throw new Error('Sync reload crash');
                },
            };
            return await window.__reloadQueue({
                router: fakeRouter,
                selectedItem: it,
                setSelectedItem: () => {},
                setReviewOpen: () => {},
            });
        }, sampleItem);

        assert.equal(exceptionResult.success, false, 'sync exception returns success:false');

        // 5. Behavioral check: onSuccess idempotency (onFinish must not overwrite onSuccess)
        const successResult = await page.evaluate(async (it) => {
            let updated = null;
            const fresh = { ...it, title: 'Instalasi Bounded (Fresh)' };
            const fakeRouter = {
                reload: (opts) => {
                    opts.onSuccess?.({
                        props: { items: { data: [fresh] } },
                    });
                    // Terminal visit finish must not overwrite
                    opts.onFinish?.();
                },
            };
            const res = await window.__reloadQueue({
                router: fakeRouter,
                selectedItem: it,
                setSelectedItem: (freshIt) => {
                    updated = freshIt;
                },
                setReviewOpen: () => {},
            });
            return { res, updated };
        }, sampleItem);

        assert.equal(successResult.res.success, true, 'onSuccess settles success:true');
        assert.equal(
            successResult.updated?.title,
            'Instalasi Bounded (Fresh)',
            'Fresh item synchronized',
        );

        // 6. Behavioral browser integration: ReviewSheet recovery from finish-only transport error
        await page.evaluate((it) => {
            let reloadCallCount = 0;
            window.__integratedRouter = {
                reload: (opts) => {
                    reloadCallCount++;
                    if (reloadCallCount === 1) {
                        // First attempt: transport failure (finish-only)
                        setTimeout(() => opts.onFinish?.(), 10);
                    } else {
                        // Second attempt (retry): successful reload with updated item
                        setTimeout(() => {
                            const fresh = {
                                ...it,
                                title: 'Instalasi Bounded (Tersinkronisasi Penuh)',
                            };
                            opts.onSuccess?.({
                                props: { items: { data: [fresh] } },
                            });
                            opts.onFinish?.();
                        }, 10);
                    }
                },
            };
            window.__routerPost = (url, data, opts) => {
                opts.onStart?.();
                opts.onError?.({ error: 'Konflik antrean terdeteksi.' });
                opts.onFinish?.();
            };
            window.mountIntegratedHarness(it);
        }, sampleItem);

        const approveBtn = page.locator('button:has-text("Setujui")');
        const tolakBtn = page.locator('button:has-text("Tolak")');

        // Trigger conflict error
        await approveBtn.click();
        const reloadBtn = page.locator('button:has-text("Muat ulang antrean")');
        await reloadBtn.waitFor();

        // Actions fail-closed
        assert.equal(await approveBtn.isDisabled(), true, 'Approve disabled on stale');
        assert.equal(await tolakBtn.isDisabled(), true, 'Tolak disabled on stale');

        // Click reload -> triggers finish-only path
        await reloadBtn.click();

        // Bounded settlement: "Memuat ulang..." must NOT hang; must revert to enabled reloadBtn
        await page.locator('button:has-text("Memuat ulang...")').waitFor({ state: 'detached' });
        assert.equal(
            await reloadBtn.isDisabled(),
            false,
            'Reload CTA re-enabled after finish-only error allowing retry',
        );
        assert.equal(
            await approveBtn.isDisabled(),
            true,
            'Actions remain fail-closed after finish-only error',
        );

        // Retry reload -> succeeds and synchronizes
        await reloadBtn.click();
        await page.locator('button:has-text("Memuat ulang...")').waitFor({ state: 'detached' });

        assert.equal(
            await approveBtn.isDisabled(),
            false,
            'Approve re-enabled after successful retry reload',
        );
        const titleText = await page
            .locator('text=Instalasi Bounded (Tersinkronisasi Penuh)')
            .textContent();
        assert.ok(
            titleText.includes('Tersinkronisasi Penuh'),
            'Synchronized title rendered after retry',
        );
    } finally {
        await browser.close();
    }
});

test('ReviewSheet Inventory Request domain behavior: titles, endpoints, notes, rejection validation', async () => {
    const bundle = await esbuild.build({
        stdin: {
            contents: `
                import React, { useState } from 'react';
                import ReactDOM from 'react-dom/client';
                import { ReviewSheet } from './resources/js/Pages/Admin/Approvals/components/ReviewSheet';

                window.route = (name, id) => '/admin/' + (name.includes('inventory') ? 'inventory/requests/' : 'spk/') + id + '/' + (name.includes('reject') ? 'reject' : 'approve');

                window.mountHarness = (initialItem) => {
                    function Harness() {
                        const [open, setOpen] = useState(true);
                        const [item, setItem] = useState(initialItem);

                        window.__setItem = setItem;
                        window.__setOpen = setOpen;

                        return React.createElement(ReviewSheet, {
                            open,
                            onOpenChange: (val) => {
                                setOpen(val);
                                if (!val) setItem(null);
                                window.__lastOpenChange = val;
                            },
                            item,
                            onRefreshQueue: () => window.__onRefreshQueue?.(),
                        });
                    }

                    const root = ReactDOM.createRoot(document.getElementById('root'));
                    window.__harnessRoot = root;
                    root.render(React.createElement(Harness));
                };
            `,
            resolveDir: process.cwd(),
            loader: 'tsx',
        },
        bundle: true,
        format: 'iife',
        write: false,
        define: { 'process.env.NODE_ENV': '"test"' },
        plugins: [
            {
                name: 'mock-inertia',
                setup(build) {
                    build.onResolve({ filter: /^@inertiajs\/react$/ }, () => ({
                        path: 'inertia-mock',
                        namespace: 'mock',
                    }));
                    build.onLoad({ filter: /.*/, namespace: 'mock' }, () => ({
                        contents:
                            'export const router = { post: (url, data, opts) => window.__routerPost?.(url, data, opts) }; export const Link = (props) => props.children; export const usePage = () => ({ props: { company: { settings: { date_format: "d/m/Y" } } } });',
                        loader: 'js',
                    }));
                },
            },
        ],
    });

    const jsCode = bundle.outputFiles[0].text;
    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage();

    try {
        await page.setContent(
            `<!DOCTYPE html><html><body><div id="root"></div><script>${jsCode}</script></body></html>`,
        );

        const invItem = {
            id: 505,
            domain: 'inventory_request',
            code: 'REQ-FO-2026-001',
            title: 'Permintaan Material Kabel FO Drop Core',
            type: 'inventory_request',
            priority: 'medium',
            status: 'submitted',
            age_seconds: 7200,
            submitted_at: '2026-09-08T08:00:00Z',
            scheduled_date: '2026-09-10',
            detail_url: '/admin/inventory/requests/505',
            requester: { id: 7, name: 'Ahmad Teknisi' },
            summary: { count: 3, quantity: 15, label: 'Kabel Drop Core, Fast Connector' },
            actions: { approve: true, reject: true, view: true },
        };

        await page.evaluate((it) => {
            window.__postCalls = [];
            window.__routerPost = (url, data, opts) => {
                window.__postCalls.push({ url, data });
                opts.onStart?.();
                opts.onSuccess?.();
                opts.onFinish?.();
            };
            window.mountHarness(it);
        }, invItem);

        // 1. Verify Inventory Request domain title & badges
        const titleText = await page
            .locator('text=Tinjau Permintaan Barang REQ-FO-2026-001')
            .textContent();
        assert.ok(titleText.includes('REQ-FO-2026-001'), 'Title displays Inventory Request code');
        assert.ok(
            await page.locator('text=Permintaan Barang').first().isVisible(),
            'Domain badge visible',
        );
        assert.ok(
            await page.locator('text=Menunggu persetujuan').first().isVisible(),
            'Status badge visible',
        );

        // 2. Verify Approval Note & approve post payload
        const noteInput = page.locator('#inv-approve-note');
        assert.ok(await noteInput.isVisible(), 'Approval note input visible for inventory request');
        await noteInput.fill('Disetujui untuk maintenance FO ring 2');

        const approveBtn = page.locator('button:has-text("Setujui")');
        await approveBtn.click();

        const calls = await page.evaluate(() => window.__postCalls);
        assert.equal(calls.length, 1, 'One router.post triggered');
        assert.equal(calls[0].url, '/admin/inventory/requests/505/approve');
        assert.deepEqual(calls[0].data, { approval_note: 'Disetujui untuk maintenance FO ring 2' });

        // 3. Mount fresh item for rejection flow
        await page.evaluate((it) => {
            window.__postCalls = [];
            window.__setOpen(true);
            window.__setItem(it);
        }, invItem);

        const tolakBtn = page.locator('button:has-text("Tolak")');
        await tolakBtn.click();

        const reasonInput = page.locator('#spk-reject-reason');
        await reasonInput.waitFor();
        await reasonInput.fill('No'); // Only 2 chars -> should fail min 3 validation

        const confirmTolakBtn = page.locator('button:has-text("Konfirmasi Tolak")');
        await confirmTolakBtn.click();

        // Validation error appears and post was blocked
        const errText = await page
            .locator('text=Alasan penolakan minimal 3 karakter.')
            .textContent();
        assert.ok(errText, 'Min 3 chars error displayed');
        const rejectCalls = await page.evaluate(() => window.__postCalls);
        assert.equal(rejectCalls.length, 0, 'No post made on validation failure');

        // Enter valid reason
        await reasonInput.fill('Stok di gudang tidak mencukupi untuk batch pekerjaan ini.');
        await confirmTolakBtn.click();

        const finalCalls = await page.evaluate(() => window.__postCalls);
        assert.equal(finalCalls.length, 1, 'Reject post triggered');
        assert.equal(finalCalls[0].url, '/admin/inventory/requests/505/reject');
        assert.deepEqual(finalCalls[0].data, {
            rejection_reason: 'Stok di gudang tidak mencukupi untuk batch pekerjaan ini.',
        });
    } finally {
        await browser.close();
    }
});

test('ReviewSheet behavioral browser in Chromium: cross-domain ID collision isolation and honest view affordance', async () => {
    const bundle = await esbuild.build({
        stdin: {
            contents: `
                import React, { useState } from 'react';
                import ReactDOM from 'react-dom/client';
                window.React = React;
                import { ReviewSheet } from './resources/js/Pages/Admin/Approvals/components/ReviewSheet';
                import { reloadQueue } from './resources/js/Pages/Admin/Approvals/Index';
                import { getItemCompositeId } from './resources/js/Pages/Admin/Approvals/components/utils';

                window.route = (name, id) => {
                    if (name === 'admin.inventory.requests.approve') return '/admin/inventory/requests/' + id + '/approve';
                    if (name === 'admin.inventory.requests.reject') return '/admin/inventory/requests/' + id + '/reject';
                    if (name === 'admin.spk.approve') return '/admin/spk/' + id + '/approve';
                    if (name === 'admin.spk.reject') return '/admin/spk/' + id + '/reject';
                    return '/unknown/' + name + '/' + id;
                };

                window.reloadQueueHelper = reloadQueue;
                window.getItemCompositeId = getItemCompositeId;

                window.mountCollisionHarness = (items) => {
                    function CollisionHarness() {
                        const [itemList, setItemList] = useState(items);
                        const [selectedItem, setSelectedItem] = useState(null);
                        const [reviewOpen, setReviewOpen] = useState(false);

                        window.__harnessSetSelected = setSelectedItem;
                        window.__harnessSetReviewOpen = setReviewOpen;
                        window.__harnessGetSelected = () => selectedItem;

                        const handleRefreshQueue = () => {
                            return reloadQueue({
                                router: window.__collisionRouter,
                                selectedItem,
                                setSelectedItem,
                                setReviewOpen,
                            });
                        };

                        return React.createElement('div', { id: 'app' },
                            React.createElement('div', { id: 'item-list' },
                                itemList.map(it => React.createElement('button', {
                                    key: getItemCompositeId(it),
                                    id: 'btn-select-' + getItemCompositeId(it).replace(':', '-'),
                                    onClick: () => {
                                        setSelectedItem(it);
                                        setReviewOpen(true);
                                    }
                                }, it.code))
                            ),
                            React.createElement(ReviewSheet, {
                                open: reviewOpen,
                                onOpenChange: (val) => {
                                    setReviewOpen(val);
                                    if (!val) setSelectedItem(null);
                                },
                                item: selectedItem,
                                onRefreshQueue: handleRefreshQueue,
                            })
                        );
                    }

                    const root = ReactDOM.createRoot(document.getElementById('root'));
                    window.__harnessRoot = root;
                    root.render(React.createElement(CollisionHarness));
                };
            `,
            resolveDir: process.cwd(),
            loader: 'tsx',
        },
        bundle: true,
        format: 'iife',
        write: false,
        define: { 'process.env.NODE_ENV': '"test"' },
        plugins: [
            {
                name: 'resolve-aliases',
                setup(build) {
                    build.onResolve({ filter: /^@\// }, (args) => {
                        const target = path.join(process.cwd(), 'resources/js', args.path.slice(2));
                        const resolved = resolvePath(target);
                        if (resolved) return { path: resolved };
                        return null;
                    });
                    build.onResolve({ filter: /^@inertiajs\/react$/ }, () => ({
                        path: 'inertia-mock',
                        namespace: 'mock',
                    }));
                    build.onLoad({ filter: /.*/, namespace: 'mock' }, () => ({
                        contents: `
                            export const router = {
                                post: (url, data, opts) => window.__routerPost?.(url, data, opts),
                                reload: (opts) => window.__routerReload?.(opts),
                            };
                            export const Link = (props) => window.React.createElement('a', { href: props.href }, props.children);
                            export const useForm = () => ({ data: {}, setData: () => {}, post: () => {}, processing: false, errors: {}, reset: () => {} });
                            export const usePage = () => ({ props: { auth: { user: { id: 1, name: 'Test' } }, ziggy: {} } });
                            export const Head = () => null;
                        `,
                        loader: 'jsx',
                    }));
                    build.onResolve({ filter: /^ziggy-js$/ }, () => ({
                        path: 'ziggy-mock',
                        namespace: 'mock',
                    }));
                    build.onLoad({ filter: /.*/, namespace: 'mock' }, () => ({
                        contents: 'export default function route() { return "/test"; }',
                        loader: 'js',
                    }));
                },
            },
        ],
    });

    const jsCode = bundle.outputFiles[0].text;
    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage();

    try {
        await page.setContent(
            `<!DOCTYPE html><html><body><div id="root"></div><script>${jsCode}</script></body></html>`,
        );

        // Two distinct domain items with the same numeric id: 7
        const spkItem = {
            domain: 'spk',
            composite_id: 'spk:7',
            id: 7,
            code: 'SPK-COLLISION-007',
            title: 'Pemasangan Drop Cable Pelanggan',
            type: 'installation',
            priority: 'medium',
            status: 'waiting_review',
            requester: { id: 11, name: 'Teknisi SPK' },
            assigned_to: null,
            submitted_at: '2026-09-08T10:00:00.000Z',
            age_seconds: 120,
            scheduled_date: '2026-09-09',
            summary: { label: 'Material Ringan', count: 1, quantity: 10 },
            actions: { approve: true, reject: true, view: false },
            detail_url: null,
        };

        const invItem = {
            id: 7,
            domain: 'inventory_request',
            composite_id: 'inventory_request:7',
            code: 'REQ-COLLISION-007',
            title: 'Permintaan Patchcord SFP',
            type: 'maintenance',
            priority: 'high',
            status: 'submitted',
            requester: { id: 22, name: 'Staff Gudang' },
            assigned_to: null,
            submitted_at: '2026-09-08T10:05:00.000Z',
            age_seconds: 60,
            scheduled_date: '2026-09-10',
            summary: { label: 'Material Kritis', count: 2, quantity: 5 },
            actions: { approve: true, reject: true, view: true },
            detail_url: '/admin/inventory/requests/7',
        };

        await page.evaluate(
            ({ spk, inv }) => {
                window.__postCalls = [];
                window.__routerPost = (url, data, opts) => {
                    window.__postCalls.push({ url, data });
                    opts.onStart?.();
                    opts.onSuccess?.();
                    opts.onFinish?.();
                };

                // Notice: fresh items payload returns SPK at index 0 and Inventory Request at index 1
                window.__collisionRouter = {
                    reload: (opts) => {
                        setTimeout(() => {
                            opts.onSuccess?.({
                                props: {
                                    items: {
                                        data: [spk, inv],
                                    },
                                },
                            });
                            opts.onFinish?.();
                        }, 10);
                    },
                };

                window.mountCollisionHarness([spk, inv]);
            },
            { spk: spkItem, inv: invItem },
        );

        // 1. Select Inventory Request (composite_id = inventory_request:7)
        const selectInvBtn = page.locator('#btn-select-inventory_request-7');
        await selectInvBtn.click();

        // Verify sheet title and code for Inventory Request
        const sheetTitle = page.getByText(/Tinjau permintaan barang/, { exact: false }).first();
        await sheetTitle.waitFor();
        const codeText = await sheetTitle.textContent();
        assert.ok(codeText.includes('REQ-COLLISION-007'), 'Inventory Request code shown in sheet');

        // Verify honest affordance: invItem has actions.view = true, detail_url = '/admin/inventory/requests/7'
        const detailLink = page.locator('a:has-text("Lihat detail")');
        assert.equal(
            await detailLink.isVisible(),
            true,
            'Lihat detail button visible when actions.view=true',
        );
        assert.equal(await detailLink.getAttribute('href'), '/admin/inventory/requests/7');

        // 2. Trigger queue reload while review sheet is open for Inventory Request {id: 7}
        // In the returned payload, SPK {id: 7} is at index 0, Inventory Request {id: 7} is at index 1!
        const refreshResult = await page.evaluate(async () => {
            const res = await window.reloadQueueHelper({
                router: window.__collisionRouter,
                selectedItem: window.__harnessGetSelected(),
                setSelectedItem: window.__harnessSetSelected,
                setReviewOpen: window.__harnessSetReviewOpen,
            });
            return {
                res,
                selectedAfterReload: window.__harnessGetSelected(),
            };
        });

        assert.equal(refreshResult.res.success, true, 'Reload queue succeeded');
        assert.equal(
            refreshResult.selectedAfterReload?.domain,
            'inventory_request',
            'Selected item domain preserved after reload (NOT hijacked by SPK at index 0)',
        );
        assert.equal(
            refreshResult.selectedAfterReload?.code,
            'REQ-COLLISION-007',
            'Selected item code preserved after reload',
        );
        assert.equal(
            refreshResult.selectedAfterReload?.composite_id,
            'inventory_request:7',
            'Composite ID matches inventory_request:7',
        );

        // 3. Confirm that approving the item triggers inventory approve route, NEVER spk approve
        const approveBtn = page.locator('button:has-text("Setujui")');
        await approveBtn.click();

        const postCalls = await page.evaluate(() => window.__postCalls);
        assert.equal(postCalls.length, 1, 'One approve POST executed');
        assert.equal(
            postCalls[0].url,
            '/admin/inventory/requests/7/approve',
            'Approve request targeted inventory endpoint (/admin/inventory/requests/7/approve), NOT SPK!',
        );

        // 4. Now select SPK {id: 7} (composite_id = spk:7)
        // Ensure ReviewSheetInner unmounts and remounts with SPK identity and state
        const selectSpkBtn = page.locator('#btn-select-spk-7');
        await selectSpkBtn.click();

        const spkSheetTitle = page.locator('text=Tinjau SPK');
        await spkSheetTitle.waitFor();
        const spkCodeText = await spkSheetTitle.textContent();
        assert.ok(spkCodeText.includes('SPK-COLLISION-007'), 'SPK code shown in sheet');

        // Verify honest affordance: spkItem has actions.view = false, detail_url = null
        // "Lihat detail" must NOT be rendered!
        const spkDetailLink = page.locator('a:has-text("Lihat detail")');
        assert.equal(await spkDetailLink.count(), 0, 'Lihat detail hidden when actions.view=false');

        // Approve SPK {id: 7}
        const spkApproveBtn = page.locator('button:has-text("Setujui")');
        await spkApproveBtn.click();

        const postCallsAfterSpk = await page.evaluate(() => window.__postCalls);
        assert.equal(postCallsAfterSpk.length, 2, 'Second approve POST executed');
        assert.equal(
            postCallsAfterSpk[1].url,
            '/admin/spk/7/approve',
            'Approve request targeted SPK endpoint (/admin/spk/7/approve)',
        );
    } finally {
        await browser.close();
    }
});
