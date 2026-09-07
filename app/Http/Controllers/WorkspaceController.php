<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Services\TreeContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cookie;
use Illuminate\View\View;

class WorkspaceController extends Controller
{
    /** ~1 year cookie for the selected office/tree. */
    private const TTL = 60 * 24 * 365;

    /**
     * First-visit chooser: pick which office/tree (workspace) to view. All
     * dashboards, clients, call stats and numbers are scoped to the choice.
     */
    public function choose(TreeContext $tree): View|RedirectResponse
    {
        $trees = $tree->trees();

        // Nothing to choose (single office) → go straight in.
        if (count($trees) <= 1) {
            return redirect()->route('dashboard');
        }

        $companiesByTree = [];
        foreach ($trees as $slug => $label) {
            $companiesByTree[$slug] = Company::query()->inTree($slug)->orderBy('name')->pluck('name')->all();
        }

        return view('workspace.choose', [
            'trees' => $trees,
            'active' => $tree->active(),
            'companiesByTree' => $companiesByTree,
        ]);
    }

    /** Select/switch the active office and remember it in a cookie. */
    public function select(string $tree, TreeContext $context): RedirectResponse
    {
        $tree = strtolower(trim($tree));
        $trees = $context->trees();

        if (! isset($trees[$tree])) {
            return redirect()->route('workspace.choose')->with('danger', 'Unknown office.');
        }

        Cookie::queue(TreeContext::COOKIE, $tree, self::TTL);
        $context->setActive($tree);

        return redirect()->route('dashboard')->with('success', 'Switched to '.$trees[$tree].'.');
    }
}
