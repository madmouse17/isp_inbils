<?php

namespace Modules\Ipam\Support;

use IPLib\Factory;
use IPLib\Range\Subnet;

/**
 * The only place that parses or formats IP text (mlocati/ip-lib). Services store and compare what it returns.
 */
final class IpNotation
{
    /** Prefix in CIDR form only; a bare address or a range pattern is not a prefix. Host bits are cleared. */
    public static function prefix(string $input): ?ParsedPrefix
    {
        $subnet = Subnet::parseString(trim($input));

        if ($subnet === null) {
            return null;
        }

        return new ParsedPrefix(
            $subnet->toString(),
            $subnet->getAddressType(),
            $subnet->getComparableStartString(),
            $subnet->getComparableEndString(),
            (int) $subnet->getNetworkPrefix(),
        );
    }

    /** A single address: no port, zone, prefix length or IPv4-mapped IPv6 form (it would dodge the IPv4 conflict check). */
    public static function address(string $input): ?ParsedAddress
    {
        $address = Factory::parseAddressString(trim($input));

        if ($address === null || str_starts_with($address->getComparableString(), '0000:0000:0000:0000:0000:ffff:')) {
            return null;
        }

        return new ParsedAddress($address->toString(), $address->getAddressType(), $address->getComparableString());
    }

    /** Exact number of addresses in a stored CIDR, as a decimal string (IPv6 sizes exceed PHP integers). */
    public static function size(string $cidr): string
    {
        $subnet = Subnet::parseString($cidr);

        return $subnet === null ? '0' : (string) $subnet->getExactSize();
    }

    public static function between(string $comparable, string $from, string $to): bool
    {
        return strcmp($comparable, $from) >= 0 && strcmp($comparable, $to) <= 0;
    }

    /** Works on canonical dotted text and on the zero-padded comparable form alike. */
    public static function ipv4ToInt(string $text): int
    {
        [$a, $b, $c, $d] = array_map('intval', explode('.', $text));

        return ($a << 24) | ($b << 16) | ($c << 8) | $d;
    }

    public static function ipv4FromInt(int $number): string
    {
        return (($number >> 24) & 255).'.'.(($number >> 16) & 255).'.'.(($number >> 8) & 255).'.'.($number & 255);
    }
}
