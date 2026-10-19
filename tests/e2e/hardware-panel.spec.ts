/**
 * @hardware-panel Hardware panel UI (.scratch/hardware-panel-ui): the front panel of OLTs, routers, ODC/ODP, ONTs and
 * racks drawn like the unit, its port sheet, the model picker, patching two ports, the rack and the fibre path.
 *
 * Fixtures: E2eFixtureSeeder::seedPanelFixtures, in the DEMO company.
 *   E2E-PANEL-OLT    custom face: 128 PON ports (pon1..pon8 of 16, gpon-olt_1/{slot}/{port}) and uplinks UP1..UP4, 2U
 *   E2E-PANEL-ODC    1:8 on PON 1 of the OLT; output 1 feeds the ODP
 *   E2E-PANEL-ODP    1:8; output 1 is the drop of E2E-PANEL-SUB (active, account e2e-panel@demo, ONT E2E-PANEL-ONT),
 *                    output 2 the drop of E2E-PANEL-SUB-ISO (suspended: isolir)
 *   E2E-PANEL-RTR    CCR2004-16G-2S+ model; sfp-sfpplus1 ("ke OLT") patched to UP1, ether3 ("cadangan") marked down
 *   E2E-PANEL-NEW    router without a model (vendor MikroTik, model hAP ac2): this spec never gives it one
 *   E2E-PANEL-RACK   42U rack with the OLT at U10 (2U) and the router at U13 (1U)
 *
 * Two describes, picked by E2E_RADIUS_LAB like radius-provisioning.spec.ts:
 *  1. CI (default, RADIUS_ENABLED off in .env.e2e): the panel cannot know who is online, so a subscriber port is
 *     "Terpakai" and the legend says "Status online tidak tersedia: RADIUS nonaktif." Everything else is drawn here.
 *  2. Lab (opt-in: E2E_RADIUS_LAB=1 plus the RADIUS_* exports of scripts/e2e-radius-lab.sh, lab up in
 *     ~/labs/freeradius): an accounting Start for e2e-panel@demo makes the panels show "Online". Without the lab that
 *     describe is skipped; with it the CI describe is, because its copy assumes RADIUS is off.
 *
 * Performance (design section 6), read from the panel's own marks: P11 `panel:painted` (ms since navigation start, the
 * moment data-panel-ready is set), P12 measure `panel:paint` (props -> painted), P13 measure `panel:hover` (hover or
 * focus -> tooltip placed). The numbers are printed as `[hardware-panel perf] {...}` and attached to the test.
 * The typical sample has to meet the budget (median of 4 visits for P11 and P12, p95 of 40 moves for P13) and the worst
 * one may take twice as long: this runs on the shared dev host (PHP built-in server, other builds and suites on the same
 * CPU), where one slow PHP request or one GC pause would otherwise turn a hard 16 ms or 1.5 s into a flake. The host
 * measured far below both limits (see the printed numbers), so the margin only absorbs noise.
 *
 * Re-runnable without a reset: a write is undone by the test that made it (patch released, device unmounted) and the
 * "apply a model" journey creates its own router.
 */
import { execFileSync } from 'node:child_process';
import { existsSync, readFileSync } from 'node:fs';
import { homedir, loadavg } from 'node:os';
import { join } from 'node:path';
import type { Locator, Page } from '@playwright/test';

import { csrfToken, inertiaProps, loginAs, selectSearchOption, unique } from './support/demo';
import { expect, test } from './support/fixtures';

const OLT = 'E2E-PANEL-OLT';
const ODC = 'E2E-PANEL-ODC';
const ODP = 'E2E-PANEL-ODP';
const ONT = 'E2E-PANEL-ONT';
const ROUTER = 'E2E-PANEL-RTR';
const UNMODELLED = 'E2E-PANEL-NEW';
const RACK = 'E2E-PANEL-RACK';
const SUBSCRIPTION = 'E2E-PANEL-SUB';
const ISOLATED_SUBSCRIPTION = 'E2E-PANEL-SUB-ISO';
const CUSTOMER = 'E2E Panel Customer';
const ISOLATED_CUSTOMER = 'E2E Panel Isolir Customer';
const ACCOUNT = 'e2e-panel@demo';
const HAP_AC2 = 'MikroTik hAP ac² (RBD52G-5HacD2HnD)';

const PON_PORTS = 128;
const PORTS = PON_PORTS + 4;

const BUDGET = { tti: 1500, paint: 100, hover: 16 } as const;
// The typical sample must meet the budget; the worst one may take twice as long, because a slow PHP request or a GC pause
// on this shared host is not a regression.
const WORST = 2;

const READY = '[data-panel-ready="true"]';
const RACK_READY = '[data-rack-asset]';

const LAB_DIR = join(homedir(), 'labs', 'freeradius');

/** The first `KEY=value` line of an env-style file, or null. Values are never logged. */
function envFileValue(file: string, key: string): string | null {
    if (!existsSync(file)) return null;
    const line = readFileSync(file, 'utf8')
        .split('\n')
        .find((candidate) => candidate.startsWith(`${key}=`));

    return line ? line.slice(key.length + 1).trim() : null;
}

const LAB =
    process.env.E2E_RADIUS_LAB === '1' ||
    envFileValue(join(process.cwd(), '.env.e2e'), 'E2E_RADIUS_LAB') === '1';

/** The overlay button of a port, keyed `group:number` as the panel keys it. */
const port = (page: Page, key: string) => page.locator(`[data-port-button="${key}"]`);

/** The drawn symbol of a port. */
const glyph = (page: Page, key: string) => page.locator(`svg use[data-port="${key}"]`);

interface AssetRow {
    id: string;
    code: string;
    name: string;
}

/** The part of the Inertia JSON of the deferred `panel` reload the spec reads. */
interface PanelResponse {
    props: {
        panel: { groups: Array<{ key: string; ports: Array<{ id: string; number: number }> }> };
    };
}

/** The part of the Inertia JSON of the `linkPortOptions` reload the spec reads. */
interface LinkPortsResponse {
    props: { linkPortOptions: Array<{ value: string; label: string }> };
}

/** The public id of an asset by its code or its name, from the index search. */
async function assetId(page: Page, term: string): Promise<string> {
    const { props } = await inertiaProps(
        page,
        `/admin/network-assets?search=${encodeURIComponent(term)}`,
    );
    const rows: AssetRow[] = props?.assets?.data ?? [];
    const row = rows.find((asset) => asset.code === term || asset.name === term);
    if (!row) throw new Error(`${term} is not in the asset list`);

    return row.id;
}

/** Opens the page of an asset and waits until its panel (or, for a rack, its units) is drawn. */
async function openPanel(page: Page, code: string, ready = READY) {
    await page.goto(`/admin/network-assets/${await assetId(page, code)}`);
    await expect(page.locator(ready)).toBeVisible({ timeout: 20_000 });
}

/** Opens the sheet of a port and waits until its detail, not the skeleton, is in. */
async function openPort(page: Page, key: string) {
    await port(page, key).click();
    const sheet = page.getByRole('dialog');
    await expect(sheet).toBeVisible();
    await expect(sheet.getByRole('status', { name: 'Memuat detail port' })).toBeHidden();

    return sheet;
}

/** Opens the page of an asset and returns the deferred `panel` prop the server sent for it. */
async function openPanelData(page: Page, code: string) {
    const id = await assetId(page, code);
    const loaded = page.waitForResponse(
        (response) =>
            response.url().includes(id) &&
            response.request().headers()['x-inertia-partial-data']?.includes('panel') === true,
    );
    await page.goto(`/admin/network-assets/${id}`);
    const body: PanelResponse = await (await loaded).json();
    await expect(page.locator(READY)).toBeVisible({ timeout: 20_000 });

    return { id, panel: body.props.panel };
}

/** A computed colour of an element, as the browser resolves it. */
const colourOf = (locator: Locator) =>
    locator.evaluate((element) => getComputedStyle(element).color);

/** What a colour expression (a token) resolves to now, in the theme of the page. */
const resolved = (page: Page, expression: string) =>
    page.evaluate((value) => {
        const probe = document.createElement('span');
        probe.style.color = value;
        document.body.append(probe);
        const colour = getComputedStyle(probe).color;
        probe.remove();

        return colour;
    }, expression);

/** The custom property a state sets on its symbol (--fill, --hatch, --cross); '' when it sets none. */
const cueOf = (locator: Locator, name: string) =>
    locator.evaluate(
        (element, property) => getComputedStyle(element).getPropertyValue(property).trim(),
        name,
    );

async function setTheme(page: Page, dark: boolean) {
    await page.evaluate((enabled) => localStorage.setItem('darkMode', String(enabled)), dark);
    await page.reload();
    await expect(page.locator('html')).toHaveClass(dark ? /\bdark\b/ : /^(?!.*\bdark\b)/);
    await expect(page.locator(READY)).toBeVisible({ timeout: 20_000 });
}

/** The value below which `share` of the values lie (0.5 is the median). */
function quantile(values: number[], share: number) {
    const ordered = [...values].sort((a, b) => a - b);

    return ordered[Math.min(ordered.length - 1, Math.ceil(ordered.length * share) - 1)];
}

const round = (value: number) => Math.round(value * 100) / 100;

const summary = (values: number[]) => ({
    n: values.length,
    median: round(quantile(values, 0.5)),
    p95: round(quantile(values, 0.95)),
    max: round(Math.max(...values)),
});

/** Every `panel:hover` measure of the page so far, in ms. */
const hoverMeasures = (page: Page) =>
    page.evaluate(() =>
        performance.getEntriesByName('panel:hover', 'measure').map((entry) => entry.duration),
    );

test.describe('@hardware-panel hardware panel', () => {
    test.skip(LAB, 'The lab describe covers the live panel: this copy assumes RADIUS is off.');

    test('@smoke the 128-PON OLT panel meets the performance budget', async ({
        page,
    }, testInfo) => {
        test.setTimeout(180_000);
        await loginAs(page, 'admin');
        const url = `/admin/network-assets/${await assetId(page, OLT)}`;

        // The first visit pulls the page chunks cold; the next ones come from the cache.
        const visits: Array<{ props: number; painted: number; paint: number }> = [];
        for (let visit = 0; visit < 4; visit += 1) {
            await page.goto(url);
            await expect(page.locator(READY)).toBeVisible({ timeout: 20_000 });
            visits.push(
                await page.evaluate(() => ({
                    props: performance.getEntriesByName('panel:props')[0].startTime,
                    painted: performance.getEntriesByName('panel:painted')[0].startTime,
                    paint: performance.getEntriesByName('panel:paint', 'measure')[0].duration,
                })),
            );
        }

        // P7: one symbol and one overlay button per port. P8: one tooltip for the whole panel.
        await expect(page.locator('svg use[data-port]')).toHaveCount(PORTS);
        await expect(page.locator('[data-port-button]')).toHaveCount(PORTS);
        await expect(page.locator('[role="tooltip"]')).toHaveCount(1);

        // P13: twenty focus moves by keyboard (the first focus counts), then twenty hovers on other ports. Each
        // action is awaited until the panel has measured it, so no sleep stands between two of them.
        const measured = (count: number) =>
            page.waitForFunction(
                (expected) =>
                    performance.getEntriesByName('panel:hover', 'measure').length >= expected,
                count,
            );
        let moves = 1;
        await port(page, 'pon1:1').focus();
        await measured(moves);
        for (const key of [
            ...Array<string>(15).fill('ArrowRight'),
            'ArrowDown',
            ...Array<string>(3).fill('ArrowLeft'),
        ]) {
            await page.keyboard.press(key);
            moves += 1;
            await measured(moves);
        }
        const keyboard = await hoverMeasures(page);

        for (let n = 0; n < 20; n += 1) {
            await port(page, `pon${3 + (n % 6)}:${1 + ((n * 7) % 16)}`).hover();
            moves += 1;
            await measured(moves);
        }
        const pointer = (await hoverMeasures(page)).slice(keyboard.length);

        const painted = visits.map((visit) => visit.painted);
        const paint = visits.map((visit) => visit.paint);
        const numbers = {
            host: { loadAverage: loadavg().map(round), ports: PORTS },
            P11_tti_ms: {
                visits: painted.map(round),
                median: round(quantile(painted, 0.5)),
                budget: BUDGET.tti,
            },
            P12_paint_ms: {
                visits: paint.map(round),
                median: round(quantile(paint, 0.5)),
                budget: BUDGET.paint,
            },
            P13_hover_ms: {
                keyboard: summary(keyboard),
                pointer: summary(pointer),
                budget: BUDGET.hover,
            },
            propsAt_ms: visits.map((visit) => round(visit.props)),
        };
        console.log(`[hardware-panel perf] ${JSON.stringify(numbers)}`);
        await testInfo.attach('hardware-panel-perf.json', {
            body: JSON.stringify(numbers, null, 2),
            contentType: 'application/json',
        });

        expect(keyboard.length, 'every focus move was measured').toBeGreaterThanOrEqual(20);
        expect(pointer.length, 'every hover was measured').toBeGreaterThanOrEqual(20);
        expect(
            quantile(painted, 0.5),
            `P11 the panel is drawn within ${BUDGET.tti} ms (median of 4 visits)`,
        ).toBeLessThan(BUDGET.tti);
        expect(
            Math.max(...painted),
            `P11 the slowest visit stays within ${WORST}x the budget`,
        ).toBeLessThan(BUDGET.tti * WORST);
        expect(
            quantile(paint, 0.5),
            `P12 the panel paints within ${BUDGET.paint} ms of its props (median of 4 visits)`,
        ).toBeLessThan(BUDGET.paint);
        expect(
            Math.max(...paint),
            `P12 the slowest paint stays within ${WORST}x the budget`,
        ).toBeLessThan(BUDGET.paint * WORST);
        const moves40 = [...keyboard, ...pointer];
        expect(
            quantile(moves40, 0.95),
            `P13 a hover or focus move places the tooltip within ${BUDGET.hover} ms (p95 of 40)`,
        ).toBeLessThan(BUDGET.hover);
        expect(
            Math.max(...moves40),
            `P13 the slowest move stays within ${WORST}x the budget`,
        ).toBeLessThan(BUDGET.hover * WORST);
    });

    test('the OLT draws a symbol and a button per port with the state in their names', async ({
        page,
    }) => {
        await loginAs(page, 'admin');
        await openPanel(page, OLT);

        await expect(page.getByRole('group', { name: `Port ${OLT}` })).toBeVisible();
        await expect(page.locator('[data-port-button^="pon"]')).toHaveCount(PON_PORTS);
        await expect(page.locator('[data-port-button]')).toHaveCount(PORTS);
        await expect(page.locator('svg use[data-port]')).toHaveCount(PORTS);

        // PON 1 feeds the ODC, whose output 1 feeds the ODP: two subscribers behind it, none known online.
        await expect(port(page, 'pon1:1')).toHaveAccessibleName(
            `gpon-olt_1/1/1: Terpakai, ke ${ODC} 1:8, 2 pelanggan, 0 online`,
        );
        await expect(port(page, 'pon1:2')).toHaveAccessibleName('gpon-olt_1/1/2: Kosong');
        await expect(port(page, 'pon8:16')).toHaveAccessibleName('gpon-olt_1/8/16: Kosong');
        await expect(port(page, 'uplink:1')).toHaveAccessibleName(
            `UP1: Terpakai, ke ${ROUTER} sfp-sfpplus1`,
        );

        const legend = page.getByRole('list', { name: 'Legenda status port' });
        await expect(legend).toContainText(/Terpakai\s*2/);
        await expect(legend).toContainText(/Kosong\s*130/);
        await expect(
            page.getByText('Status online tidak tersedia: RADIUS nonaktif.'),
        ).toBeVisible();
        await expect(page.getByText('Susunan manual · 132 port')).toBeVisible();
    });

    test('arrows move between ports, Enter opens the port sheet, Escape returns focus', async ({
        page,
    }) => {
        await loginAs(page, 'admin');
        await openPanel(page, OLT);

        // One tab stop, on the first port; Tab passes through the panel in one press.
        await expect(page.locator('[data-port-button][tabindex="0"]')).toHaveCount(1);
        await expect(port(page, 'pon1:1')).toHaveAttribute('tabindex', '0');
        await port(page, 'pon1:1').focus();
        await page.keyboard.press('Tab');
        await expect(page.locator('[data-port-button]:focus')).toHaveCount(0);
        await page.keyboard.press('Shift+Tab');
        await expect(port(page, 'pon1:1')).toBeFocused();

        for (const [key, expected] of [
            ['ArrowRight', 'pon1:2'],
            ['ArrowDown', 'pon2:2'],
            ['ArrowLeft', 'pon2:1'],
            ['ArrowUp', 'pon1:1'],
            ['End', 'uplink:4'],
            ['Home', 'pon1:1'],
            ['ArrowRight', 'pon1:2'],
        ]) {
            await page.keyboard.press(key);
            await expect(port(page, expected), `${key} moves focus to ${expected}`).toBeFocused();
        }
        await expect(port(page, 'pon1:2')).toHaveAttribute('tabindex', '0');
        await expect(port(page, 'pon1:1')).toHaveAttribute('tabindex', '-1');

        await page.keyboard.press('Enter');
        const sheet = page.getByRole('dialog', { name: 'gpon-olt_1/1/2' });
        await expect(sheet).toBeVisible();
        await expect(sheet.getByText(`Slot 1 · ${OLT}`)).toBeVisible();
        await expect(port(page, 'pon1:2')).toHaveAttribute('aria-expanded', 'true');
        await expect(sheet.getByRole('status', { name: 'Memuat detail port' })).toBeHidden();
        await expect(sheet.getByText('Kosong', { exact: true })).toBeVisible();
        await expect(sheet.getByText('PON 2', { exact: true })).toBeVisible();

        await page.keyboard.press('Escape');
        await expect(sheet).toBeHidden();
        await expect(port(page, 'pon1:2')).toBeFocused();
        await expect(port(page, 'pon1:2')).toHaveAttribute('aria-expanded', 'false');

        // Space opens it as well, and the next arrow works from where focus came back to.
        await page.keyboard.press('Space');
        await expect(page.getByRole('dialog', { name: 'gpon-olt_1/1/2' })).toBeVisible();
        await page.keyboard.press('Escape');
        await expect(port(page, 'pon1:2')).toBeFocused();
        await page.keyboard.press('ArrowRight');
        await expect(port(page, 'pon1:3')).toBeFocused();
    });

    test('one tooltip follows hover and focus', async ({ page }) => {
        await loginAs(page, 'admin');
        await openPanel(page, OLT);

        const tooltip = page.locator(`${READY} [role="tooltip"]`);
        await expect(page.locator('[role="tooltip"]')).toHaveCount(1);
        await expect(tooltip).not.toHaveAttribute('data-open', '');

        await port(page, 'pon1:1').hover();
        await expect(tooltip).toHaveAttribute('data-open', '');
        await expect(tooltip).toContainText('gpon-olt_1/1/1 · Terpakai');
        await expect(tooltip).toContainText('PON 1 di ODN');
        await expect(tooltip).toContainText(`ke ${ODC} · 1:8`);
        await expect(tooltip).toContainText('2 pelanggan · 0 online');

        await port(page, 'pon8:16').hover();
        await expect(tooltip).toContainText('gpon-olt_1/8/16 · Kosong');
        await expect(tooltip).toContainText('PON 128 di ODN');
        await expect(tooltip).not.toContainText('gpon-olt_1/1/1');

        await page.mouse.move(0, 0);
        await expect(tooltip).not.toHaveAttribute('data-open', '');

        await port(page, 'uplink:1').focus();
        await expect(tooltip).toHaveAttribute('data-open', '');
        await expect(tooltip).toContainText('UP1 · Terpakai');
        await expect(tooltip).toContainText(`ke ${ROUTER} · sfp-sfpplus1`);

        // The same element all along; leaving the panel with the keyboard closes it.
        await expect(page.locator('[role="tooltip"]')).toHaveCount(1);
        await page.keyboard.press('Tab');
        await expect(tooltip).not.toHaveAttribute('data-open', '');
    });

    test('each state has its colour, a cue besides colour and its label', async ({ page }) => {
        await loginAs(page, 'admin');

        // ODP: the drop of E2E-PANEL-SUB (RADIUS off: occupied), the drop of the suspended one (isolir), a free output.
        await openPanel(page, ODP);
        await expect(port(page, 's1:1')).toHaveAccessibleName(
            `1: Terpakai, pelanggan ${SUBSCRIPTION} ${CUSTOMER}`,
        );
        await expect(port(page, 's1:2')).toHaveAccessibleName(
            `2: Diisolir, pelanggan ${ISOLATED_SUBSCRIPTION} ${ISOLATED_CUSTOMER}`,
        );
        await expect(port(page, 's1:3')).toHaveAccessibleName('3: Kosong');
        const odpLegend = page.getByRole('list', { name: 'Legenda status port' });
        for (const label of ['Terpakai', 'Diisolir', 'Kosong']) {
            await expect(odpLegend).toContainText(label);
        }
        for (const [key, state, colour, cue, value] of [
            ['s1:1', 'occupied', 'var(--status-info)', '--fill', 'currentColor'],
            ['s1:2', 'isolir', 'var(--status-warning)', '--hatch', '1'],
            ['s1:3', 'free', 'hsl(var(--muted-foreground))', '--hatch', ''],
        ]) {
            await expect(port(page, key)).toHaveAttribute('data-state', state);
            await expect(glyph(page, key)).toHaveAttribute('data-state', state);
            expect(await colourOf(glyph(page, key)), `${state} colour`).toBe(
                await resolved(page, colour),
            );
            expect(await cueOf(glyph(page, key), cue), `${state} cue ${cue}`).toBe(value);
        }
        // Three states, three colours.
        const odpColours = new Set<string>();
        for (const key of ['s1:1', 's1:2', 's1:3'])
            odpColours.add(await colourOf(glyph(page, key)));
        expect(odpColours.size).toBe(3);

        // Router: a patched port, a port marked down, a free one.
        await openPanel(page, ROUTER);
        await expect(port(page, 'sfp:1')).toHaveAccessibleName(
            `sfp-sfpplus1, ke OLT: Terpakai, ke ${OLT} UP1`,
        );
        await expect(port(page, 'ether:3')).toHaveAccessibleName('ether3, cadangan: Dinonaktifkan');
        await expect(port(page, 'ether:1')).toHaveAccessibleName('ether1: Kosong');
        const routerLegend = page.getByRole('list', { name: 'Legenda status port' });
        for (const label of ['Terpakai', 'Dinonaktifkan', 'Kosong']) {
            await expect(routerLegend).toContainText(label);
        }
        for (const [key, state, colour, cue, value] of [
            ['sfp:1', 'occupied', 'var(--status-info)', '--fill', 'currentColor'],
            ['ether:3', 'admin_down', 'var(--status-danger)', '--cross', '1'],
            ['ether:1', 'free', 'hsl(var(--muted-foreground))', '--cross', ''],
        ]) {
            await expect(port(page, key)).toHaveAttribute('data-state', state);
            expect(await colourOf(glyph(page, key)), `${state} colour`).toBe(
                await resolved(page, colour),
            );
            expect(await cueOf(glyph(page, key), cue), `${state} cue ${cue}`).toBe(value);
        }

        // The ONT's PON port takes the drop of its subscription.
        await openPanel(page, ONT);
        await expect(port(page, 'pon:1')).toHaveAccessibleName(
            `PON: Terpakai, ke ${ODP} 1:8 · keluaran 1, pelanggan ${SUBSCRIPTION} ${CUSTOMER}`,
        );
        await expect(port(page, 'lan:1')).toHaveAccessibleName('LAN1: Kosong');
        await expect(page.locator('[data-port-button]')).toHaveCount(7);
    });

    test('dark mode swaps the state colours to the dark tokens', async ({ page }) => {
        await loginAs(page, 'admin');
        await openPanel(page, ROUTER);

        // Design 5.3: occupied is --status-info, #2563eb in light and #60a5fa in dark.
        const light = await colourOf(glyph(page, 'sfp:1'));
        expect(light).toBe(await resolved(page, 'var(--status-info)'));
        expect(light).toBe('rgb(37, 99, 235)');

        await setTheme(page, true);
        const dark = await colourOf(glyph(page, 'sfp:1'));
        expect(dark).toBe(await resolved(page, 'var(--status-info)'));
        expect(dark).toBe('rgb(96, 165, 250)');
        // A port marked down is --status-danger: #f87171 in dark.
        expect(await colourOf(glyph(page, 'ether:3'))).toBe('rgb(248, 113, 113)');
    });

    test('the template picker gives an unmodelled router the model of the catalogue', async ({
        page,
    }) => {
        test.setTimeout(120_000);
        await loginAs(page, 'admin');

        // The fixture only proves the picker and its suggestion: this spec never applies a model to it.
        await page.goto(`/admin/network-assets/${await assetId(page, UNMODELLED)}`);
        await expect(page.getByRole('heading', { name: 'Pilih model perangkat' })).toBeVisible();
        await expect(page.getByRole('combobox', { name: 'Model perangkat' })).toHaveValue(HAP_AC2);
        await expect(page.getByText('Disarankan dari vendor dan model aset ini.')).toBeVisible();
        await expect(page.locator('[data-port-button]')).toHaveCount(0);

        // A router of the same text, made here so every run starts from "no model".
        const name = `E2E Panel Router ${unique('model')}`;
        await page.goto('/admin/network-assets/create');
        await page.getByLabel(/^Nama( \*)?$/).fill(name);
        await page.getByLabel('Tipe aset').selectOption({ label: 'Router' });
        await page.getByLabel('Vendor').fill('MikroTik');
        await page.getByLabel(/^Model( \*)?$/).fill('hAP ac2 RBD52G-5HacD2HnD');
        await page.getByRole('button', { name: 'Simpan', exact: true }).click();
        await expect(page.getByText('Aset jaringan dibuat.')).toBeVisible();

        await page.goto(`/admin/network-assets/${await assetId(page, name)}`);
        await expect(page.getByRole('heading', { name: 'Pilih model perangkat' })).toBeVisible();
        await expect(page.getByRole('combobox', { name: 'Model perangkat' })).toHaveValue(HAP_AC2);
        await page.getByRole('button', { name: 'Terapkan model' }).click();

        // The panel is drawn from the model: five ethernet ports, all free, and the model named in the header.
        await expect(page.locator(READY)).toBeVisible({ timeout: 20_000 });
        await expect(page.locator('[data-port-button]')).toHaveCount(5);
        for (const number of [1, 2, 3, 4, 5]) {
            await expect(port(page, `ether:${number}`)).toHaveAccessibleName(
                `ether${number}: Kosong`,
            );
        }
        await expect(page.getByText(HAP_AC2, { exact: true })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Ganti model' })).toBeVisible();
        await expect(page.getByRole('heading', { name: 'Pilih model perangkat' })).toHaveCount(0);

        // The model is stored, not just drawn.
        await page.reload();
        await expect(page.locator(READY)).toBeVisible({ timeout: 20_000 });
        await expect(page.locator('[data-port-button]')).toHaveCount(5);
    });

    test('link flow: a router port is patched to an OLT uplink and released from the other side', async ({
        page,
    }) => {
        test.setTimeout(150_000);
        await loginAs(page, 'admin');
        await openPanel(page, ROUTER);

        await test.step('ether2 starts free, so a run never depends on the one before it', async () => {
            if ((await port(page, 'ether:2').getAttribute('data-state')) !== 'free') {
                const leftover = await openPort(page, 'ether:2');
                await leftover.getByRole('button', { name: 'Lepas sambungan' }).click();
                await page
                    .getByRole('alertdialog')
                    .getByRole('button', { name: 'Lepas sambungan' })
                    .click();
                await page.keyboard.press('Escape');
            }
            await expect(port(page, 'ether:2')).toHaveAttribute('data-state', 'free');
        });

        await test.step('pick the OLT, then one of its free uplinks, and patch', async () => {
            const sheet = await openPort(page, 'ether:2');
            await sheet.getByRole('button', { name: 'Hubungkan ke port lain' }).click();
            await selectSearchOption(page, 'Perangkat tujuan', OLT);
            await page.getByRole('combobox', { name: 'Port tujuan' }).click();
            // UP1 is patched to the router already: once the list is in, only the free uplinks are offered.
            await expect(page.getByRole('option', { name: /^UP2\b/ })).toBeVisible();
            await expect(page.getByRole('option', { name: /^UP1\b/ })).toHaveCount(0);
            await page.getByRole('option', { name: /^UP2\b/ }).click();
            await sheet.getByRole('button', { name: 'Hubungkan', exact: true }).click();

            await expect(sheet.getByRole('link', { name: `${OLT} · UP2` })).toBeVisible();
            await expect(sheet.getByRole('button', { name: 'Lepas sambungan' })).toBeVisible();
            await expect(port(page, 'ether:2')).toHaveAttribute('data-state', 'occupied');
            await page.keyboard.press('Escape');
            await expect(sheet).toBeHidden();
        });

        await test.step('the OLT shows the same patch, and releasing it there frees both ports', async () => {
            await openPanel(page, OLT);
            await expect(port(page, 'uplink:2')).toHaveAttribute('data-state', 'occupied');
            await expect(port(page, 'uplink:2')).toHaveAccessibleName(
                `UP2: Terpakai, ke ${ROUTER} ether2`,
            );

            const sheet = await openPort(page, 'uplink:2');
            await sheet.getByRole('button', { name: 'Lepas sambungan' }).click();
            const confirm = page.getByRole('alertdialog', { name: 'Lepas sambungan UP2?' });
            await expect(confirm).toContainText(`ether2 di ${ROUTER} menjadi kosong`);
            await confirm.getByRole('button', { name: 'Lepas sambungan' }).click();
            await expect(confirm).toBeHidden();
            await expect(port(page, 'uplink:2')).toHaveAttribute('data-state', 'free');
            await page.keyboard.press('Escape');

            await openPanel(page, ROUTER);
            await expect(port(page, 'ether:2')).toHaveAttribute('data-state', 'free');
            // The patch to UP1 of the fixture is untouched.
            await expect(port(page, 'sfp:1')).toHaveAttribute('data-state', 'occupied');
        });
    });

    test('a port taken while the form was open is refused inside the sheet', async ({ page }) => {
        test.setTimeout(150_000);
        await loginAs(page, 'admin');
        const router = await openPanelData(page, ROUTER);
        const ether5 = router.panel.groups
            .find((group) => group.key === 'ether')!
            .ports.find((candidate) => candidate.number === 5)!.id;
        const linkUrl = `/admin/network-assets/${router.id}/ports/${ether5}/link`;
        const headers = async () => ({
            'X-XSRF-TOKEN': await csrfToken(page),
            Accept: 'text/html',
        });

        const sheet = await openPort(page, 'ether:2');
        await sheet.getByRole('button', { name: 'Hubungkan ke port lain' }).click();
        const offered = page.waitForResponse(
            (response) =>
                response
                    .request()
                    .headers()
                    ['x-inertia-partial-data']?.includes('linkPortOptions') === true,
        );
        await selectSearchOption(page, 'Perangkat tujuan', OLT);
        const listed: LinkPortsResponse = await (await offered).json();
        const uplink3 = listed.props.linkPortOptions.find(
            (option) => option.label === 'UP3',
        )!.value;

        try {
            // Someone else patches ether5 to UP3 while this form still lists UP3 as free.
            const taken = await page.request.post(linkUrl, {
                headers: await headers(),
                form: { target_port_id: uplink3 },
            });
            expect(taken.ok()).toBe(true);

            await page.getByRole('combobox', { name: 'Port tujuan' }).click();
            await page.getByRole('option', { name: /^UP3\b/ }).click();
            await sheet.getByRole('button', { name: 'Hubungkan', exact: true }).click();

            await expect(sheet.getByText(`Port UP3 di ${OLT} sudah terhubung.`)).toBeVisible();
            await expect(
                sheet.getByRole('button', { name: 'Hubungkan', exact: true }),
            ).toBeVisible();
            await expect(port(page, 'ether:2')).toHaveAttribute('data-state', 'free');
        } finally {
            // No redirect is followed: after a DELETE the browser-less client would repeat the DELETE on the page the
            // server sends it back to, and that page is the asset itself.
            await page.request.delete(linkUrl, { headers: await headers(), maxRedirects: 0 });
        }
    });

    test('rack: the OLT and the router sit at U10 and U13, a device is mounted and taken out', async ({
        page,
    }) => {
        test.setTimeout(150_000);
        await loginAs(page, 'admin');
        await openPanel(page, RACK, RACK_READY);

        const group = page.getByRole('group', { name: `Isi ${RACK}` });
        const mounted = page.getByRole('button', { name: `${UNMODELLED}, U20, 1U` });
        await expect(page.getByText('42U · 2 perangkat')).toBeVisible();
        await expect(group.getByRole('button', { name: `${OLT}, U10, 2U` })).toBeVisible();
        await expect(group.getByRole('button', { name: `${ROUTER}, U13, 1U` })).toBeVisible();
        // 42 units less the 2U OLT and the 1U router: every other unit is a button that mounts a device.
        await expect(group.getByRole('button', { name: /kosong, pasang perangkat$/ })).toHaveCount(
            39,
        );
        await expect(
            group.getByRole('button', { name: 'U10 kosong, pasang perangkat' }),
        ).toHaveCount(0);
        await expect(
            group.getByRole('button', { name: 'U11 kosong, pasang perangkat' }),
        ).toHaveCount(0);

        // A device left in U20 by a run that stopped halfway is taken out first.
        if (await mounted.count()) {
            await mounted.click();
            await page.getByRole('button', { name: 'Lepas dari rak' }).click();
            await expect(mounted).toHaveCount(0);
        }

        await test.step('a 2U device at U12 would run into the router at U13: refused, nothing mounted', async () => {
            await group.getByRole('button', { name: 'U12 kosong, pasang perangkat' }).click();
            const dialog = page.getByRole('dialog', { name: 'Pasang perangkat di U12' });
            await expect(dialog).toBeVisible();
            await selectSearchOption(page, 'Perangkat', UNMODELLED);
            await expect(dialog.getByLabel('Mulai di U')).toHaveValue('12');
            await dialog.getByLabel('Tinggi (U)').fill('2');
            await dialog.getByRole('button', { name: 'Pasang di rak' }).click();

            await expect(dialog.getByText(`U13 sudah terisi ${ROUTER}.`)).toBeVisible();
            await dialog.getByRole('button', { name: 'Batal' }).click();
            await expect(dialog).toBeHidden();
            await expect(page.getByText('42U · 2 perangkat')).toBeVisible();
        });

        await test.step('an empty U opens the placement dialog and mounts the device there', async () => {
            await group.getByRole('button', { name: 'U20 kosong, pasang perangkat' }).click();
            const dialog = page.getByRole('dialog', { name: 'Pasang perangkat di U20' });
            await expect(dialog).toContainText(`${RACK}, 42U. U1 ada di bagian bawah rak.`);
            await selectSearchOption(page, 'Perangkat', UNMODELLED);
            await expect(dialog.getByLabel('Mulai di U')).toHaveValue('20');
            await expect(dialog.getByLabel('Tinggi (U)')).toHaveValue('1');
            await dialog.getByRole('button', { name: 'Pasang di rak' }).click();

            await expect(dialog).toBeHidden();
            await expect(mounted).toBeVisible();
            await expect(mounted).toBeFocused();
            await expect(page.getByText('42U · 3 perangkat')).toBeVisible();
            await expect(
                group.getByRole('button', { name: 'U20 kosong, pasang perangkat' }),
            ).toHaveCount(0);
        });

        await test.step('the mounted device is taken out again', async () => {
            await mounted.click();
            const dialog = page.getByRole('dialog', { name: `${UNMODELLED} di ${RACK}` });
            await expect(dialog.getByRole('link', { name: 'Buka halaman aset' })).toBeVisible();
            await dialog.getByRole('button', { name: 'Lepas dari rak' }).click();

            await expect(dialog).toBeHidden();
            await expect(mounted).toHaveCount(0);
            await expect(
                group.getByRole('button', { name: 'U20 kosong, pasang perangkat' }),
            ).toBeVisible();
            await expect(page.getByText('42U · 2 perangkat')).toBeVisible();
        });
    });

    test('trace: the OLT, the ODC, the ODP and the customer are lit as one path', async ({
        page,
    }) => {
        test.setTimeout(120_000);
        await loginAs(page, 'admin');

        await test.step('the sheet of a drop opens the path of its subscriber', async () => {
            await openPanel(page, ODP);
            const sheet = await openPort(page, 's1:1');
            await expect(sheet.getByText(SUBSCRIPTION)).toBeVisible();
            await expect(sheet.getByText(CUSTOMER)).toBeVisible();
            await expect(sheet.getByText('Status online tidak tersedia.')).toBeVisible();
            await sheet.getByRole('link', { name: 'Lacak jalur' }).click();

            await expect(page).toHaveURL(
                /\/admin\/network-assets\/trace\?path_subscription=[0-9A-HJKMNP-TV-Z]{26}$/i,
            );
            const heading = page.getByRole('heading', { name: `Jalur fiber ${SUBSCRIPTION}` });
            await expect(heading).toBeVisible();
            await expect(heading).toBeFocused();
        });

        const chain = page.getByRole('list', { name: `Jalur fiber ${SUBSCRIPTION}` });
        await test.step('one card per hop, the lit port of each, and the state in words', async () => {
            const hops = chain.getByRole('listitem');
            await expect(hops).toHaveCount(4);
            await expect(hops.nth(0).getByRole('link')).toHaveAccessibleName(
                `${OLT}, port gpon-olt_1/1/1`,
            );
            await expect(hops.nth(1).getByRole('link')).toHaveAccessibleName(
                `${ODC}, port ${ODC} · 1:8 · 1`,
            );
            await expect(hops.nth(2).getByRole('link')).toHaveAccessibleName(
                `${ODP}, port ${ODP} · 1:8 · 1`,
            );
            await expect(hops.nth(3).getByRole('link')).toHaveAccessibleName(
                new RegExp(`^${ONT}, port `),
            );

            const lit = page.locator('svg [data-lit]');
            await expect(lit).toHaveCount(4);
            expect(
                await lit.evaluateAll((nodes) =>
                    nodes.map((node) => node.getAttribute('data-lit')),
                ),
            ).toEqual(['pon1:1', 's1:1', 's1:1', 'pon:1']);
            // Without RADIUS the path is "Terpakai": its colour is the occupied one.
            const occupied = await resolved(page, 'var(--status-info)');
            for (const colour of await lit.evaluateAll((nodes) =>
                nodes.map((node) => getComputedStyle(node).color),
            )) {
                expect(colour).toBe(occupied);
            }
            await expect(page.getByRole('list', { name: 'Legenda status port' })).toContainText(
                'Terpakai',
            );
            await expect(
                page.getByText('Status online tidak tersedia: RADIUS nonaktif.'),
            ).toBeVisible();
        });
    });

    test('trace: a search result with a subscription draws the same path on demand', async ({
        page,
    }) => {
        await loginAs(page, 'admin');
        await page.goto(`/admin/network-assets/trace?search=${ONT}`);

        // The row names the customer and the subscription of the ONT and offers its path.
        const row = page.getByRole('row').filter({ hasText: ONT });
        await expect(row).toContainText(CUSTOMER);
        await expect(row).toContainText(SUBSCRIPTION);
        const heading = page.getByRole('heading', { name: `Jalur fiber ${SUBSCRIPTION}` });
        await expect(heading).toHaveCount(0);
        await row.getByRole('button', { name: `Jalur fiber ${SUBSCRIPTION}` }).click();
        await expect(heading).toBeVisible();
        await expect(
            page.getByRole('list', { name: `Jalur fiber ${SUBSCRIPTION}` }).getByRole('listitem'),
        ).toHaveCount(4);
    });

    test('staff reads the panels and has no control that writes', async ({ page }) => {
        await loginAs(page, 'staff');

        await page.goto(`/admin/network-assets/${await assetId(page, UNMODELLED)}`);
        await expect(
            page.getByText(
                'Model perangkat belum dipilih. Minta admin memilih model agar panel tampil.',
            ),
        ).toBeVisible();
        await expect(page.getByRole('button', { name: 'Terapkan model' })).toHaveCount(0);

        await openPanel(page, ROUTER);
        await expect(page.getByRole('button', { name: 'Ganti model' })).toHaveCount(0);
        const sheet = await openPort(page, 'ether:1');
        await expect(sheet.getByText('Status', { exact: true })).toBeVisible();
        await expect(sheet.getByRole('button', { name: 'Hubungkan ke port lain' })).toHaveCount(0);
        await expect(sheet.getByRole('button', { name: 'Simpan port' })).toHaveCount(0);
        await page.keyboard.press('Escape');

        // A rack shows its devices but no empty unit to mount one in.
        await openPanel(page, RACK, RACK_READY);
        await expect(page.getByRole('button', { name: `${OLT}, U10, 2U` })).toBeVisible();
        await expect(page.getByRole('button', { name: /kosong, pasang perangkat$/ })).toHaveCount(
            0,
        );
        await expect(page.getByRole('button', { name: 'Ubah ukuran' })).toHaveCount(0);
    });

    test.describe('phone 390 x 844', () => {
        test.use({ viewport: { width: 390, height: 844 }, hasTouch: true, isMobile: true });

        test('the panel scrolls sideways, the legend stays, buttons are 44 px and the sheet opens from the bottom', async ({
            page,
        }) => {
            await loginAs(page, 'admin');
            await openPanel(page, OLT);

            expect(await page.evaluate(() => matchMedia('(pointer: coarse)').matches)).toBe(true);

            // The drawing keeps its target size and scrolls inside the card; the page itself does not.
            const scroller = page.locator(`${READY} .overflow-x-auto`);
            const fits = await scroller.evaluate((element) => ({
                content: element.scrollWidth,
                visible: element.clientWidth,
            }));
            expect(fits.content, 'the panel is wider than the card').toBeGreaterThan(
                fits.visible + 100,
            );
            expect(
                await page.evaluate(() => document.documentElement.scrollWidth),
                'the page does not scroll sideways',
            ).toBeLessThanOrEqual(390);
            const smallest = await page.locator('[data-port-button]').evaluateAll((buttons) =>
                Math.min(
                    ...buttons.map((button) => {
                        const box = button.getBoundingClientRect();
                        return Math.min(box.width, box.height);
                    }),
                ),
            );
            expect(smallest, 'every port button is a 44 px target').toBeGreaterThanOrEqual(43.5);

            // The legend is outside the scroller, so scrolling the drawing never takes it along.
            const legend = page.getByRole('list', { name: 'Legenda status port' });
            await scroller.evaluate((element) => element.scrollTo({ left: element.scrollWidth }));
            const legendBox = (await legend.boundingBox())!;
            expect(legendBox.x).toBeGreaterThanOrEqual(0);
            expect(legendBox.x + legendBox.width).toBeLessThanOrEqual(390);
            expect(
                await legend.evaluate((element) => element.closest('.overflow-x-auto') === null),
            ).toBe(true);
            // Its wrapper sticks to the top of the panel on a phone (max-sm:sticky).
            await expect(legend.locator('xpath=..')).toHaveCSS('position', 'sticky');

            await port(page, 'uplink:1').tap();
            const sheet = page.getByRole('dialog', { name: 'UP1' });
            await expect(sheet).toBeVisible();
            // The sheet slides up from the bottom edge and spans the width.
            await expect
                .poll(async () => {
                    const box = (await sheet.boundingBox())!;
                    return Math.abs(box.y + box.height - 844);
                })
                .toBeLessThanOrEqual(1);
            const box = (await sheet.boundingBox())!;
            expect(box.x).toBeLessThanOrEqual(1);
            expect(box.width).toBeGreaterThanOrEqual(388);
            expect(box.y, 'a sheet, not a full page').toBeGreaterThan(100);
        });
    });
});

test.describe('@hardware-panel hardware panel online state (FreeRADIUS lab)', () => {
    test.skip(!LAB, 'Opt-in: set E2E_RADIUS_LAB=1 with the lab up (see the spec header).');
    test.describe.configure({ mode: 'serial' });

    const compose = (...args: string[]) =>
        execFileSync(
            'docker',
            ['--context', 'default', 'compose', '-f', join(LAB_DIR, 'compose.yaml'), ...args],
            {
                encoding: 'utf8',
            },
        );
    /** One accounting packet for the subscriber of the fixtures, sent from inside the lab's FreeRADIUS container. */
    const accounting = (status: 'Start' | 'Stop') =>
        compose(
            'exec',
            '-T',
            'freeradius',
            'sh',
            '-c',
            `printf "User-Name=${ACCOUNT},Acct-Status-Type=${status},Acct-Session-Id=e2e-panel-1,NAS-IP-Address=127.0.0.1" | radclient 127.0.0.1:1813 acct "$RADIUS_SECRET"`,
        );
    const forget = () =>
        compose(
            'exec',
            '-T',
            'radius-db',
            'sh',
            '-c',
            `MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot radius -e "delete from radacct where username = '${ACCOUNT}'"`,
        );

    test.beforeAll(() => {
        forget();
        accounting('Start');
    });

    test.afterAll(() => {
        forget();
    });

    test('an open session makes the subscriber online on the OLT, the ODP and the path', async ({
        page,
    }) => {
        test.setTimeout(150_000);
        await loginAs(page, 'admin');

        await openPanel(page, OLT);
        await expect(port(page, 'pon1:1')).toHaveAttribute('data-state', 'online');
        await expect(port(page, 'pon1:1')).toHaveAccessibleName(
            `gpon-olt_1/1/1: Online, ke ${ODC} 1:8, 2 pelanggan, 1 online`,
        );
        await expect(
            page.getByText('Online = sesi RADIUS terbuka, diperbarui paling lama tiap 15 detik.'),
        ).toBeVisible();

        await openPanel(page, ODP);
        await expect(port(page, 's1:1')).toHaveAttribute('data-state', 'online');
        await expect(port(page, 's1:1')).toHaveAccessibleName(
            `1: Online, pelanggan ${SUBSCRIPTION} ${CUSTOMER}`,
        );
        // Isolir wins over everything else, and the suspended subscriber has no session.
        await expect(port(page, 's1:2')).toHaveAttribute('data-state', 'isolir');

        // Design 5.5: the online colour is --status-success, #15803d in light and #34d399 in dark.
        expect(await colourOf(glyph(page, 's1:1'))).toBe('rgb(21, 128, 61)');
        await setTheme(page, true);
        expect(await colourOf(glyph(page, 's1:1'))).toBe('rgb(52, 211, 153)');
        await setTheme(page, false);

        const sheet = await openPort(page, 's1:1');
        await expect(sheet.getByText(/^Online sejak /)).toBeVisible();
        await sheet.getByRole('link', { name: 'Lacak jalur' }).click();
        await expect(
            page.getByRole('heading', { name: `Jalur fiber ${SUBSCRIPTION}` }),
        ).toBeVisible();
        await expect(page.getByRole('list', { name: 'Legenda status port' })).toContainText(
            'Online',
        );
        await expect(page.getByText('Status online tidak tersedia')).toHaveCount(0);
    });

    test('once the session ends the subscriber is offline, not free', async ({ page }) => {
        test.setTimeout(150_000);
        accounting('Stop');
        await loginAs(page, 'admin');
        await openPanel(page, ODP);

        // The online set is cached for 15 seconds: reload until the panel has the stop.
        await expect
            .poll(
                async () => {
                    await page.reload();
                    await expect(page.locator(READY)).toBeVisible({ timeout: 20_000 });
                    return port(page, 's1:1').getAttribute('data-state');
                },
                { timeout: 60_000, intervals: [4_000] },
            )
            .toBe('offline');
    });
});
