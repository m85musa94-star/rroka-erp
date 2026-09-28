<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Odoo-style list state shared by every index screen: search (?q), filters
 * (?f[]=key, OR-ed inside a filter group, AND-ed across groups), group-by (?g)
 * and view mode (?v=list|kanban). Controllers declare what is available;
 * the control-panel partial renders it.
 */
class ListView
{
    public string $q;

    /** @var list<string> */
    public array $active;

    public ?string $group;

    public string $view;

    /**
     * @param  array<string, array{label:string, group:string, apply:callable}>  $filters
     * @param  array<string, array{label:string, key:callable, title:callable}>  $groups
     * @param  list<string>  $views
     */
    public function __construct(
        public Request $request,
        public array $filters = [],
        public array $groups = [],
        public array $views = ['list'],
        public ?string $defaultView = null,
    ) {
        $this->q = trim((string) $request->query('q', ''));
        $this->active = array_values(array_intersect((array) $request->query('f', []), array_keys($filters)));
        $g = $request->query('g');
        $this->group = is_string($g) && isset($groups[$g]) ? $g : null;
        $v = $request->query('v');
        $this->view = is_string($v) && in_array($v, $views, true) ? $v : ($defaultView ?? $views[0]);
    }

    public function applyFilters(Builder $query): Builder
    {
        $byGroup = [];
        foreach ($this->active as $key) {
            $byGroup[$this->filters[$key]['group']][] = $this->filters[$key]['apply'];
        }
        foreach ($byGroup as $appliers) {
            $query->where(function ($w) use ($appliers) {
                foreach ($appliers as $apply) {
                    $w->orWhere(fn ($sub) => $apply($sub));
                }
            });
        }

        return $query;
    }

    /** Group rows for the list view: [title => rows]. */
    public function grouped(Collection $rows): Collection
    {
        $def = $this->groups[$this->group];

        return $rows->groupBy(fn ($r) => (string) $def['key']($r))
            ->mapWithKeys(fn ($items) => [$def['title']($items->first()) => $items]);
    }

    /** Current URL with some parameters changed (null removes). */
    public function url(array $changes): string
    {
        $params = array_filter([
            'q' => $this->q ?: null,
            'f' => $this->active ?: null,
            'g' => $this->group,
            'v' => $this->view !== ($this->defaultView ?? $this->views[0]) ? $this->view : null,
        ]);
        foreach ($changes as $k => $v) {
            if ($v === null) {
                unset($params[$k]);
            } else {
                $params[$k] = $v;
            }
        }
        $qs = http_build_query($params);

        return $this->request->url().($qs ? '?'.$qs : '');
    }

    public function toggleFilterUrl(string $key): string
    {
        $active = in_array($key, $this->active, true)
            ? array_values(array_diff($this->active, [$key]))
            : [...$this->active, $key];

        return $this->url(['f' => $active ?: null, 'page' => null]);
    }

    public function isFiltered(): bool
    {
        return $this->q !== '' || $this->active !== [] || $this->group !== null;
    }
}
