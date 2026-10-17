<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Platform (operator) routes
|--------------------------------------------------------------------------
|
| Everything under /platform, named platform.*, in the dedicated `platform`
| middleware group (PlatformServiceProvider). PLATFORM_DOMAIN, when set, binds
| the whole panel to that host. Each ticket adds its own file under
| routes/platform/ (sorted by name, loaded here); this shell is not edited for
| that.
|
*/

$platform = Route::middleware('platform')->prefix('platform')->name('platform.');

if (is_string($domain = config('platform.domain')) && $domain !== '') {
    $platform = $platform->domain($domain);
}

$platform->group(function (): void {
    foreach (glob(__DIR__.'/platform/*.php') ?: [] as $file) {
        require $file;
    }
});
