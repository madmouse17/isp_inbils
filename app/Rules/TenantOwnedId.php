<?php

namespace App\Rules;

/**
 * Card-facing alias for company-owned scalar FK existence.
 *
 * Prefer BelongsToCompany in new code; this alias keeps P0-A acceptance language stable.
 */
class TenantOwnedId extends BelongsToCompany {}
