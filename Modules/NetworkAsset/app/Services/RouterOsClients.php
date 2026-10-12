<?php

namespace Modules\NetworkAsset\Services;

use Illuminate\Contracts\Container\Container;
use Modules\NetworkAsset\Models\NasDevice;

/** Picks the API client of one router by its api_protocol: a router never mixes protocols. */
class RouterOsClients
{
    public function __construct(private readonly Container $container) {}

    public function for(NasDevice $nas): RouterOsApi
    {
        return $this->container->make($nas->api_protocol === 'binary' ? RouterOsBinaryClient::class : RouterOsClient::class);
    }
}
