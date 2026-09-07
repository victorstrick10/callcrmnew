<?php

namespace App\Services;

use App\Models\Company;
use Illuminate\Support\Collection;

/**
 * Resolves the active "office / tree" workspace for the current request from a
 * cookie, and exposes the companies + company IDs that belong to it. All the
 * data-viewing surfaces (dashboard, clients, call stats, profile numbers) scope
 * their queries to the active tree so each office's calls/leads/numbers stay
 * completely separate — while every action still works on both offices.
 */
class TreeContext
{
    public const COOKIE = 'active_tree';

    private ?string $override = null;

    /** Force a specific tree for this request (used right after switching). */
    public function setActive(?string $tree): void
    {
        $this->override = $tree !== null ? strtolower(trim($tree)) : null;
    }

    /**
     * Distinct office trees that exist, as slug => label (e.g. off1 => Off1).
     *
     * @return array<string,string>
     */
    public function trees(): array
    {
        $slugs = Company::query()
            ->selectRaw("lower(coalesce(nullif(trim(tree), ''), 'off1')) as t")
            ->distinct()
            ->pluck('t')
            ->filter()
            ->sort()
            ->values();

        if ($slugs->isEmpty()) {
            $slugs = collect(['off1']);
        }

        $out = [];
        foreach ($slugs as $slug) {
            $out[$slug] = Company::treeLabel($slug);
        }

        return $out;
    }

    /** The active tree slug from the request cookie, if it is a known tree. */
    public function active(): ?string
    {
        $trees = $this->trees();

        $candidate = $this->override ?? request()?->cookie(self::COOKIE);
        $candidate = is_string($candidate) ? strtolower(trim($candidate)) : '';

        if ($candidate !== '' && isset($trees[$candidate])) {
            return $candidate;
        }

        // Auto-select when there is only one tree.
        if (count($trees) === 1) {
            return array_key_first($trees);
        }

        return null;
    }

    public function activeLabel(): string
    {
        $active = $this->active();

        return $active ? Company::treeLabel($active) : '';
    }

    /** True when there are multiple trees and none is selected yet. */
    public function needsSelection(): bool
    {
        return count($this->trees()) > 1 && $this->active() === null;
    }

    /** Companies in the active tree (or all companies when no tree is active). */
    public function companies(): Collection
    {
        $active = $this->active();
        $q = Company::query()->orderBy('name');

        return $active ? $q->inTree($active)->get() : $q->get();
    }

    /**
     * Company IDs in the active tree, or null when no tree is active (meaning
     * "do not scope" — e.g. CLI jobs, webhooks, or a single-tree install).
     *
     * @return list<int>|null
     */
    public function companyIds(): ?array
    {
        $active = $this->active();
        if ($active === null) {
            return null;
        }

        return Company::query()->inTree($active)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }
}
