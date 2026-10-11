<?php

namespace Modules\Ipam\Support;

/** An IP address as ip-lib normalised it: canonical text plus the comparable string stored in `address_cmp`. */
final readonly class ParsedAddress
{
    public function __construct(
        public string $address,
        public int $family,
        public string $comparable,
    ) {}
}
