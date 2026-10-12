<?php

namespace Modules\NetworkAsset\Services;

use Illuminate\Support\Str;
use Modules\NetworkAsset\Models\NasDevice;

/** What both RouterOS clients (REST and binary API) share: the menus, record id and value conversions. */
trait ConvertsRouterOsRecords
{
    /** @var array<string, array{accounts: string, sessions: string, login: string}> */
    private const MENUS = [
        'pppoe' => ['accounts' => 'ppp/secret', 'sessions' => 'ppp/active', 'login' => 'name'],
        'hotspot' => ['accounts' => 'ip/hotspot/user', 'sessions' => 'ip/hotspot/active', 'login' => 'user'],
    ];

    private const ACCOUNT_FIELDS = '.id,name,profile,disabled,comment';

    /** PPP secrets also say which service they serve (pppoe, l2tp, ...): adoption must never take over a VPN user. */
    private const PPP_EXTRA_FIELDS = ',service';

    protected function accountFields(string $type): string
    {
        return self::ACCOUNT_FIELDS.($type === 'pppoe' ? self::PPP_EXTRA_FIELDS : '');
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{id: string, name: string, profile: ?string, disabled: bool, comment: ?string, service: ?string}
     */
    protected function account(array $row): array
    {
        return [
            'id' => (string) ($row['.id'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'profile' => $this->text($row['profile'] ?? null),
            'disabled' => ($row['disabled'] ?? 'false') === 'true',
            'comment' => $this->text($row['comment'] ?? null),
            'service' => $this->text($row['service'] ?? null),
        ];
    }

    protected function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /** RouterOS record ids look like `*1A`; anything else is never put into a URL. */
    protected function recordId(mixed $id): string
    {
        if (! is_string($id) || ! preg_match('/^\*[0-9A-Fa-f]+$/', $id)) {
            throw RouterOsException::server('The router returned an unexpected record id.');
        }

        return $id;
    }

    /**
     * RouterOS reads every value as a string.
     *
     * @param  array<string, string|bool>  $fields
     * @return array<string, string>
     */
    protected function strings(array $fields): array
    {
        return array_map(fn (string|bool $value): string => is_bool($value) ? ($value ? 'true' : 'false') : $value, $fields);
    }

    /** The router asset's management IP, bracketed when IPv6; anything else is never put into an address. */
    protected function managementHost(NasDevice $nas): string
    {
        $host = $nas->networkAsset?->getAttribute('management_ip');

        if (! is_string($host) || filter_var($host, FILTER_VALIDATE_IP) === false) {
            throw RouterOsException::rejected('The router asset has no valid management IP.');
        }

        return str_contains($host, ':') ? "[{$host}]" : $host;
    }

    /** RouterOS error text for the audit trail: control characters out, 255 characters at most. */
    protected function cleanMessage(string $text): string
    {
        return Str::limit(trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', $text) ?? ''), 255, '');
    }
}
