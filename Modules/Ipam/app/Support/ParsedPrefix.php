<?php

namespace Modules\Ipam\Support;

/**
 * A CIDR prefix as ip-lib normalised it. `from` and `to` are comparable strings: fixed width, so a plain
 * string comparison (and a SQL BETWEEN on an ascii_bin column) says whether an address lies inside.
 */
final readonly class ParsedPrefix
{
    public function __construct(
        public string $cidr,
        public int $family,
        public string $from,
        public string $to,
        public int $length,
    ) {}

    public function contains(string $comparable): bool
    {
        return IpNotation::between($comparable, $this->from, $this->to);
    }

    public function containsPrefix(self $other): bool
    {
        return $this->family === $other->family
            && strcmp($this->from, $other->from) <= 0
            && strcmp($this->to, $other->to) >= 0;
    }
}
