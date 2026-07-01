<?php

namespace App\Events;

use App\Models\Core\Company;
use Illuminate\Foundation\Events\Dispatchable;

class CompanyCreated
{
    use Dispatchable;

    public function __construct(public readonly Company $company) {}
}
