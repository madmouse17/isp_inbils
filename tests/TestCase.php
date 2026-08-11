<?php

namespace Tests;

use App\Services\Core\CompanyService;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function tearDown(): void
    {
        CompanyService::resetCache();

        parent::tearDown();
    }
}
