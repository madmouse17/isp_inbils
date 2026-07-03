<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\NumberSequenceResource;
use App\Models\Core\NumberSequence;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class NumberSequenceController extends Controller
{
    public function index(): InertiaResponse
    {
        Gate::authorize('viewAny', NumberSequence::class);
        $sequences = NumberSequence::query()->orderBy('entity_type')->get();
        return Inertia::render('Admin/NumberSequences/Index', [
            'sequences' => NumberSequenceResource::collection($sequences),
        ]);
    }

    public function update(Request $request, NumberSequence $number_sequence): RedirectResponse
    {
        Gate::authorize('update', $number_sequence);
        $request->validate([
            'prefix' => ['required', 'string', 'max:20'],
            'next_number' => ['required', 'integer', 'min:1'],
            'padding' => ['required', 'integer', 'min:1', 'max:10'],
            'year_suffix' => ['boolean'],
        ]);
        $number_sequence->update($request->only(['prefix', 'next_number', 'padding', 'year_suffix']));
        return back()->with('success', 'Number sequence updated.');
    }
}
