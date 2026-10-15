<?php

namespace Modules\Inventory\Exceptions;

use RuntimeException;

class InsufficientStockException extends RuntimeException
{
    public static function forIssue(float $requested, float $available): self
    {
        return new self("Stok tidak mencukupi. Diminta: {$requested}, Tersedia: {$available}.");
    }

    public static function forReserve(float $requested, float $available): self
    {
        return new self("Stok tersedia tidak mencukupi untuk reservasi. Diminta: {$requested}, Tersedia: {$available}.");
    }
}
