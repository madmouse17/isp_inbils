/**
 * Node selfcheck for `lib/routerosCommands`: the RouterOS text an admin pastes into a router, the random shared
 * secret, and the host checks that gate the panel.
 * Run: node --test resources/js/lib/routerosCommands.selfcheck.mjs
 *
 * Expected values are literals written from the RouterOS scripting manual (escape sequences of a double-quoted
 * string: `\"`, `\\`, `\$`, `\xx` with the hex letters in capitals), never computed with the code under test.
 */
import assert from 'node:assert/strict';
import test from 'node:test';

const {
    STORED_SECRET_PLACEHOLDER,
    isIpv4Address,
    isRouterHost,
    randomRadiusSecret,
    routerOsCommands,
} = await import('./routerosCommands.ts');

const base = {
    radiusServer: '203.0.113.7',
    secret: 'Ab12Cd34Ef56Gh78Ij90Kl12Mn34Op56',
    nasAddress: '198.51.100.20',
    coaPort: 1700,
};

test('the three commands, in order, with the secret quoted', () => {
    assert.equal(
        routerOsCommands(base),
        [
            '/radius add service=ppp address=203.0.113.7 secret="Ab12Cd34Ef56Gh78Ij90Kl12Mn34Op56" src-address=198.51.100.20 authentication-port=1812 accounting-port=1813',
            '/radius incoming set accept=yes port=1700',
            '/ppp aaa set use-radius=yes accounting=yes interim-update=5m',
        ].join('\n'),
    );
});

test('the CoA port is the one given', () => {
    assert.match(routerOsCommands({ ...base, coaPort: 13799 }), /\n\/radius incoming set accept=yes port=13799\n/);
});

test('a secret reaches the router exactly as typed: quote, backslash, dollar and question mark are escaped', () => {
    const quoted = (secret) => routerOsCommands({ ...base, secret }).split('\n')[0];

    assert.match(quoted('p@$$w0rd'), / secret="p@\\\$\\\$w0rd" /);
    assert.match(quoted('say"hi'), / secret="say\\"hi" /);
    assert.match(quoted('back\\slash'), / secret="back\\\\slash" /);
    // The console takes `?` as a request for help, so it is written as its hex code.
    assert.match(quoted('why?'), / secret="why\\3F" /);
    // Characters RouterOS leaves alone inside quotes stay readable.
    assert.match(quoted('a[b];c d'), / secret="a\[b\];c d" /);
});

test('a control character or a non-ASCII byte becomes capital-letter hex, so no line can end early', () => {
    const quoted = (secret) => routerOsCommands({ ...base, secret }).split('\n')[0];

    assert.match(quoted('a\nb'), / secret="a\\0Ab" /);
    assert.match(quoted('a\r\tb'), / secret="a\\0D\\09b" /);
    assert.match(quoted('é'), / secret="\\C3\\A9" /);
    // Whatever the secret holds, the output stays three lines.
    assert.equal(routerOsCommands({ ...base, secret: 'x\n/system reset-configuration' }).split('\n').length, 3);
});

test('an address that is not a plain host is quoted, never pasted raw', () => {
    const first = routerOsCommands({ ...base, radiusServer: '1.2.3.4; /system reset-configuration' }).split('\n')[0];

    assert.match(first, / address="1\.2\.3\.4; \/system reset-configuration" /);
    assert.equal(routerOsCommands({ ...base, radiusServer: 'radius.example.net' }).split('\n')[0].includes(' address=radius.example.net '), true);
});

test('without a secret the placeholder stays raw, so the router refuses the line until it is replaced', () => {
    const first = routerOsCommands({ ...base, secret: null }).split('\n')[0];

    assert.equal(STORED_SECRET_PLACEHOLDER, '(secret yang Anda simpan)');
    assert.match(first, / secret=\(secret yang Anda simpan\) src-address=/);
    assert.equal(first.includes('"'), false);
});

test('a random secret is 32 letters and digits', () => {
    for (let run = 0; run < 200; run += 1) {
        assert.match(randomRadiusSecret(), /^[A-Za-z0-9]{32}$/);
    }

    assert.equal(randomRadiusSecret(8).length, 8);
});

test('two random secrets differ and every character of the alphabet turns up', () => {
    assert.notEqual(randomRadiusSecret(), randomRadiusSecret());

    const seen = new Set();
    for (let run = 0; run < 400; run += 1) {
        for (const character of randomRadiusSecret()) seen.add(character);
    }

    assert.equal(seen.size, 62);
});

test('bytes from 248 up are dropped, so no character is likelier than another', () => {
    // Eight bytes per call. 255, 248 and 250 would wrap onto "H", "A" and "C" (the first eight symbols) if they were used.
    // The rest are 0 -> "A", 247 -> 247 mod 62 = 61 -> "9", 61 -> "9", 62 -> "A", 124 -> "A".
    const bytes = [255, 248, 250, 0, 247, 61, 62, 124];
    let calls = 0;
    const fill = (buffer) => {
        calls += 1;
        buffer.set(bytes);

        return buffer;
    };

    // One call gives five characters ("A99AA"); the second gives the last three ("A99").
    assert.equal(randomRadiusSecret(8, fill), 'A99AAA99');
    assert.equal(calls, 2);
});

test('the host checks accept an IPv4 address or a host name and refuse the rest', () => {
    for (const host of ['203.0.113.7', '10.0.0.1', '255.255.255.255', 'radius', 'radius.example.net', 'r-1.example.net']) {
        assert.equal(isRouterHost(host), true, host);
    }
    for (const host of ['', ' ', '10.0.0', '10.0.0.256', '1.2.3.4.5', 'a b', 'a;b', '-bad.example', 'bad-.example', 'a..b', 'x'.repeat(64) + '.net']) {
        assert.equal(isRouterHost(host), false, JSON.stringify(host));
    }

    assert.equal(isIpv4Address('198.51.100.20'), true);
    assert.equal(isIpv4Address('radius.example.net'), false);
    assert.equal(isIpv4Address('198.51.100'), false);
    assert.equal(isIpv4Address('198.51.100.20 '), false);
});
