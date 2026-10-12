<?php

namespace Modules\NetworkAsset\Services;

use Modules\NetworkAsset\Models\NasDevice;

/**
 * The router operations Inbils needs, whatever API the router speaks (REST or binary). Service type is
 * `pppoe` (/ppp/secret, /ppp/active) or `hotspot` (/ip/hotspot/user, /ip/hotspot/active). Every failure is a
 * RouterOsException with nasDeviceId set; the shapes below are already converted from RouterOS strings.
 */
interface RouterOsApi
{
    /** @return array{version: string, board_name: string, uptime: string} */
    public function systemResource(NasDevice $nas): array;

    /** @return array{id: string, name: string, profile: ?string, disabled: bool, comment: ?string, service: ?string}|null */
    public function find(NasDevice $nas, string $type, string $name): ?array;

    /**
     * @param  array<string, string|bool>  $fields
     * @return string the new record id
     */
    public function create(NasDevice $nas, string $type, array $fields): string;

    /** @param  array<string, string|bool>  $fields */
    public function update(NasDevice $nas, string $type, string $id, array $fields): void;

    /** A record that is already gone counts as removed. */
    public function remove(NasDevice $nas, string $type, string $id): void;

    /** @return list<array{id: string, name: string, address: ?string}> */
    public function activeSessions(NasDevice $nas, string $type, ?string $name = null): array;

    /** Ends one session. A session that is already gone counts as ended. */
    public function disconnect(NasDevice $nas, string $type, string $activeId): void;

    /** @return list<array{id: string, name: string, profile: ?string, disabled: bool, comment: ?string, service: ?string}> */
    public function listAccounts(NasDevice $nas, string $type): array;
}
