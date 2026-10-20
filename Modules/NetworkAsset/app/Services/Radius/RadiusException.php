<?php

namespace Modules\NetworkAsset\Services\Radius;

use RuntimeException;

/**
 * A RADIUS database call that was refused or failed. Messages are fixed text and never chain the driver
 * exception: it carries the host, user and bindings.
 */
final class RadiusException extends RuntimeException
{
    /** The company in scope has no enabled RADIUS integration (Pengaturan → Integrasi): nothing was sent. */
    public const DISABLED = 'disabled';

    /** The connection or a query failed: retrying later may help. */
    public const UNAVAILABLE = 'unavailable';

    /** The input cannot be stored in the RADIUS schema: retrying cannot help. */
    public const REJECTED = 'rejected';

    public function __construct(public readonly string $kind, string $message)
    {
        parent::__construct($message);
    }

    public static function disabled(): self
    {
        return new self(self::DISABLED, 'RADIUS belum dikonfigurasi untuk perusahaan ini (Pengaturan → Integrasi).');
    }

    public static function unavailable(): self
    {
        return new self(self::UNAVAILABLE, 'Basis data RADIUS tidak dapat dihubungi; dicoba lagi otomatis.');
    }

    /** The company code is not a valid RADIUS realm: no username or NAS client can be written for the company. */
    public static function invalidRealm(): self
    {
        return new self(self::REJECTED, 'Kode perusahaan tidak bisa dipakai sebagai realm RADIUS.');
    }

    public static function rejected(string $message): self
    {
        return new self(self::REJECTED, $message);
    }
}
