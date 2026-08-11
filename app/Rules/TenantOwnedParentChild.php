<?php

namespace App\Rules;

/**
 * Card-facing alias for parent/child tenant consistency checks.
 *
 * Prefer MatchesParentAttribute in new code when the attribute-name wording
 * is clearer; this alias keeps P0-A acceptance language stable.
 */
class TenantOwnedParentChild extends MatchesParentAttribute {}
