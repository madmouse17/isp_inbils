<?php

namespace Modules\Inventory\Exceptions;

use App\Support\Money;
use RuntimeException;

/**
 * A stock operation asked for more than the stock allows. The message names the product and the place in the words of
 * whoever reads it (a toast, the approval sheet, the app): "Stok Kabel Fiber Optic Single di AUDIT POP Bandung Timur
 * tersisa 0 m, dibutuhkan 18 m." `requested` and `available` are the quantities as shown: no trailing zeros, decimal
 * comma, dots between thousands.
 */
class InsufficientStockException extends RuntimeException
{
    private function __construct(
        public readonly string $productName,
        public readonly string $unit,
        public readonly string $locationName,
        public readonly string $requested,
        public readonly string $available,
        string $message,
    ) {
        parent::__construct($message);
    }

    /** Issue, transfer or reserve more than is free there (the quantity minus what is reserved). */
    public static function forShortfall(string|float $requested, string|float $available, string $productName, string $unit, string $locationName): self
    {
        return self::build($requested, $available, $productName, $unit, $locationName, fn (string $need, string $have): string => "Stok {$productName} di {$locationName} tersisa {$have}, dibutuhkan {$need}.");
    }

    /** Release more than is reserved there: `available` is the reserved quantity. */
    public static function forRelease(string|float $requested, string|float $available, string $productName, string $unit, string $locationName): self
    {
        return self::build($requested, $available, $productName, $unit, $locationName, fn (string $need, string $have): string => "Cadangan {$productName} di {$locationName} hanya {$have}, tidak bisa dilepas {$need}.");
    }

    /** Count the stock lower than what is reserved there: `requested` is the reserved quantity, `available` the new count. */
    public static function forAdjustment(string|float $requested, string|float $available, string $productName, string $unit, string $locationName): self
    {
        return self::build($requested, $available, $productName, $unit, $locationName, fn (string $need, string $have): string => "Stok {$productName} di {$locationName} tidak bisa diturunkan ke {$have}, karena {$need} sudah dicadangkan.");
    }

    /** @param  callable(string, string): string  $sentence  takes the requested and the available quantity, each followed by the unit */
    private static function build(string|float $requested, string|float $available, string $productName, string $unit, string $locationName, callable $sentence): self
    {
        $requested = self::quantity($requested);
        $available = self::quantity($available);

        return new self($productName, $unit, $locationName, $requested, $available, $sentence(trim("{$requested} {$unit}"), trim("{$available} {$unit}")));
    }

    /** The stored two decimals as text: "18", "0,3", "1.500,5". */
    private static function quantity(string|float $quantity): string
    {
        return rtrim(rtrim(number_format((float) Money::round($quantity), 2, ',', '.'), '0'), ',');
    }
}
