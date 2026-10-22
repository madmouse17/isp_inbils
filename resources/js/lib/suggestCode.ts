/**
 * A code an admin can accept from a name: letters and digits in capitals, words joined by a dash, so
 * "Home 20Mbps" gives "PKG-HOME-20MBPS" with the prefix `PKG`. Null while the name has no letter or digit,
 * so a chip never offers an empty code. The server still checks that the code is free.
 */
export function suggestCode(name: string, prefix = '', maxLength = 50): string | null {
    const body = name
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .toUpperCase()
        .replace(/[^A-Z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');

    if (body === '') return null;

    return `${prefix === '' ? '' : `${prefix}-`}${body}`.slice(0, maxLength).replace(/-+$/, '');
}
