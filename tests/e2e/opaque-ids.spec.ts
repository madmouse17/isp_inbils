/**
 * @opaque-ids Records are addressed by an opaque ULID public id, never by the auto-increment id
 *
 * Coverage (.scratch/opaque-ids/spec.md, K2 and K3):
 * - the main lists link every record by ULID: no link in the rendered page and no URL in the page props has an
 *   integer path segment
 * - a record URL with an integer id answers 404, the same as a well-formed id nobody owns
 * - a record opens through the ULID its list link carries
 *
 * Deterministic: ids are read from the seeded rows and the pages, no fixed ids.
 */
import { expect, test } from './support/fixtures';
import { e2eCustomerName, inertiaProps, loginAs } from './support/demo';

const ULID = '[0-9A-HJKMNP-TV-Z]{26}';
const ULID_PATH = new RegExp(`/${ULID}(/|$)`, 'i');
/** A path segment of digits only: the auto-increment id the server no longer exposes. */
const INTEGER_PATH = /\/\d+(\/|$)/;
/** Well-formed, owned by nobody. */
const UNKNOWN_ULID = '01arz3ndektsv4rrffq69g5fav';
const SEEDED_CUSTOMER_SEARCH = `?search=${encodeURIComponent('E2E-LINKED-CUST')}`;

/** Sidebar lists whose rows open a record. `seeded`: the E2E reset always leaves rows in the list. */
const lists: Array<{ path: string; seeded: boolean }> = [
    { path: '/admin/customers', seeded: true },
    { path: '/admin/users', seeded: true },
    { path: '/admin/roles', seeded: true },
    { path: '/admin/products', seeded: true },
    { path: '/admin/network-assets', seeded: true },
    { path: '/admin/invoices', seeded: false },
    { path: '/admin/spk', seeded: false },
    { path: '/admin/tickets', seeded: false },
    { path: '/admin/evaluations', seeded: false },
    { path: '/admin/nas-devices', seeded: false },
    { path: '/admin/inventory/requests', seeded: false },
    { path: '/admin/service-packages', seeded: false },
    { path: '/admin/ipam/prefixes', seeded: false },
    { path: '/admin/odn', seeded: false },
];

/** Record pages (GET). `{id}` is the record. */
const recordPages = [
    '/admin/customers/{id}',
    '/admin/customers/{id}/edit',
    '/admin/subscriptions/{id}',
    '/admin/invoices/{id}',
    '/admin/spk/{id}',
    '/admin/tickets/{id}',
    '/admin/users/{id}',
    '/admin/roles/{id}',
    '/admin/products/{id}',
    '/admin/network-assets/{id}',
    '/admin/nas-devices/{id}',
    '/admin/inventory/requests/{id}',
    '/admin/service-packages/{id}',
    '/admin/ipam/prefixes/{id}',
    '/admin/odn/assets/{id}',
    '/admin/media/{id}',
];

/** Every string of the page props that is a path or a URL. */
function urlStrings(value: unknown): string[] {
    if (typeof value === 'string') return /^(\/|https?:\/\/)/.test(value) ? [value] : [];
    if (Array.isArray(value)) return value.flatMap(urlStrings);
    if (typeof value === 'object' && value !== null) {
        return Object.values(value as Record<string, unknown>).flatMap(urlStrings);
    }

    return [];
}

/** The same-origin admin paths among `urls` that carry an integer id (static files such as `/storage/…` are not records). */
function integerPaths(urls: string[], origin: string): string[] {
    return urls.flatMap((url) => {
        const { origin: linkOrigin, pathname } = new URL(url, origin);

        return linkOrigin === origin &&
            pathname.startsWith('/admin/') &&
            INTEGER_PATH.test(pathname)
            ? [pathname]
            : [];
    });
}

test('@opaque-ids the main lists link records by ULID, never by integer id', async ({
    page,
    baseURL,
}) => {
    const origin = new URL(baseURL!).origin;
    await loginAs(page, 'admin');

    const integerLinks: string[] = [];

    for (const list of lists) {
        const { props } = await inertiaProps(page, list.path);
        integerLinks.push(
            ...integerPaths(urlStrings(props), origin).map((path) => `${list.path} props: ${path}`),
        );

        await page.goto(list.path);
        await expect(page.getByRole('main')).toBeVisible({ timeout: 15_000 });

        // The rows render after the page loads: a seeded list is read once its first record link is there.
        let links: string[] = [];
        await expect
            .poll(
                async () => {
                    links = await page
                        .getByRole('main')
                        .locator('a[href]')
                        .evaluateAll((anchors) =>
                            anchors.map((anchor) => (anchor as HTMLAnchorElement).href),
                        );

                    return (
                        !list.seeded || links.some((url) => ULID_PATH.test(new URL(url).pathname))
                    );
                },
                { message: `${list.path} links its rows by ULID`, timeout: 15_000 },
            )
            .toBe(true);
        integerLinks.push(
            ...integerPaths(links, origin).map((path) => `${list.path} link: ${path}`),
        );
    }

    expect(integerLinks, 'record links with an integer id').toEqual([]);
});

test('@opaque-ids a record URL with an integer id answers 404, like an id nobody owns', async ({
    page,
}) => {
    await loginAs(page, 'admin');

    // Control: the route opens for a real record by its ULID, so the 404s below come from the id, not the route.
    const { props } = await inertiaProps(page, `/admin/customers${SEEDED_CUSTOMER_SEARCH}`);
    const customerId = (props?.customers?.data as Array<{ id: string }> | undefined)?.[0]?.id;
    expect(customerId, 'the seeded customer id in the list props').toMatch(
        new RegExp(`^${ULID}$`, 'i'),
    );
    expect((await page.request.get(`/admin/customers/${customerId}`)).status()).toBe(200);

    for (const template of recordPages) {
        const byInteger = await page.request.get(template.replace('{id}', '1'));
        const byUnknown = await page.request.get(template.replace('{id}', UNKNOWN_ULID));

        expect(byInteger.status(), `GET ${template} with an integer id`).toBe(404);
        expect(byUnknown.status(), `GET ${template} with an unknown ULID`).toBe(404);
    }
});

test('@opaque-ids a customer opens through the ULID in its list link', async ({ page }) => {
    await loginAs(page, 'admin');

    await page.goto(`/admin/customers${SEEDED_CUSTOMER_SEARCH}`);
    await page.getByRole('link', { name: e2eCustomerName }).first().click();

    await expect(page).toHaveURL(new RegExp(`/admin/customers/${ULID}$`, 'i'));
    await expect(page.getByText(e2eCustomerName).first()).toBeVisible();
});
