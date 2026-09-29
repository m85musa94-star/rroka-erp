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
            ['key' => 'clients', 'label' => __('العملاء'), 'route' => 'clients.index', 'match' => 'clients.*', 'permission' => 'clients.view', 'group' => 'sales'],
            ['key' => 'quotations', 'label' => __('عروض الأسعار'), 'route' => 'quotations.index', 'match' => 'quotations.*', 'permission' => 'quotations.view', 'group' => 'sales'],
            ['key' => 'projects', 'label' => __('المشاريع'), 'route' => 'projects.index', 'match' => 'projects.*', 'permission' => 'projects.view', 'group' => 'ops'],
            ['key' => 'studio', 'label' => __('الاستوديو'), 'route' => 'studio.index', 'match' => 'studio.*', 'permission' => 'studio.view', 'group' => 'ops'],
            ['key' => 'reports', 'label' => __('التقارير'), 'route' => 'reports.index', 'match' => 'reports.*', 'permission' => ['quotations.view', 'projects.view', 'costing.view'], 'group' => 'reports'],
            ['key' => 'production', 'label' => __('التصنيع'), 'route' => 'production.index', 'match' => ['production.*', 'designs.*', 'design-versions.*'], 'permission' => ['production.manage', 'production.log_time', 'quality.inspect', 'designs.manage', 'designs.release', 'bom.manage'], 'group' => 'mrp'],
            ['key' => 'inventory', 'label' => __('المخزون'), 'route' => 'materials.index', 'match' => 'materials.*', 'permission' => ['inventory.view', 'inventory.move'], 'group' => 'mrp'],
            ['key' => 'quality', 'label' => __('الجودة'), 'route' => null, 'match' => null, 'permission' => null, 'group' => 'ops'],
            ['key' => 'installation', 'label' => __('التركيب'), 'route' => null, 'match' => null, 'permission' => null, 'group' => 'ops'],
            ['key' => 'rates', 'label' => __('معدلات التكلفة'), 'route' => 'rates.index', 'match' => 'rates.*', 'permission' => 'settings.cost_rates', 'group' => 'settings'],
            ['key' => 'daftra', 'label' => __('الربط مع دفترة'), 'route' => 'daftra.index', 'match' => 'daftra.*', 'permission' => 'daftra.sync', 'group' => 'settings'],
            ['key' => 'users', 'label' => __('المستخدمون'), 'route' => 'users.index', 'match' => 'users.*', 'permission' => 'users.manage', 'group' => 'settings'],
            ['key' => 'roles', 'label' => __('الأدوار والصلاحيات'), 'route' => 'roles.index', 'match' => 'roles.*', 'permission' => 'users.manage', 'group' => 'settings'],
        ];
    }

    /** Built apps the user may open first, then planned ones (never clickable). */
    public static function forUser(User $user): array
    {
        $built = array_filter(self::all(), fn ($a) => $a['route'] !== null && self::can($user, $a['permission']));
        $planned = array_filter(self::all(), fn ($a) => $a['route'] === null);

        return array_values([...$built, ...$planned]);
    }

    /** A string permission, or any of an array of permissions. */
    public static function can(User $user, string|array $permission): bool
    {
        foreach ((array) $permission as $p) {
            if ($user->hasPermission($p)) {
                return true;
            }
        }

        return false;
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
            'reports' => [
                ['كل التقارير', 'reports.index', [], ['quotations.view', 'projects.view', 'costing.view']],
                ['عروض الأسعار', 'reports.show', ['key' => 'quotations'], 'quotations.view'],
                ['المشاريع', 'reports.show', ['key' => 'projects'], 'projects.view'],
                ['الربحية', 'reports.show', ['key' => 'profitability'], 'costing.view'],
            ],
            'mrp' => [
                ['أوامر التصنيع', 'production.index', [], ['production.manage', 'production.log_time', 'quality.inspect', 'projects.view']],
                ['التصاميم وقوائم المواد', 'designs.index', [], ['designs.manage', 'designs.release', 'bom.manage', 'projects.view']],
                ['الخامات والمخزون', 'materials.index', [], ['inventory.view', 'inventory.move']],
                ['الآلات ومعدلات التكلفة', 'rates.index', [], 'settings.cost_rates'],
            ],
            'settings' => [
                ['معدلات التكلفة', 'rates.index', [], 'settings.cost_rates'],
                ['الربط مع دفترة', 'daftra.index', [], 'daftra.sync'],
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
                    ['التقارير', 'reports.show', ['key' => 'quotations'], 'quotations.view'],
                ],
                'projects' => [
                    ['كل المشاريع', 'projects.index', [], 'projects.view'],
                    ['الجارية', 'projects.index', ['f' => ['open']], 'projects.view'],
                    ['مراحل المشاريع', 'projects.index', ['v' => 'kanban'], 'projects.view'],
                    ['التقارير', 'reports.show', ['key' => 'projects'], 'projects.view'],
                    ['الربحية', 'reports.show', ['key' => 'profitability'], 'costing.view'],
                ],
                'studio' => [
                    ['كل الصور', 'studio.index', [], 'studio.view'],
                    ['صور العملاء', 'studio.index', ['f' => ['client_reference']], 'studio.view'],
                    ['أعمال منجزة', 'studio.index', ['f' => ['finished_work']], 'studio.view'],
                    ['رفع صور', 'studio.create', [], 'studio.manage'],
                ],
                default => [],
            },
        };

        $items = [];
        foreach ($defs as [$label, $route, $params, $perm]) {
            if (! self::can($user, $perm)) {
                continue;
            }
            $url = route($route, $params);
            $items[] = [__($label), $url, self::isActive($route, $params)];
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
        if ($route === 'reports.show') {
            return request()->routeIs('reports.show') && request()->route('key') === $params['key'];
        }
        if ($route === 'reports.index') {
            return request()->routeIs('reports.index');
        }
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
            if ($app['match'] && request()->routeIs(...(array) $app['match'])) {
                return $app;
            }
        }

        return null;
    }
}
