<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;

/**
 * The package form's "+ Baru" dialog (QuickCreateDialog.tsx) creates a master row without leaving the page: it posts to
 * the store route with this header and wants the new row back. Every other client, one that asks for JSON included,
 * gets the redirect of a form post.
 */
trait AnswersInlineCreate
{
    private function wantsInlineRow(Request $request): bool
    {
        return $request->hasHeader('X-Inline-Create');
    }
}
