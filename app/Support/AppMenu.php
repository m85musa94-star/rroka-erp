<?php

namespace App\Support;

use App\Models\User;

/**
 * The app launcher (home grid) and top bar read from this single list.
 * Planned modules are shown greyed as "قريبًا" so the roadmap is visible.
 */
class AppMenu
{
    /** @return list<array{key:string,label:string,route:?string,match:?string,permission:?string,group:string}> */
    public static function all(): array
    {
        return [
            ['key' => 'clients', 'label' => 'العملاء', 'route' => 'clients.index', 'match' => 'clients.*', 'permission' => 'clients.view', 'group' => 'sales'],
            ['key' => 'quotations', 'label' => 'عروض الأسعار', 'route' => 'quotations.index', 'match' => 'quotations.*', 'permission' => 'quotations.view', 'group' => 'sales'],
            ['key' => 'projects', 'label' => 'المشاريع', 'route' => 'projects.index', 'match' => 'projects.*', 'permission' => 'projects.view', 'group' => 'ops'],
            ['key' => 'designs', 'label' => 'التصاميم', 'route' => null, 'match' => null, 'permission' => null, 'group' => 'ops'],
            ['key' => 'inventory', 'label' => 'المخزون', 'route' => null, 'match' => null, 'permission' => null, 'group' => 'ops'],
            ['key' => 'production', 'label' => 'الإنتاج', 'route' => null, 'match' => null, 'permission' => null, 'group' => 'ops'],
            ['key' => 'quality', 'label' => 'الجودة', 'route' => null, 'match' => null, 'permission' => null, 'group' => 'ops'],
            ['key' => 'installation', 'label' => 'التركيب', 'route' => null, 'match' => null, 'permission' => null, 'group' => 'ops'],
            ['key' => 'rates', 'label' => 'معدلات التكلفة', 'route' => 'rates.index', 'match' => 'rates.*', 'permission' => 'settings.cost_rates', 'group' => 'settings'],
            ['key' => 'users', 'label' => 'المستخدمون', 'route' => 'users.index', 'match' => 'users.*', 'permission' => 'users.manage', 'group' => 'settings'],
            ['key' => 'roles', 'label' => 'الأدوار والصلاحيات', 'route' => 'roles.index', 'match' => 'roles.*', 'permission' => 'users.manage', 'group' => 'settings'],
        ];
    }

    /** Built apps the user may open first, then planned ones (never clickable). */
    public static function forUser(User $user): array
    {
        $built = array_filter(self::all(), fn ($a) => $a['route'] !== null && $user->hasPermission($a['permission']));
        $planned = array_filter(self::all(), fn ($a) => $a['route'] === null);

        return array_values([...$built, ...$planned]);
    }

    /**
     * Sections shown next to the app name in the top bar: [label, url, active].
     * Settings apps share one menu, like Odoo's Settings.
     */
    public static function menu(?array $app, User $user): array
    {
        if (! $app) {
            return [];
        }
        $defs = match ($app['group']) {
            'settings' => [
                ['معدلات التكلفة', 'rates.index', [], 'settings.cost_rates'],
                ['المستخدمون', 'users.index', [], 'users.manage'],
                ['الأدوار والصلاحيات', 'roles.index', [], 'users.manage'],
            ],
            default => match ($app['key']) {
                'clients' => [
                    ['كل العملاء', 'clients.index', [], 'clients.view'],
                    ['المنشآت', 'clients.index', ['f' => ['company']], 'clients.view'],
                    ['الأفراد', 'clients.index', ['f' => ['individual']], 'clients.view'],
                ],
                'quotations' => [
                    ['كل العروض', 'quotations.index', [], 'quotations.view'],
                    ['بانتظار رد العميل', 'quotations.index', ['f' => ['sent']], 'quotations.view'],
                    ['مراحل العروض', 'quotations.index', ['v' => 'kanban'], 'quotations.view'],
                    ['العملاء', 'clients.index', [], 'clients.view'],
                ],
                'projects' => [
                    ['كل المشاريع', 'projects.index', [], 'projects.view'],
                    ['الجارية', 'projects.index', ['f' => ['open']], 'projects.view'],
                    ['مراحل المشاريع', 'projects.index', ['v' => 'kanban'], 'projects.view'],
                ],
                default => [],
            },
        };

        $items = [];
        foreach ($defs as [$label, $route, $params, $perm]) {
            if (! $user->hasPermission($perm)) {
                continue;
            }
            $url = route($route, $params);
            $items[] = [$label, $url, self::isActive($route, $params)];
        }
        // Only one item is active: the most specific match.
        $hits = array_keys(array_filter($items, fn ($i) => $i[2]));
        if (count($hits) > 1) {
            foreach ($hits as $idx) {
                $items[$idx][2] = false;
            }
            $items[end($hits)][2] = true;
        }

        return $items;
    }

    private static function isActive(string $route, array $params): bool
    {
        if (! request()->routeIs(str_replace('.index', '.*', $route))) {
            return false;
        }
        foreach ($params as $k => $v) {
            if (request()->query($k) != $v) {
                return false;
            }
        }

        return true;
    }

    public static function current(): ?array
    {
        foreach (self::all() as $app) {
            if ($app['match'] && request()->routeIs($app['match'])) {
                return $app;
            }
        }

        return null;
    }
}
