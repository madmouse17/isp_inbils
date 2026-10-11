/**
 * Former scaffold API paths (internal to test setup; P1-C non-exposure contract).
 *
 * Kept in a separate module so Playwright's failure diagnostics — which dump the
 * spec file's own source into error-context.md — never reproduce these exact
 * route strings. Test names and assertion messages use sanitized labels only.
 */
export const FORMER_SCAFFOLD_API_PATHS = [
    '/api/v1/billings',
    '/api/v1/customers',
    '/api/v1/inventories',
    '/api/v1/networkassets',
    '/api/v1/reportings',
    '/api/v1/services',
    '/api/v1/spks',
    '/api/v1/ticketings',
];
