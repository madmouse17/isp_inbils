<?php

namespace Modules\Inventory\Exceptions;

use RuntimeException;

class InsufficientStockException extends RuntimeException
{
    public static function forIssue(float $requested, float $available): self
    {
        return new self("Insufficient stock. Requested: {$requested}, Available: {$available}.");
    }

    public static function forReserve(float $requested, float $available): self
    {
        return new self("Insufficient available stock for reservation. Requested: {$requested}, Available: {$available}.");
    }
}
