import { expect, test } from './support/fixtures';
import { loginAs } from './support/demo';

test('@kanban-t_40cf97ea admin location map is first-class with empty markers and tile fallback', async ({
    page,
}) => {
    await page.route('**/admin/locations**', async (route) => {
        const response = await route.fetch();
        const html = await response.text();
        const match = html.match(/data-page="([^"]*)"/);

        if (!match) {
            await route.fulfill({ response, body: html });
            return;
        }

        const pageData = JSON.parse(decodeInertiaAttribute(match[1])) as {
            props?: { markers?: unknown };
        };
        pageData.props = { ...pageData.props, markers: [] };

        await route.fulfill({
            response,
            body: html.replace(
                match[0],
                `data-page="${encodeInertiaAttribute(JSON.stringify(pageData))}"`,
            ),
        });
    });
    const tinyPng = Buffer.from(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
        'base64',
    );
    let osmTileRequests = 0;
    let abortedOsmTileRequests = 0;
    await page.route(
        /^https:\/\/[abc]\.tile\.openstreetmap\.org\/.*\.png(?:\?.*)?$/,
        async (route) => {
            osmTileRequests += 1;

            if (osmTileRequests === 1) {
                abortedOsmTileRequests += 1;
                await route.abort();
                return;
            }

            await route.fulfill({
                status: 200,
                contentType: 'image/png',
                body: tinyPng,
            });
        },
    );

    await loginAs(page, 'admin');
    await page.goto('/admin/locations');

    const map = page.locator('.leaflet-container');
    const petaTab = page.getByRole('tab', { name: 'Peta' });

    await expect(petaTab).toHaveAttribute('aria-selected', 'true');
    await expect(map).toBeVisible();
    await expect(map.locator('.leaflet-control-attribution')).toContainText('OpenStreetMap');
    await expect(page.getByText('0 lokasi', { exact: true })).toBeVisible();
    await expect(page.getByText('Belum ada lokasi dengan koordinat.')).toHaveCount(0);

    await expect(page.getByRole('alert')).toContainText('Lapisan peta tidak dapat dimuat');
    await expect(page.getByRole('alert').getByRole('button', { name: 'Coba lagi' })).toBeVisible();
    expect(abortedOsmTileRequests).toBe(1);

    const mapBounds = await map.boundingBox();
    expect(mapBounds).not.toBeNull();
    await map.click({
        position: {
            x: Math.max(1, Math.round(mapBounds!.width - 80)),
            y: Math.round(mapBounds!.height / 2),
        },
    });

    await page.getByRole('button', { name: 'Tambah POP' }).click();
    const createDialog = page.getByRole('dialog', { name: 'Tambah Lokasi' });
    await expect(createDialog).toBeVisible();
    await expect(createDialog.getByLabel('Latitude')).toHaveValue(/^-?\d+\.\d{7}$/);
    await expect(createDialog.getByLabel('Longitude')).toHaveValue(/^-?\d+\.\d{7}$/);
});

test('@kanban-t_40cf97ea marker edit uses the authoritative tree row', async ({ page }) => {
    const expectedAddress = 'Authoritative marker address';
    let expectedParentId: number | null = null;
    let expectedCode = '';
    let expectedName = '';
    let expectedMarkerAriaLabel = '';

    await page.route('**/admin/locations**', async (route) => {
        if (route.request().method() !== 'GET') {
            await route.continue();
            return;
        }

        const response = await route.fetch();
        const html = await response.text();
        const match = html.match(/data-page="([^"]*)"/);

        if (!match) {
            await route.fulfill({ response, body: html });
            return;
        }

        const pageData = JSON.parse(decodeInertiaAttribute(match[1])) as {
            props?: {
                tree?: { data?: LocationRow[] } | LocationRow[];
                markers?: unknown;
            };
        };
        const treeSource = pageData.props?.tree;
        const tree = Array.isArray(treeSource) ? treeSource : (treeSource?.data ?? []);
        const target = flattenRows(tree).find((row) => row.parent_id !== null);

        if (!target) {
            throw new Error('Expected an E2E location with a parent.');
        }

        target.address = expectedAddress;
        expectedParentId = target.parent_id;
        expectedCode = target.code;
        expectedName = target.name;
        expectedMarkerAriaLabel =
            target.type === 'pop'
                ? 'POP'
                : target.type.charAt(0).toUpperCase() + target.type.slice(1);
        pageData.props = {
            ...pageData.props,
            tree: Array.isArray(treeSource) ? tree : { ...treeSource, data: tree },
            markers: [
                {
                    id: target.id,
                    code: target.code,
                    name: target.name,
                    type: target.type,
                    lat: '-6.1234567',
                    lng: '106.7654321',
                    is_active: target.is_active,
                },
            ],
        };

        await route.fulfill({
            response,
            body: html.replace(
                match[0],
                `data-page="${encodeInertiaAttribute(JSON.stringify(pageData))}"`,
            ),
        });
    });

    await loginAs(page, 'admin');
    await page.goto('/admin/locations');

    const map = page.locator('.leaflet-container');
    const savedMarker = map.locator(
        `.leaflet-marker-icon:has(svg[aria-label="${expectedMarkerAriaLabel}"])`,
    );
    await expect(savedMarker).toHaveCount(1);
    await expect(map.locator('.leaflet-marker-icon svg[aria-label="Draft location"]')).toHaveCount(
        0,
    );
    await savedMarker.click();
    const popup = page.locator('.locations-map-popup');
    await expect(popup).toBeVisible();
    await expect(popup).toContainText(expectedCode);
    await expect(popup).toContainText(expectedName);
    await popup.getByRole('button', { name: 'Edit' }).click();

    const editDialog = page.getByRole('dialog', { name: /Edit/ });
    await expect(editDialog).toBeVisible();
    await expect(editDialog.getByLabel('Address')).toHaveValue(expectedAddress);
    await expect(editDialog.getByLabel('Parent')).toHaveValue(String(expectedParentId));
    await editDialog.getByLabel('Latitude').fill('-6.2345678');
    await editDialog.getByLabel('Longitude').fill('106.8765432');

    const updateRequest = page.waitForRequest((request) => {
        if (!request.url().match(/\/admin\/locations\/\d+$/)) return false;
        if (request.method() === 'PUT') return true;

        const postData = request.postData() ?? '';
        return request.method() === 'POST' && /(?:^|&)__?method=put(?:&|$)/i.test(postData);
    });

    await editDialog.getByRole('button', { name: 'Simpan & Konfirmasi Koordinat' }).click();
    const request = await updateRequest;
    const payload = readRequestPayload(request.postData() ?? '');

    expect(payload.address).toBe(expectedAddress);
    expect(payload.parent_id).toBe(String(expectedParentId));
    expect(payload.lat).toBe('-6.2345678');
    expect(payload.lng).toBe('106.8765432');
});

interface LocationRow {
    id: number;
    parent_id: number | null;
    code: string;
    name: string;
    type: 'region' | 'area' | 'pop' | 'rack' | 'site';
    address: string | null;
    is_active: boolean;
    children: LocationRow[];
}

function flattenRows(rows: LocationRow[]): LocationRow[] {
    return rows.flatMap((row) => [row, ...flattenRows(row.children ?? [])]);
}

function readRequestPayload(body: string): Record<string, string> {
    try {
        return Object.fromEntries(
            Object.entries(JSON.parse(body) as Record<string, unknown>).map(([key, value]) => [
                key,
                String(value ?? ''),
            ]),
        );
    } catch {
        return Object.fromEntries(new URLSearchParams(body).entries());
    }
}

function decodeInertiaAttribute(value: string): string {
    return value
        .replace(/&quot;/g, '"')
        .replace(/&#039;/g, "'")
        .replace(/&amp;/g, '&')
        .replace(/&lt;/g, '<')
        .replace(/&gt;/g, '>');
}

function encodeInertiaAttribute(value: string): string {
    return value
        .replace(/&/g, '&amp;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
}
