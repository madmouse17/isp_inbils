<?php

namespace App\Http\Requests\Platform\Tenants;

/** Only the typed company code: the gates of a deletion request (30 days, a finished export) are the service's. */
class RequestTenantDeletionRequest extends ConfirmedTenantRequest {}
