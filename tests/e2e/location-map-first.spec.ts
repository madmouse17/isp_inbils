import { expect, test } from './support/fixtures';
import { inertiaProps, loginAs } from './support/demo';

/** The words of `LOCATION_TYPE` (lib/statuses/location.ts): a marker is named by its type. */
const TYPE_LABELS = {
    region: 'Wilayah',
    area: 'Area',
    pop: 'POP',
    rack: 'Rak',
    site: 'Site',
} as const;

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
    const createDialog = page.getByRole('dialog', { name: 'Buat Lokasi' });
    await expect(createDialog).toBeVisible();
    await expect(createDialog.getByLabel('Lintang')).toHaveValue(/^-?\d+\.\d{7}$/);
    await expect(createDialog.getByLabel('Bujur')).toHaveValue(/^-?\d+\.\d{7}$/);
});

test('@kanban-t_40cf97ea marker edit uses the authoritative tree row', async ({ page }) => {
    const expectedAddress = 'Authoritative marker address';
    let expectedParentId: string | null = null;
    let expectedCode = '';
    let expectedName = '';
    let expectedMarkerAriaLabel = '';
    let expectedParentPath = '';

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
        expectedMarkerAriaLabel = TYPE_LABELS[target.type];
        expectedParentPath =
            flattenRows(tree).find((row) => row.id === target.parent_id)?.path ?? '';
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
    await expect(
        map.locator('.leaflet-marker-icon svg[aria-label="Lokasi baru, belum disimpan"]'),
    ).toHaveCount(0);

    await savedMarker.click();
    const popup = page.locator('.locations-map-popup');
    await expect(popup).toBeVisible();
    await expect(popup).toContainText(expectedCode);
    await expect(popup).toContainText(expectedName);
    await popup.getByRole('button', { name: 'Ubah' }).click();

    const editDialog = page.getByRole('dialog', { name: /Ubah/ });
    await expect(editDialog).toBeVisible();
    await expect(editDialog.getByLabel('Alamat')).toHaveValue(expectedAddress);
    // The parent is a searchable field: it shows the path of the parent.
    expect(expectedParentPath, 'path of the parent row').not.toBe('');
    await expect(editDialog.getByRole('combobox', { name: 'Induk' })).toHaveValue(
        expectedParentPath,
    );
    await editDialog.getByLabel('Lintang').fill('-6.2345678');
    await editDialog.getByLabel('Bujur').fill('106.8765432');

    const updateRequest = page.waitForRequest((request) => {
        if (!request.url().match(/\/admin\/locations\/[0-9A-HJKMNP-TV-Z]{26}$/i)) return false;
        if (request.method() === 'PUT') return true;

        const postData = request.postData() ?? '';
        return request.method() === 'POST' && /(?:^|&)__?method=put(?:&|$)/i.test(postData);
    });

    await editDialog.getByRole('button', { name: 'Simpan dan konfirmasi koordinat' }).click();
    const request = await updateRequest;
    const payload = readRequestPayload(request.postData() ?? '');

    expect(payload.address).toBe(expectedAddress);
    expect(payload.parent_id).toBe(String(expectedParentId));
    expect(payload.lat).toBe('-6.2345678');
    expect(payload.lng).toBe('106.8765432');
});

interface LocationRow {
    id: string;
    parent_id: string | null;
    code: string;
    name: string;
    type: 'region' | 'area' | 'pop' | 'rack' | 'site';
    path: string | null;
    address: string | null;
    is_active: boolean;
    children: LocationRow[];
}

function flattenRows(rows: LocationRow[]): LocationRow[] {
    return rows.flatMap((row) => [row, ...flattenRows(row.children ?? [])]);
}

test('@ui-consistency the Tabel tab lists every location and keeps Hapus off while a location has locations under it', async ({
    page,
}) => {
    // Regression: the table read `row.children.length` from rows that carry no children and crashed for anyone who may delete.
    const pageErrors: string[] = [];
    page.on('pageerror', (error) => pageErrors.push(error.message));

    await loginAs(page, 'admin');
    const props = (await inertiaProps(page, '/admin/locations?per_page=50')).props as {
        tree: { data: LocationRow[] };
        locations: { data: Array<{ id: string; code: string }> };
    };
    const parentIds = new Set(
        flattenRows(props.tree.data).flatMap((row) =>
            row.parent_id === null ? [] : [row.parent_id],
        ),
    );
    const parent = props.locations.data.find((row) => parentIds.has(row.id));
    const leaf = props.locations.data.find((row) => !parentIds.has(row.id));
    expect(parent, 'the table lists a location with locations under it').toBeDefined();
    expect(leaf, 'the table lists a location without any').toBeDefined();

    await page.goto('/admin/locations?per_page=50');
    await page.getByRole('tab', { name: 'Tabel' }).click();

    const rowOf = (code: string) =>
        page.getByRole('row').filter({ has: page.getByText(code, { exact: true }) });
    await expect(rowOf(parent!.code)).toBeVisible();
    await expect(rowOf(parent!.code).getByRole('button', { name: 'Hapus' })).toBeDisabled();
    await expect(rowOf(leaf!.code).getByRole('button', { name: 'Hapus' })).toBeEnabled();
    expect(pageErrors).toEqual([]);
});

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
