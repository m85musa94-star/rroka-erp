<?php

namespace App\Http\Middleware;

use App\Support\AuditContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs each write request in one database transaction that carries the acting
 * user for the audit log. Any failed request (exception rendered as a response)
 * is rolled back as a whole, so a request never half-applies.
 *
 * Routes that must commit mid-request (the Daftra sync writes its log before
 * calling out) use `audit.user:manual` and apply AuditContext themselves.
 */
class SetAuditUser
{
    public function handle(Request $request, Closure $next, string $mode = 'transaction'): Response
    {
        // The group applies `audit.user`; a route opting out adds `audit.user:manual`.
        $manual = $mode === 'manual'
            || in_array('audit.user:manual', $request->route()?->gatherMiddleware() ?? [], true);

        if ($manual || $request->isMethodSafe() || ! $request->user()) {
            return $next($request);
        }

        DB::beginTransaction();
        try {
            AuditContext::apply($request->user()->id);
            $response = $next($request);
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        if (isset($response->exception) || $response->getStatusCode() >= 400) {
            DB::rollBack();
        } else {
            DB::commit();
        }

        return $response;
    }
}
