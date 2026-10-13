export type ResetPeriod = 'never' | 'yearly' | 'monthly' | 'daily';

export interface TemplateInput {
    template: string;
    prefix: string;
    companyCode: string;
    nextNumber: number;
    resetPeriod: ResetPeriod;
    /** IANA zone of the company; date tokens render in it, like the server does. */
    timeZone: string;
}

const TOKEN = /\{([A-Z]+)(?::(\d+))?\}/g;
const LITERAL = /^[A-Za-z0-9\-_/.]*$/;

const pad = (value: number, width: number) => String(value).padStart(width, '0');

/** Mirrors the PHP NumberTemplate renderer; `{SEQ:n}` is zero-padded, never truncated. */
export function renderTemplate(input: TemplateInput, now: Date = new Date()): string {
    const { template, prefix, companyCode, nextNumber, timeZone } = input;
    const date = Object.fromEntries(
        new Intl.DateTimeFormat('en-US', {
            timeZone,
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
        })
            .formatToParts(now)
            .map((part) => [part.type, part.value]),
    );
    const parts: Record<string, string> = {
        PREFIX: prefix,
        YYYY: date.year.padStart(4, '0'),
        YY: date.year.slice(-2),
        MM: date.month,
        DD: date.day,
        COMPANY: companyCode,
    };

    return template.replace(TOKEN, (match, name: string, width?: string) => {
        if (name === 'SEQ' && width !== undefined) return pad(nextNumber, Math.min(Number(width), 10));
        return width === undefined && name in parts ? parts[name] : match;
    });
}

/** Client-side hint only; the server stays the authority and returns the real validation errors. */
export function validateTemplate(template: string, resetPeriod: ResetPeriod): string | null {
    const literals = template.replace(TOKEN, '');
    if (!LITERAL.test(literals)) {
        return 'Gunakan huruf, angka, tanda - _ / . dan token di dalam kurung kurawal.';
    }

    const names: string[] = [];
    for (const [, name, width] of template.matchAll(TOKEN)) {
        const valid =
            name === 'SEQ'
                ? width !== undefined && Number(width) >= 1 && Number(width) <= 10
                : width === undefined &&
                  ['PREFIX', 'YYYY', 'YY', 'MM', 'DD', 'COMPANY'].includes(name);
        if (!valid)
            return `Token {${name}${width !== undefined ? `:${width}` : ''}} tidak dikenal.`;
        names.push(name);
    }

    if (names.filter((name) => name === 'SEQ').length !== 1) {
        return 'Format harus memuat tepat satu {SEQ:n}, n antara 1 dan 10.';
    }

    const hasYear = names.includes('YYYY') || names.includes('YY');
    if (resetPeriod !== 'never' && !hasYear) return 'Reset berkala memerlukan {YYYY} atau {YY}.';
    if ((resetPeriod === 'monthly' || resetPeriod === 'daily') && !names.includes('MM')) {
        return 'Reset bulanan atau harian memerlukan {MM}.';
    }
    if (resetPeriod === 'daily' && !names.includes('DD')) return 'Reset harian memerlukan {DD}.';

    return null;
}
