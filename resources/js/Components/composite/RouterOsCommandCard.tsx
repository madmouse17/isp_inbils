import { useState } from 'react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle, Input } from '@/Components/ui';
import { CopyButton } from '@/Components/composite/CopyButton';
import { isIpv4Address, isRouterHost, routerOsCommands } from '@/lib/routerosCommands';

/** Inbils keeps the FreeRADIUS database host, not the address routers reach it at: the admin types that once per browser. */
const SERVER_KEY = 'inbils.radius.serverAddress';

function rememberedServer(): string {
    try {
        return window.localStorage.getItem(SERVER_KEY) ?? '';
    } catch {
        // Storage can be blocked (browser policy): the field simply starts empty.
        return '';
    }
}

function rememberServer(address: string): void {
    try {
        window.localStorage.setItem(SERVER_KEY, address);
    } catch {
        // Blocked storage: the address is asked again next time.
    }
}

interface RouterOsCommandCardProps {
    /** The address FreeRADIUS sees the router at (`nas.nasname`), as the page holds it. */
    nasAddress: string;
    coaPort: number;
    /** The secret as typed on the page; null where the page never holds it (the saved router). */
    secret: string | null;
    /** Said under the commands, e.g. why the secret reads as a placeholder. */
    note?: string;
}

/**
 * The commands that make a MikroTik router a RADIUS client of Inbils, ready to paste into its terminal. They follow the
 * form while it is typed. The commands appear once every value they need is valid, so a half-typed line is never copied.
 */
export function RouterOsCommandCard({
    nasAddress,
    coaPort,
    secret,
    note,
}: RouterOsCommandCardProps) {
    const [server, setServer] = useState(rememberedServer);
    const radiusServer = server.trim();
    const nas = nasAddress.trim();
    const serverError =
        radiusServer !== '' && !isRouterHost(radiusServer)
            ? 'Isi alamat IPv4 atau nama host, tanpa spasi.'
            : undefined;

    const missing = [
        { absent: !isRouterHost(radiusServer), label: 'alamat FreeRADIUS dari router' },
        { absent: !isIpv4Address(nas), label: 'alamat NAS' },
        { absent: !Number.isInteger(coaPort) || coaPort < 1 || coaPort > 65535, label: 'port CoA' },
        { absent: secret === '', label: 'secret RADIUS' },
    ].find((value) => value.absent);

    const commands = missing
        ? null
        : routerOsCommands({ radiusServer, secret, nasAddress: nas, coaPort });

    return (
        <Card>
            <CardHeader>
                <CardTitle>Perintah RouterOS</CardTitle>
                <CardDescription>
                    Tempel di terminal router agar router memakai FreeRADIUS Inbils.
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
                <Input
                    label="Alamat FreeRADIUS dari router"
                    value={server}
                    onChange={(event) => {
                        setServer(event.target.value);

                        const next = event.target.value.trim();
                        if (next === '' || isRouterHost(next)) rememberServer(next);
                    }}
                    hint="Alamat yang dipakai router untuk menjangkau FreeRADIUS. Disimpan hanya di peramban ini."
                    error={serverError}
                    autoComplete="off"
                    spellCheck={false}
                />
                {commands ? (
                    <>
                        <pre className="rounded-md bg-muted p-3 font-mono text-xs break-all whitespace-pre-wrap text-foreground select-all">
                            {commands}
                        </pre>
                        <CopyButton value={commands} label="Salin perintah" copiedLabel="Disalin" />
                    </>
                ) : (
                    <p className="text-sm text-muted-foreground">
                        Isi {missing?.label} untuk melihat perintahnya.
                    </p>
                )}
                {note ? <p className="text-sm text-muted-foreground">{note}</p> : null}
            </CardContent>
        </Card>
    );
}
