import { createHmac } from 'node:crypto';

const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
const STEP_SECONDS = 30;

function base32Decode(secret: string): Buffer {
    const bits = [...secret.replace(/[\s=]/g, '').toUpperCase()]
        .map((char) => {
            const value = BASE32.indexOf(char);
            if (value < 0) {
                throw new Error(`Not a base32 character: ${char}`);
            }
            return value.toString(2).padStart(5, '0');
        })
        .join('');

    return Buffer.from((bits.match(/.{8}/g) ?? []).map((byte) => parseInt(byte, 2)));
}

/**
 * RFC 6238 authenticator code (HMAC-SHA1, 6 digits, 30 s step) for a base32 secret.
 * `at` is epoch milliseconds: pass `Date.now() + 30_000` for the next step's code, which the server
 * also accepts and which is not yet spent when the current step's code was used to confirm.
 */
export function totp(secret: string, at: number = Date.now()): string {
    const counter = Buffer.alloc(8);
    counter.writeBigUInt64BE(BigInt(Math.floor(at / 1000 / STEP_SECONDS)));

    const hmac = createHmac('sha1', base32Decode(secret)).update(counter).digest();
    const offset = hmac[hmac.length - 1] & 0x0f;

    return String((hmac.readUInt32BE(offset) & 0x7fffffff) % 1_000_000).padStart(6, '0');
}
