<?php

namespace App\Http\Middleware;

use App\Services\TreeContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate the data-viewing pages until an office/tree is selected. When more than
 * one office exists and none is chosen yet, redirect to the workspace chooser.
 */
class RequireWorkspace
{
    public function __construct(private TreeContext $tree)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->tree->needsSelection()) {
            return redirect()->route('workspace.choose');
        }

        return $next($request);
    }
}
