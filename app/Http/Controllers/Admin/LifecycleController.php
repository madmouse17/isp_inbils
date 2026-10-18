<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Core\LifecycleService;
use App\Traits\HasLifecycle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use LogicException;

/**
 * Deactivate, activate and restore for every model that uses HasLifecycle. The model is a route default set by the
 * route file, never input; the record is found through the tenant scope, so another company's id answers 404.
 */
final class LifecycleController extends Controller
{
    /**
     * Registers POST {uri}/{record}/{action} for each action, named "{name ?? uri}.{action}". Call it inside a route
     * file's admin group.
     *
     * @param  class-string<Model>  $model
     * @param  list<'deactivate'|'activate'|'restore'>  $actions
     */
    public static function routes(string $uri, string $model, array $actions = ['deactivate', 'activate', 'restore'], ?string $name = null): void
    {
        foreach ($actions as $action) {
            Route::post($uri.'/{record}/'.$action, [self::class, $action])
                ->whereUlid('record')
                ->defaults('lifecycleModel', $model)
                ->name(($name ?? $uri).'.'.$action);
        }
    }

    public function deactivate(string $record, string $lifecycleModel): RedirectResponse
    {
        $model = $this->resolve($record, $lifecycleModel);

        Gate::authorize('update', $model);
        LifecycleService::deactivate($model);

        return back()->with('success', $lifecycleModel::lifecycleNoun().' dinonaktifkan.');
    }

    public function activate(string $record, string $lifecycleModel): RedirectResponse
    {
        $model = $this->resolve($record, $lifecycleModel);

        Gate::authorize('update', $model);
        LifecycleService::activate($model);

        return back()->with('success', $lifecycleModel::lifecycleNoun().' diaktifkan.');
    }

    public function restore(string $record, string $lifecycleModel): RedirectResponse
    {
        $model = $this->resolve($record, $lifecycleModel, withTrashed: true);

        Gate::authorize('restore', $model);
        LifecycleService::restore($model);

        return back()->with('success', $lifecycleModel::lifecycleNoun().' dipulihkan.');
    }

    /** @param  class-string<Model>  $lifecycleModel */
    private function resolve(string $record, string $lifecycleModel, bool $withTrashed = false): Model
    {
        if (! in_array(HasLifecycle::class, class_uses_recursive($lifecycleModel), true)) {
            throw new LogicException($lifecycleModel.' must use HasLifecycle.');
        }

        return $lifecycleModel::query()
            ->when($withTrashed, fn (Builder $query) => $query->withTrashed())
            ->wherePublicId($record)
            ->firstOrFail();
    }
}
