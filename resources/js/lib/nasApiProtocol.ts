import type { NasApiProtocol } from '@/types/network-asset';

export const NAS_API_PROTOCOLS: { value: NasApiProtocol; label: string; hint: string }[] = [
    {
        value: 'rest',
        label: 'REST API (RouterOS 7.1 or later)',
        hint: 'HTTPS, www-ssl service, usually port 443. The router certificate is always verified.',
    },
    {
        value: 'binary',
        label: 'Binary API (RouterOS 6 or 7)',
        hint: 'Any port except 8728 is TLS with a verified certificate (default 8729, api-ssl). Port 8728 (api) is plain text: use only on a trusted management network. Custom plain-text ports are not supported.',
    },
];

/** The port to suggest when the protocol changes. */
export const defaultApiPort = (protocol: NasApiProtocol): string =>
    protocol === 'binary' ? '8729' : '443';

/** Switching protocol moves the port only while it still holds the other protocol's default. */
export const portAfterProtocolChange = (current: string, next: NasApiProtocol): string => {
    const otherDefaults = next === 'binary' ? ['443'] : ['8728', '8729'];

    return otherDefaults.includes(current) ? defaultApiPort(next) : current;
};

export const apiProtocolLabel = (protocol: NasApiProtocol): string =>
    NAS_API_PROTOCOLS.find((option) => option.value === protocol)?.label ?? protocol;
