/**
 * Node selfcheck for the status maps (`lib/statuses`): their shape, the lookup helpers, and that
 * every map lists exactly the values the backend stores. The backend sources are read from the
 * repo, so a value added or renamed there fails here until the map follows.
 * Run: node resources/js/lib/statuses.selfcheck.mjs
 *
 * Expected values are literals, never computed with the code under test.
 */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { registerHooks } from 'node:module';
import { fileURLToPath } from 'node:url';
import test from 'node:test';

// The libs import each other without extensions, the way Vite resolves them; teach Node the .ts.
registerHooks({
    resolve: (specifier, context, next) =>
        next(/^\.\.?\/[^.]+$/.test(specifier) ? `${specifier}.ts` : specifier, context),
});

const statuses = await import('./statuses/index.ts');
const { labelOf, optionsOf, resolveStatus, toneColor } = statuses;

const TONES = ['success', 'info', 'warning', 'danger', 'neutral'];
const maps = Object.entries(statuses).filter(([, value]) => typeof value === 'object');
const read = (path) =>
    readFileSync(fileURLToPath(new URL(`../../../${path}`, import.meta.url)), 'utf8');
const keysOf = (map) => Object.keys(map).sort();

test('every map is a list of labels with a known tone', () => {
    assert.ok(maps.length >= 40, `expected the domain maps, found ${maps.length}`);
    for (const [name, map] of maps) {
        const entries = Object.entries(map);
        assert.ok(entries.length > 0, `${name} is empty`);
        for (const [value, entry] of entries) {
            assert.deepEqual(Object.keys(entry).sort(), ['label', 'tone'], `${name}.${value}`);
            assert.ok(TONES.includes(entry.tone), `${name}.${value} tone ${entry.tone}`);
        }
    }
});

test('labels are short Indonesian text: sentence case, no codes, no dashes, no stop', () => {
    for (const [name, map] of maps) {
        for (const [value, { label }] of Object.entries(map)) {
            const where = `${name}.${value} "${label}"`;
            assert.equal(label, label.trim(), where);
            assert.match(label, /^[A-Z0-9]/, `${where} starts with a capital`);
            assert.doesNotMatch(label, /[_—–]/, `${where} has a code character or a dash`);
            assert.doesNotMatch(label, /\.$/, `${where} ends with a full stop`);
            assert.ok(label.length <= 32, `${where} is too long for a badge`);
            assert.notEqual(label, value.replaceAll('_', ' '), `${where} is the raw code`);
        }
    }
});

test('the words decided with the owner are the words the maps use', () => {
    const fixed = [
        ['SUBSCRIPTION_STATUS', 'active', 'Aktif', 'success'],
        ['SUBSCRIPTION_STATUS', 'pending', 'Menunggu', 'warning'],
        ['SUBSCRIPTION_STATUS', 'suspended', 'Ditangguhkan', 'warning'],
        ['SUBSCRIPTION_STATUS', 'terminated', 'Berhenti', 'neutral'],
        ['WORK_ORDER_STATUS', 'in_progress', 'Dikerjakan', 'info'],
        ['WORK_ORDER_STATUS', 'waiting_review', 'Menunggu review', 'warning'],
        ['WORK_ORDER_STATUS', 'completed', 'Selesai', 'success'],
        ['WORK_ORDER_STATUS', 'cancelled', 'Dibatalkan', 'neutral'],
        ['TICKET_STATUS', 'open', 'Terbuka', 'info'],
        ['TICKET_STATUS', 'assigned', 'Ditugaskan', 'info'],
        ['TICKET_STATUS', 'on_progress', 'Dikerjakan', 'info'],
        ['TICKET_STATUS', 'resolved', 'Diselesaikan', 'success'],
        ['TICKET_STATUS', 'closed', 'Ditutup', 'neutral'],
        ['INVOICE_STATUS', 'sent', 'Belum dibayar', 'info'],
        ['INVOICE_STATUS', 'paid', 'Lunas', 'success'],
        ['INVOICE_STATUS', 'partial', 'Sebagian', 'warning'],
        ['INVOICE_STATUS', 'overdue', 'Lewat jatuh tempo', 'danger'],
        ['CUSTOMER_TYPE', 'Individual', 'Perorangan', 'neutral'],
        ['CUSTOMER_TYPE', 'Company', 'Perusahaan', 'neutral'],
        ['PRIORITY', 'urgent', 'Mendesak', 'danger'],
    ];
    for (const [name, value, label, tone] of fixed) {
        assert.deepEqual(statuses[name][value], { label, tone }, `${name}.${value}`);
    }
});

test('resolveStatus: a listed value, an unlisted one, and no value', () => {
    assert.deepEqual(resolveStatus(statuses.TICKET_STATUS, 'on_progress'), {
        label: 'Dikerjakan',
        tone: 'info',
        known: true,
    });
    // A status the server adds before the map learns it shows as itself, neutral, and never throws.
    assert.deepEqual(resolveStatus(statuses.TICKET_STATUS, 'escalated'), {
        label: 'escalated',
        tone: 'neutral',
        known: false,
    });
    for (const none of [null, undefined, '']) {
        assert.equal(resolveStatus(statuses.TICKET_STATUS, none), null, String(none));
    }
});

test('resolveStatus does not read the members of the map object', () => {
    for (const key of ['constructor', '__proto__', 'toString', 'hasOwnProperty', 'valueOf']) {
        assert.deepEqual(
            resolveStatus(statuses.TICKET_STATUS, key),
            { label: key, tone: 'neutral', known: false },
            key,
        );
    }
});

test('resolveStatus takes a boolean or a number as the key it is stored under', () => {
    assert.equal(resolveStatus(statuses.ACTIVE_STATE, true).label, 'Aktif');
    assert.equal(resolveStatus(statuses.ACTIVE_STATE, false).label, 'Nonaktif');
    assert.equal(resolveStatus(statuses.ACTIVE_STATE, false).tone, 'neutral');
    assert.equal(resolveStatus(statuses.IP_VERSION, 4).label, 'IPv4');
    assert.equal(resolveStatus(statuses.IP_VERSION, 6).label, 'IPv6');
    assert.equal(resolveStatus(statuses.NETWORK_ONLINE_STATE, true).tone, 'success');
    // Zero and false are values, not "no value".
    assert.notEqual(resolveStatus({ 0: { label: 'Nol', tone: 'neutral' } }, 0), null);
    assert.notEqual(resolveStatus(statuses.ACTIVE_STATE, false), null);
});

test('labelOf is the label as text, "-" for no value, the value itself when unlisted', () => {
    assert.equal(labelOf(statuses.PRIORITY, 'high'), 'Tinggi');
    assert.equal(labelOf(statuses.PRIORITY, 'critical'), 'critical');
    assert.equal(labelOf(statuses.PRIORITY, null), '-');
    assert.equal(labelOf(statuses.PRIORITY, ''), '-');
});

test('optionsOf lists the map in its own order, for a filter or a select', () => {
    assert.deepEqual(optionsOf(statuses.PRIORITY), [
        { value: 'low', label: 'Rendah' },
        { value: 'medium', label: 'Sedang' },
        { value: 'high', label: 'Tinggi' },
        { value: 'urgent', label: 'Mendesak' },
    ]);
    assert.deepEqual(optionsOf(statuses.CUSTOMER_TYPE), [
        { value: 'Individual', label: 'Perorangan' },
        { value: 'Company', label: 'Perusahaan' },
    ]);
    assert.deepEqual(optionsOf({}), []);
    assert.deepEqual(
        optionsOf(statuses.INVOICE_STATUS).map((option) => option.value),
        ['draft', 'sent', 'partial', 'paid', 'overdue', 'cancelled'],
    );
});

test('toneColor names the --status-* variable of each tone', () => {
    for (const tone of TONES) {
        assert.equal(toneColor(tone), `var(--status-${tone})`);
    }
});

test('WORK_ITEM_STATUS merges three sets that agree on every shared value', () => {
    const merged = statuses.WORK_ITEM_STATUS;
    const parts = [
        statuses.INVENTORY_REQUEST_STATUS,
        statuses.TICKET_STATUS,
        statuses.WORK_ORDER_STATUS,
    ];
    for (const part of parts) {
        for (const [value, entry] of Object.entries(part)) {
            assert.deepEqual(merged[value], entry, `${value} must say the same in every set`);
        }
    }
    assert.deepEqual(
        keysOf(merged),
        [...new Set(parts.flatMap((part) => Object.keys(part)))].sort(),
    );
});

/** `'a', 'b'` of a PHP array literal body. */
const quoted = (body) => [...body.matchAll(/'([^']+)'/g)].map((match) => match[1]).sort();

/** The values of `'table' => [... 'column' => ['values' => [...]]` in the closed-values migration. */
function migrationValues(table, column) {
    const source = read(
        'database/migrations/2026_08_11_163004_enforce_latest_closed_values_as_enums.php',
    );
    const pattern = new RegExp(
        `'${table}'\\s*=>\\s*\\[[\\s\\S]*?'${column}'\\s*=>\\s*\\['values'\\s*=>\\s*\\[([^\\]]*)\\]`,
    );
    const match = pattern.exec(source);
    assert.ok(match, `${table}.${column} not found in the closed-values migration`);

    return quoted(match[1]);
}

/** The items of `const NAME = [...]` in a PHP file. */
function phpConstant(path, name) {
    const match = new RegExp(`const ${name}\\s*=\\s*\\[([^\\]]*)\\]`).exec(read(path));
    assert.ok(match, `${name} not found in ${path}`);

    return quoted(match[1]);
}

test('maps list exactly the values the database enums hold', () => {
    const pairs = [
        ['CUSTOMER_TYPE', 'customers', 'type'],
        ['SUBSCRIPTION_STATUS', 'service_subscriptions', 'status'],
        ['INVOICE_STATUS', 'invoices', 'status'],
        ['INVOICE_TYPE', 'invoices', 'type'],
        ['INVOICE_SOURCE', 'invoices', 'source'],
        ['WORK_ORDER_STATUS', 'work_orders', 'status'],
        ['WORK_ORDER_TYPE', 'work_orders', 'type'],
        ['PRIORITY', 'work_orders', 'priority'],
        ['PRIORITY', 'tickets', 'priority'],
        ['PRIORITY', 'ticket_categories', 'default_priority'],
        ['TICKET_STATUS', 'tickets', 'status'],
        ['TICKET_SOURCE', 'tickets', 'source'],
        ['EMPLOYEE_STATUS', 'employee_profiles', 'status'],
        ['ORGANIZATION_TYPE', 'organization_units', 'type'],
        ['PRODUCT_TYPE', 'products', 'type'],
        ['STOCK_MOVEMENT_TYPE', 'stock_movements', 'movement_type'],
        ['NETWORK_ASSET_STATUS', 'network_assets', 'status'],
        ['NETWORK_ASSET_TYPE', 'network_assets', 'asset_type'],
        ['NETWORK_ASSET_OWNERSHIP', 'network_assets', 'ownership'],
        ['LOCATION_TYPE', 'locations', 'type'],
    ];
    for (const [name, table, column] of pairs) {
        assert.deepEqual(
            keysOf(statuses[name]),
            migrationValues(table, column),
            `${name} vs ${table}.${column}`,
        );
    }
    // Payments gained `midtrans` after the migration above; staff still record the first four.
    assert.deepEqual(
        keysOf(statuses.PAYMENT_METHOD),
        [...migrationValues('payments', 'method'), 'midtrans'].sort(),
    );
});

test('maps list exactly the values the models and controllers name', () => {
    assert.deepEqual(
        keysOf(statuses.CUSTOMER_STATUS),
        phpConstant('app/Models/Core/Customer.php', 'STATUSES').sort(),
    );
    assert.deepEqual(
        keysOf(statuses.IP_PREFIX_USAGE),
        phpConstant('Modules/Ipam/app/Models/IpPrefix.php', 'USAGES').sort(),
    );
    assert.deepEqual(
        keysOf(statuses.WHATSAPP_STATUS),
        phpConstant('app/Models/Core/WhatsAppMessage.php', 'STATUSES').sort(),
    );
    assert.deepEqual(
        keysOf(statuses.WHATSAPP_SKIP_REASON),
        phpConstant('app/Models/Core/WhatsAppMessage.php', 'SKIP_REASONS').sort(),
    );
    assert.deepEqual(
        keysOf(statuses.INVENTORY_REQUEST_STATUS),
        phpConstant(
            'Modules/Inventory/app/Http/Controllers/Api/V1/Technician/InventoryRequestController.php',
            'STATUSES',
        ).sort(),
    );
    assert.deepEqual(
        keysOf(statuses.MATERIAL_RETURN_STATUS),
        phpConstant(
            'Modules/Inventory/app/Http/Controllers/Api/V1/Technician/MaterialReturnController.php',
            'STATUSES',
        ).sort(),
    );
});

test('maps list exactly the cases and constants of the remaining backend sources', () => {
    const resetCases = [
        ...read('app/Support/NumberSequences/ResetPeriod.php').matchAll(/case \w+ = '(\w+)'/g),
    ].map((match) => match[1]);
    assert.deepEqual(keysOf(statuses.RESET_PERIOD), resetCases.sort());

    const attempt = read('Modules/Billing/app/Models/PaymentAttempt.php');
    const attemptStatuses = [...attempt.matchAll(/const STATUS_\w+ = '(\w+)'/g)].map(
        (match) => match[1],
    );
    assert.deepEqual(keysOf(statuses.PAYMENT_ATTEMPT_STATUS), attemptStatuses.sort());

    const address = read('Modules/Ipam/app/Models/IpAddress.php');
    const addressStatuses = [...address.matchAll(/const STATUS_\w+ = '(\w+)'/g)].map(
        (match) => match[1],
    );
    assert.deepEqual(keysOf(statuses.IP_ADDRESS_STATUS), addressStatuses.sort());

    const accounts = read(
        'Modules/NetworkAsset/database/migrations/2026_10_13_210001_create_network_accounts_table.php',
    );
    assert.deepEqual(
        keysOf(statuses.NETWORK_SYNC_STATUS),
        quoted(/enum\('sync_status', \[([^\]]*)\]/.exec(accounts)[1]),
    );
    assert.deepEqual(
        keysOf(statuses.NETWORK_SERVICE_TYPE),
        quoted(/enum\('service_type', \[([^\]]*)\]/.exec(accounts)[1]),
    );
});
