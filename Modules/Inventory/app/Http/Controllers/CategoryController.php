<?php

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Modules\Inventory\Http\Requests\StoreCategoryRequest;
use Modules\Inventory\Http\Requests\UpdateCategoryRequest;
use Modules\Inventory\Http\Resources\CategoryResource;
use Modules\Inventory\Http\Resources\UnitResource;
use Modules\Inventory\Models\Category;
use Modules\Inventory\Models\Unit;

class CategoryController extends Controller
{
    public function index(Request $request): InertiaResponse
    {
        Gate::authorize('viewAny', Category::class);

        $categories = Category::query()
            ->with('unit')
            ->withCount('children')
            ->when($request->input('search'), fn ($q, $v) => $q->where(fn ($sq) => $sq
                ->where('name', 'like', "%{$v}%")
                ->orWhere('code', 'like', "%{$v}%")))
            ->orderBy('name')
            ->paginate(10)
            ->withQueryString();

        return Inertia::render('Admin/Inventory/Categories/Index', [
            'categories' => CategoryResource::collection($categories),
            'units' => UnitResource::collection(Unit::query()->orderBy('name')->get()),
            'can' => ['create' => $request->user()?->can('inventory.create') ?? false],
        ]);
    }

    public function store(StoreCategoryRequest $request): RedirectResponse
    {
        Gate::authorize('store', Category::class);
        Category::create($request->validated());

        return back()->with('success', 'Category created.');
    }

    public function update(UpdateCategoryRequest $request, Category $category): RedirectResponse
    {
        Gate::authorize('update', $category);
        $category->update($request->validated());

        return back()->with('success', 'Category updated.');
    }

    public function destroy(Category $category): RedirectResponse
    {
        Gate::authorize('delete', $category);

        if ($category->children()->exists() || $category->products()->exists()) {
            return back()->withErrors(['category' => 'Cannot delete category with children or products.']);
        }

        $category->delete();

        return back()->with('success', 'Category deleted.');
    }
}
