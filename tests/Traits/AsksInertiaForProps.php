<?php

namespace Tests\Traits;

use App\Http\Middleware\HandleInertiaRequests;

/** The headers of an Inertia partial reload, which is how a page asks for an optional prop such as `<field>Options`. */
trait AsksInertiaForProps
{
    /** @return array<string, string> headers of a partial reload that asks for one prop */
    private function partialReload(string $component, string $prop): array
    {
        return [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
            'X-Inertia-Partial-Component' => $component,
            'X-Inertia-Partial-Data' => $prop,
        ];
    }
}
