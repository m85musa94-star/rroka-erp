<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tells the database who is acting, so audit_log rows carry the user id.
 */
class SetAuditUser
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($user = $request->user()) {
            DB::select("SELECT set_config('rroka.user_id', ?, false)", [(string) $user->id]);
        }

        try {
            return $next($request);
        } finally {
            try {
                DB::select("SELECT set_config('rroka.user_id', '', false)");
            } catch (\Throwable) {
                // Connection is inside an aborted transaction; it will be rolled back anyway.
            }
        }
    }
}
