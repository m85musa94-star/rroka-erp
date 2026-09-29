<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** `permission:a` or `permission:a|b` (any one of them). */
class RequirePermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        if (! $user || ! $user->is_active || ! collect(explode('|', $permission))->contains(fn ($p) => $user->hasPermission($p))) {
            if (! $request->expectsJson() && ! $request->is('api/*')) {
                abort(403, __('ليست لديك صلاحية لهذه العملية.'));
            }

            return response()->json(['error' => 'FORBIDDEN', 'permission' => $permission], 403);
        }

        return $next($request);
    }
}
