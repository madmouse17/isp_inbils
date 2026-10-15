import assert from 'node:assert/strict';
import { registerHooks } from 'node:module';
import test from 'node:test';

registerHooks({
    resolve: (specifier, context, next) => {
        if (specifier === '@/Components/composite') {
            return next(new URL('../Components/composite/index.ts', import.meta.url).href, context);
        }

        return next(/^\.\.?\/[^.]+$/.test(specifier) ? `${specifier}.ts` : specifier, context);
    },
});

const { toPagination } = await import('./pagination.ts');

test('resource collection pagination reads page numbers from meta', () => {
    assert.deepEqual(
        toPagination({
            data: [{ id: 11 }],
            meta: { current_page: 2, last_page: 4, per_page: 10, total: 31 },
        }),
        {
            data: [{ id: 11 }],
            current_page: 2,
            last_page: 4,
            per_page: 10,
            total: 31,
        },
    );
});

test('plain paginator pagination stays supported', () => {
    assert.deepEqual(
        toPagination({
            data: [{ id: 1 }],
            current_page: 3,
            last_page: 5,
            per_page: 10,
            total: 41,
        }),
        {
            data: [{ id: 1 }],
            current_page: 3,
            last_page: 5,
            per_page: 10,
            total: 41,
        },
    );
});
