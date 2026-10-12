<?php

namespace Tests\Feature\NetworkAsset;

use Modules\NetworkAsset\Services\RouterOsClient;

/** The REST client (RouterOS 7.1+) against the shared contract. */
class RouterOsRestContractTest extends RouterOsApiContractTestCase
{
    protected function protocol(): string
    {
        return 'rest';
    }

    protected function port(): int
    {
        return 443;
    }

    protected function expectedClient(): string
    {
        return RouterOsClient::class;
    }
}
