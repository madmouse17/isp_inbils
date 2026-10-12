<?php

namespace Tests\Feature\NetworkAsset;

use Modules\NetworkAsset\Services\RouterOsBinaryClient;

/** The binary API client (RouterOS 6 and 7, API-SSL on 8729) against the shared contract. */
class RouterOsBinaryContractTest extends RouterOsApiContractTestCase
{
    protected function protocol(): string
    {
        return 'binary';
    }

    protected function port(): int
    {
        return 8729;
    }

    protected function expectedClient(): string
    {
        return RouterOsBinaryClient::class;
    }
}
