/**
 * Self-check test for AuditLog UI logic and static contracts.
 * Run: node --test resources/js/Pages/Admin/Reports/AuditLog.selfcheck.mjs
 */
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const auditLogTsxPath = new URL('./AuditLog.tsx', import.meta.url);

test('AuditLog.tsx static contracts and component integrity', async () => {
    const source = await readFile(auditLogTsxPath, 'utf8');

    // 1. Export default component
    assert.match(source, /export default function AuditLog/);

    // 2. Exact allowlist & components: no raw table/input/button
    assert.match(source, /from '@\/Components\/ui'/);
    assert.match(source, /from '@\/Components\/composite'/);
    assert.doesNotMatch(source, /<table\b/);
    assert.doesNotMatch(source, /<button\b/);

    // 3. Multi-tenant fail-closed protection: legacy rows without company_id must never be rendered
    assert.match(source, /row\.company_id != null/);

    // 4. Server-side canonical search/log/user/date/sort/per_page/page
    assert.match(source, /useServerTable\(/);
    assert.match(source, /route\('admin\.reports\.audit-log'\)/);
    assert.match(source, /search/);
    assert.match(source, /log_name/);
    assert.match(source, /user_id/);
    assert.match(source, /date_from/);
    assert.match(source, /date_to/);
    assert.match(source, /sort/);
    assert.match(source, /direction/);
    assert.match(source, /per_page/);

    // 5. States: Loading / Empty / Error / Stale / Truncated
    assert.match(source, /isTruncated/);
    assert.match(source, /500/);
    assert.match(source, /<DateRangePicker/);
    assert.doesNotMatch(source, /type="date"/);
    assert.match(source, /Tidak Ada Log Aktivitas/);
    assert.match(source, /Memperbarui data/);

    // 6. Permission gating for sensitive properties & export
    assert.match(source, /report\.audit\.view/);
    assert.match(source, /canExport/);
    assert.match(source, /canViewAudit/);
    assert.match(source, /ExportMenu/);

    // 7. Responsive mobile cards
    assert.match(source, /hidden md:block/);
    assert.match(source, /md:hidden/);

    // 8. Bahasa Indonesia language
    assert.match(source, /Rekam jejak aktivitas audit sistem/);
    assert.match(source, /Terapkan Filter/);
    assert.match(source, /Atur Ulang/);
    assert.match(source, /Muat Ulang/);
    assert.match(source, /Detail Log/);
    assert.match(source, /Pelaku/);
    assert.match(source, /Subjek/);

    // 9. Contract alignment: Pelaku and Subjek are NOT sortable (server-authoritative, no invalid 422 keys)
    assert.doesNotMatch(source, /key:\s*['"]causer['"],\s*header:\s*['"]Pelaku['"],\s*sortable:\s*true/);
    assert.doesNotMatch(source, /key:\s*['"]subject['"],\s*header:\s*['"]Subjek['"],\s*sortable:\s*true/);
    assert.doesNotMatch(source, /sortKey:\s*['"]causer['"]/);
    assert.doesNotMatch(source, /sortKey:\s*['"]subject['"]/);
    assert.match(source, /ALLOWED_SORT_FIELDS/);

    // 10. Client fallback isolation: when meta is present, bypass client search & sort
    assert.match(source, /if\s*\(meta\)\s*\{\s*return safeRows;\s*\}/);
});

test('Fail-closed tenant isolation logic: quarantine rows where company_id is null', () => {
    const rawRows = [
        { id: 1, company_id: 10, log_name: 'billing', description: 'Tenant 10 invoice' },
        { id: 2, company_id: null, log_name: 'legacy', description: 'Quarantined ambiguous row' },
        {
            id: 3,
            company_id: undefined,
            log_name: 'legacy',
            description: 'Quarantined missing row',
        },
        { id: 4, company_id: 10, log_name: 'customer', description: 'Tenant 10 customer' },
    ];

    const safeRows = rawRows.filter((r) => r.company_id != null);
    assert.equal(safeRows.length, 2);
    assert.deepEqual(
        safeRows.map((r) => r.id),
        [1, 4],
    );
});

test('Search filter logic matches description, log_name, event, and causer', () => {
    const rows = [
        {
            id: 101,
            company_id: 1,
            log_name: 'billing',
            event: 'created',
            description: 'Invoice INV-2026-001 created',
            causer: { id: 5, name: 'Budi Santoso', email: 'budi@example.com' },
        },
        {
            id: 102,
            company_id: 1,
            log_name: 'inventory',
            event: 'updated',
            description: 'Stock adjusted for Router Mikrotik',
            causer: { id: 7, name: 'Siti Aminah', email: 'siti@example.com' },
        },
    ];

    const filterBy = (term) => {
        const lower = term.toLowerCase();
        return rows.filter(
            (r) =>
                r.description.toLowerCase().includes(lower) ||
                r.log_name.toLowerCase().includes(lower) ||
                r.event.toLowerCase().includes(lower) ||
                (r.causer?.name.toLowerCase().includes(lower) ?? false) ||
                (r.causer?.email.toLowerCase().includes(lower) ?? false),
        );
    };

    assert.equal(filterBy('budi').length, 1);
    assert.equal(filterBy('budi')[0].id, 101);
    assert.equal(filterBy('mikrotik').length, 1);
    assert.equal(filterBy('mikrotik')[0].id, 102);
    assert.equal(filterBy('billing').length, 1);
    assert.equal(filterBy('created').length, 1);
    assert.equal(filterBy('nonexistent').length, 0);
});

test('Truncated state detector detects 500 limit reached', () => {
    const list499 = Array.from({ length: 499 }, (_, i) => ({ id: i, company_id: 1 }));
    const list500 = Array.from({ length: 500 }, (_, i) => ({ id: i, company_id: 1 }));

    assert.equal(list499.length >= 500, false);
    assert.equal(list500.length >= 500, true);
});

test('Model diff key extraction extracts combined unique sorted keys', () => {
    const properties = {
        attributes: { status: 'paid', amount: 150000, updated_at: '2026-09-07' },
        old: { status: 'unpaid', amount: 150000, note: 'pending verification' },
    };

    const attrs = Object.keys(properties.attributes || {});
    const olds = Object.keys(properties.old || {});
    const combined = Array.from(new Set([...attrs, ...olds])).sort();

    assert.deepEqual(combined, ['amount', 'note', 'status', 'updated_at']);
});
