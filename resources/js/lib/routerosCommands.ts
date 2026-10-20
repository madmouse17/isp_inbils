/**
 * What a MikroTik router needs to be a RADIUS client of Inbils: the commands the admin pastes into the RouterOS
 * terminal, and the shared secret the admin can have Inbils invent. Pure text, no browser state: the card that shows it
 * is `Components/composite/RouterOsCommandCard`.
 */

/** Stands where the page does not hold the secret (the saved router). Left unquoted on purpose, see `routerOsCommands`. */
export const STORED_SECRET_PLACEHOLDER = '(secret yang Anda simpan)';

const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';

/** `StoreNasDeviceRequest` takes 8 to 60 printable characters; 32 of 62 symbols is about 190 bits. */
const RADIUS_SECRET_LENGTH = 32;

/** 4 × 62. Only a byte below it lands on the 62 symbols with equal odds; `byte % 62` over all 256 would favour "A" to "H". */
const UNBIASED_BYTES = 248;

/**
 * A random shared secret of letters and digits. `fill` is the entropy source (`crypto.getRandomValues`); it takes and
 * returns a byte array, and the selfcheck passes its own to prove that biased bytes are dropped.
 */
export function randomRadiusSecret(
    length = RADIUS_SECRET_LENGTH,
    fill: (bytes: Uint8Array) => Uint8Array = (bytes) => crypto.getRandomValues(bytes),
): string {
    let secret = '';

    while (secret.length < length) {
        for (const byte of fill(new Uint8Array(length))) {
            if (secret.length === length) break;
            if (byte < UNBIASED_BYTES) secret += ALPHABET[byte % ALPHABET.length];
        }
    }

    return secret;
}

const OCTET = '(?:25[0-5]|2[0-4]\\d|1\\d\\d|[1-9]?\\d)';
const IPV4 = new RegExp(`^${OCTET}(?:\\.${OCTET}){3}$`);
const HOST_LABEL = /^[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?$/;

/** Dotted IPv4, the form the NAS address takes (`StoreNasDeviceRequest`: `ipv4`). */
export function isIpv4Address(value: string): boolean {
    return IPV4.test(value);
}

/**
 * Where a router can reach FreeRADIUS: a dotted IPv4 address or a host name. The last label of a name is never all
 * digits, so `10.0.0.256` is a wrong address, not a host.
 */
export function isRouterHost(value: string): boolean {
    if (isIpv4Address(value)) return true;
    if (value.length > 253) return false;

    const labels = value.split('.');

    return (
        labels.every((label) => HOST_LABEL.test(label)) && !/^\d+$/.test(labels[labels.length - 1])
    );
}

/** A host or IPv4 address as the router reads it without quotes. */
const BARE_WORD = /^[A-Za-z0-9.-]+$/;

/**
 * One value as RouterOS reads it: bare when `bare` allows it and the value is a plain host or address, otherwise a
 * double-quoted string. Inside it `"`, `\` and `$` (variable substitution) take a backslash, and every control character,
 * non-ASCII byte and `?` (the console reads it as a request for help) becomes `\XX`, the hex code with its letters in
 * capitals. No value can then end the line or start another command, and the router receives exactly the characters
 * Inbils holds. A secret is always quoted: a bare word is parsed by type, and a quoted one never is.
 */
function routerOsString(value: string, bare: boolean): string {
    if (bare && BARE_WORD.test(value)) return value;

    const escaped = Array.from(new TextEncoder().encode(value), (byte) => {
        const character = String.fromCharCode(byte);

        if (character === '"' || character === '\\' || character === '$') return `\\${character}`;
        if (byte < 0x20 || byte > 0x7e || character === '?') {
            return `\\${byte.toString(16).toUpperCase().padStart(2, '0')}`;
        }

        return character;
    });

    return `"${escaped.join('')}"`;
}

interface RouterOsInput {
    /** The address the router uses to reach FreeRADIUS. */
    radiusServer: string;
    /** The shared secret as it will be saved; null keeps the placeholder, which RouterOS refuses until it is replaced. */
    secret: string | null;
    /** The address FreeRADIUS sees the router at (`nas.nasname`). */
    nasAddress: string;
    /** The port the router listens on for Disconnect requests. */
    coaPort: number;
}

/** The three commands, one per line: the RADIUS server, the incoming Disconnect listener, and PPP using both. */
export function routerOsCommands({
    radiusServer,
    secret,
    nasAddress,
    coaPort,
}: RouterOsInput): string {
    const secretValue = secret === null ? STORED_SECRET_PLACEHOLDER : routerOsString(secret, false);

    return [
        `/radius add service=ppp address=${routerOsString(radiusServer, true)} secret=${secretValue} src-address=${routerOsString(nasAddress, true)} authentication-port=1812 accounting-port=1813`,
        `/radius incoming set accept=yes port=${Math.trunc(coaPort)}`,
        '/ppp aaa set use-radius=yes accounting=yes interim-update=5m',
    ].join('\n');
}
