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
