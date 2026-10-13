/**
 * Node selfcheck for the Indonesian copy of the web UI (spec `ui-consistency`, Q4 and R6).
 *
 * It reads the sources with the TypeScript parser and collects what a person reads: JSX text, string
 * props such as `label=` or `placeholder=`, and object properties such as `header:` or `label:`.
 * Any of those that holds an English word from the denylist below fails, unless the word is a kept
 * term or the text is allowlisted.
 *
 * Run: node --test resources/js/lib/copy.selfcheck.mjs
 *   - default: the files in `CONVERTED`, which must be clean. A ticket adds its files there when it
 *     finishes them, so the check never goes red for work that is still to come.
 *   - COPY_SCAN=all node --test resources/js/lib/copy.selfcheck.mjs: every file under `ROOTS`, for QA;
 *     it lists what is still English.
 */
import assert from 'node:assert/strict';
import { globSync, readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import ts from 'typescript';

const JS_ROOT = fileURLToPath(new URL('..', import.meta.url));

/** Where UI text lives. */
const ROOTS = ['Pages/**/*.tsx', 'Components/**/*.tsx', 'Layouts/**/*.tsx', 'lib/statuses/*.ts'];

/** Files that are finished and must stay Indonesian. Append a path or a glob when a ticket converts files. */
const CONVERTED = [
    'Components/ui/PickerField.tsx',
    'Components/ui/DatePicker.tsx',
    'Components/ui/DateTimePicker.tsx',
    'Components/ui/MonthPicker.tsx',
    'Components/ui/TimeInput.tsx',
    'Components/ui/TimeSlotList.tsx',
    'Components/ui/EnumBadge.tsx',
    'Components/ui/SearchSelect.tsx',
    'Components/ui/MapPicker.tsx',
    'Components/ui/FileUpload.tsx',
    'Components/composite/RemoteSearchSelect.tsx',
    'Components/composite/IndonesiaRegionFields.tsx',
    'Components/composite/CustomerAcquirerField.tsx',
    'Components/composite/ConfirmRequestDialog.tsx',
    'Components/composite/ResourceState.tsx',
    'Components/composite/CompanySwitcher.tsx',
    'Pages/Admin/Components.tsx',
    'lib/statuses/*.ts',
    'Components/ui/DataTable.tsx',
    'Components/ui/Pagination.tsx',
    'Components/ui/ExportMenu.tsx',
    'Components/ui/Toast.tsx',
    'Components/ui/dialog.tsx',
    'Components/ui/sheet.tsx',
    'Components/ui/alert-dialog.tsx',
    'Components/ui/EmptyState.tsx',
    'Components/ui/empty.tsx',
    'Components/ui/Topbar.tsx',
    'Components/ui/Sidebar.tsx',
    'Components/ui/Modal.tsx',
    'Components/ui/Alert.tsx',
    'Components/ui/Wordmark.tsx',
    'Components/ui/EmptyCell.tsx',
    'Components/ui/Badge.tsx',
    'Components/ui/Card.tsx',
    'Components/ui/Checkbox.tsx',
    'Components/ui/IconButton.tsx',
    'Components/ui/Input.tsx',
    'Components/ui/Textarea.tsx',
    'Components/ui/Label.tsx',
    'Components/ui/Button.tsx',
    'Components/ui/Breadcrumb.tsx',
    'Components/ui/StatCard.tsx',
    'Components/ui/Dropdown.tsx',
    'Components/ui/dropdown-menu.tsx',
    'Components/composite/DataTable.tsx',
    'Components/composite/DataTableActions.tsx',
    'Components/composite/NativeSelect.tsx',
    'Components/composite/NotificationBell.tsx',
    'Components/composite/ErrorBoundary.tsx',
    'Components/composite/DynamicBadge.tsx',
    'Components/composite/StatusBadge.tsx',
    'Layouts/AdminLayout.tsx',
    'Layouts/GuestLayout.tsx',
    'Layouts/SetupLayout.tsx',
    'Pages/Error.tsx',
    'Pages/Auth/Login.tsx',
];

/** Words that stay as they are, in every sentence (Q4). They are removed from a text before it is judged. */
const KEPT_TERMS = [
    'SPK',
    'ODN',
    'ODP',
    'OLT',
    'PON',
    'NAS',
    'IP',
    'CIDR',
    'MAC',
    'VLAN',
    'SLA',
    'MRR',
    'KPI',
    'SKU',
    'RADIUS',
    'Midtrans',
    'WhatsApp',
    '2FA',
    'Email',
    'STOP',
    'NOC',
    'PPPoE',
    'Hotspot',
    'Online',
    'Offline',
];

/**
 * Intentional English, one entry per case with the reason. `file` is a path suffix (any file when
 * omitted), `text` the exact text. Keep this short: a text that is here is a decision, not a default.
 */
const ALLOWED = [
    // { file: 'Pages/Admin/Example.tsx', text: 'Open Street Map', reason: 'a product name' },
];

/**
 * English words that have an Indonesian replacement in the glossary (R6) or are plain English UI
 * words. Words both languages use (Status, Detail, Filter, Reset, Total, Data, Menu, Admin) are not
 * here, and neither is a word that is also an Indonesian abbreviation (`No.` for nomor).
 */
const DENIED_WORDS = [
    // actions
    ...[
        'Save',
        'Cancel',
        'Delete',
        'Remove',
        'Edit',
        'Create',
        'Add',
        'Update',
        'Submit',
        'Search',
    ],
    ...[
        'Back',
        'Next',
        'Previous',
        'Close',
        'Open',
        'Select',
        'Choose',
        'Apply',
        'Confirm',
        'Retry',
    ],
    ...['Export', 'Import', 'Download', 'Upload', 'Browse', 'Copy', 'Send', 'Print', 'Refresh'],
    // states and feedback
    ...['Loading', 'Error', 'Success', 'Failed', 'Warning', 'Required', 'Optional', 'Please'],
    ...['Active', 'Inactive', 'Pending', 'Completed', 'Cancelled', 'Unassigned', 'Disabled'],
    // fields and nouns
    ...['Name', 'Description', 'Type', 'Date', 'Time', 'Action', 'Actions', 'Notes', 'Reason'],
    ...['Amount', 'Quantity', 'Price', 'Address', 'Phone', 'Password', 'Login', 'Logout'],
    ...['Settings', 'Profile', 'Dashboard', 'Report', 'Reports', 'Page', 'Rows', 'Showing'],
    ...['Customer', 'Customers', 'Subscription', 'Subscriptions', 'Invoice', 'Invoices'],
    ...['Payment', 'Payments', 'Product', 'Products', 'Location', 'Locations', 'Technician'],
    ...['Employee', 'Employees', 'User', 'Users', 'Role', 'Roles', 'Permission', 'Permissions'],
    ...['Company', 'Organization', 'Vehicle', 'Vehicles', 'Document', 'Documents', 'Ticket'],
    ...['Tickets', 'Asset', 'Assets', 'Warehouse', 'Category', 'Categories', 'Province', 'City'],
    ...['District', 'Village', 'Latitude', 'Longitude', 'Current', 'Today', 'Yesterday'],
    // words of English sentences
    ...['the', 'and', 'or', 'with', 'your', 'you', 'is', 'are', 'not', 'this', 'that', 'from'],
    ...['for', 'of', 'in', 'on', 'at', 'by', 'has', 'have', 'was', 'will', 'can', 'cannot'],
    ...['must', 'should', 'if', 'then', 'here', 'there', 'no data', 'nothing', 'something'],
];

const DENIED = new RegExp(`(?<![\\w-])(${DENIED_WORDS.join('|')})(?![\\w-])`, 'i');
const KEPT = new RegExp(`(?<![\\w-])(${KEPT_TERMS.join('|')})(?![\\w-])`, 'g');

/** Props whose string value is read by a person. */
const TEXT_PROPS = new Set([
    'label',
    'placeholder',
    'title',
    'aria-label',
    'description',
    'hint',
    'subtitle',
    'emptyText',
    'emptyTitle',
    'emptyDescription',
    'errorTitle',
    'errorDescription',
    'retryLabel',
    'backLabel',
    'forbiddenTitle',
    'forbiddenDescription',
    'confirmLabel',
    'alt',
]);

/** Object keys whose string value is read by a person: a column header, an option or a status label. */
const TEXT_KEYS = new Set([
    'label',
    'title',
    'description',
    'placeholder',
    'header',
    'message',
    'hint',
    'subtitle',
    'emptyText',
    'retryLabel',
    'backLabel',
    'confirmLabel',
]);

/** The strings of an expression that can end up on screen: a literal, a template, either side of `?:` or `&&`. */
function literalTexts(node) {
    if (ts.isStringLiteralLike(node)) return [node.text];
    if (ts.isTemplateExpression(node)) {
        return [node.head.text, ...node.templateSpans.map((span) => span.literal.text)];
    }
    if (ts.isParenthesizedExpression(node)) return literalTexts(node.expression);
    if (ts.isConditionalExpression(node)) {
        return [...literalTexts(node.whenTrue), ...literalTexts(node.whenFalse)];
    }
    if (
        ts.isBinaryExpression(node) &&
        [
            ts.SyntaxKind.AmpersandAmpersandToken,
            ts.SyntaxKind.BarBarToken,
            ts.SyntaxKind.QuestionQuestionToken,
        ].includes(node.operatorToken.kind)
    ) {
        return [...literalTexts(node.left), ...literalTexts(node.right)];
    }

    return [];
}

/** Every string a person reads in a source file, with its line. */
export function readableStrings(sourceText, fileName = 'file.tsx') {
    const kind = fileName.endsWith('.tsx') ? ts.ScriptKind.TSX : ts.ScriptKind.TS;
    const source = ts.createSourceFile(fileName, sourceText, ts.ScriptTarget.Latest, true, kind);
    const found = [];
    const add = (node, text) => {
        const clean = text.replace(/\s+/g, ' ').trim();
        if (clean) {
            found.push({
                text: clean,
                line: source.getLineAndCharacterOfPosition(node.getStart()).line + 1,
            });
        }
    };

    const visit = (node) => {
        if (ts.isJsxText(node)) {
            add(node, node.text);
        } else if (ts.isJsxAttribute(node) && TEXT_PROPS.has(node.name.getText())) {
            const init = node.initializer;
            if (init && ts.isStringLiteral(init)) add(init, init.text);
            else if (init && ts.isJsxExpression(init) && init.expression) {
                literalTexts(init.expression).forEach((text) => add(init, text));
            }
        } else if (
            ts.isJsxExpression(node) &&
            (ts.isJsxElement(node.parent) || ts.isJsxFragment(node.parent)) &&
            node.expression
        ) {
            literalTexts(node.expression).forEach((text) => add(node, text));
        } else if (
            ts.isPropertyAssignment(node) &&
            (ts.isIdentifier(node.name) || ts.isStringLiteral(node.name)) &&
            TEXT_KEYS.has(node.name.text)
        ) {
            literalTexts(node.initializer).forEach((text) => add(node, text));
        }
        ts.forEachChild(node, visit);
    };
    visit(source);

    return found;
}

/** The English word in a text, or null. */
export function englishWord(text) {
    return DENIED.exec(text.replace(KEPT, ' '))?.[1] ?? null;
}

function violationsIn(path) {
    return readableStrings(readFileSync(`${JS_ROOT}${path}`, 'utf8'), path)
        .map(({ text, line }) => ({ path, line, text, word: englishWord(text) }))
        .filter(
            ({ path: file, text, word }) =>
                word &&
                !ALLOWED.some(
                    (entry) => entry.text === text && (!entry.file || file.endsWith(entry.file)),
                ),
        );
}

const files = (patterns) =>
    [...new Set(patterns.flatMap((pattern) => globSync(pattern, { cwd: JS_ROOT })))].sort();

test('the reader finds the text a person reads, and only that', () => {
    const sample = `
        export function Sample({ ok }: { ok: boolean }) {
            return (
                <section className="Save the date" data-name="Delete">
                    Simpan perubahan
                    <Field label="Name" aria-label="Kembali" title={ok ? 'Cancel' : 'Batal'} />
                    <Button>Create customer</Button>
                    {ok && 'Please wait'}
                    {rows.map((row) => ({ header: 'Type', key: 'type', cell: () => row.kind }))}
                </section>
            );
        }
    `;
    assert.deepEqual(
        readableStrings(sample).map(({ text }) => text),
        [
            'Simpan perubahan',
            'Name',
            'Kembali',
            'Cancel',
            'Batal',
            'Create customer',
            'Please wait',
            'Type',
        ],
    );
});

test('English UI words are caught, Indonesian text and kept terms are not', () => {
    for (const text of [
        'Save',
        'Cancel changes',
        'Rows per page',
        'Customer name',
        'Search...',
        'No data available',
        'Something went wrong',
        'Please try again',
        'Delete this record?',
        'The invoice is overdue',
    ]) {
        assert.notEqual(englishWord(text), null, text);
    }
    for (const text of [
        'Simpan',
        'Batal',
        'Hapus data ini?',
        'Baris per halaman',
        'Tidak ada data.',
        'Nama pelanggan',
        'Kirim ulang email',
        'Status SPK',
        'Penugasan SPK ke teknisi',
        'Kata sandi WhatsApp',
        'No. SPK',
        'Detail tagihan',
        'Reset filter',
        'Total',
        'PPPoE',
        'Online',
        '2FA aktif',
        'Pelanggan berhenti (STOP)',
    ]) {
        assert.equal(englishWord(text), null, text);
    }
});

test('the converted files read as Indonesian', () => {
    const scope = process.env.COPY_SCAN === 'all' ? ROOTS : CONVERTED;
    const list = files(scope);
    assert.ok(list.length > 0, `no file matches ${scope.join(', ')}`);

    const found = list.flatMap(violationsIn);
    const report = found
        .map(({ path, line, text, word }) => `${path}:${line} "${text}" (${word})`)
        .join('\n');

    assert.equal(found.length, 0, `English UI text in ${list.length} files:\n${report}`);
});
