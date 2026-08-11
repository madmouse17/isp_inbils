<?php

namespace App\Rules;

/**
 * Card-facing alias for allowlisted morph + same-company existence.
 *
 * Prefer PolymorphicBelongsToCompany when the morph wording is clearer;
 * this alias keeps P0-A acceptance language stable.
 */
class TenantOwnedMorph extends PolymorphicBelongsToCompany {}
