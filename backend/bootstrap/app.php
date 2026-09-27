<?php

use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\RequirePermission;
use App\Http\Middleware\SetAuditUser;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Hosting platforms terminate HTTPS at their load balancer.
        $middleware->trustProxies(at: '*');
        $middleware->alias([
            'permission' => RequirePermission::class,
            'audit.user' => SetAuditUser::class,
            'active' => EnsureUserIsActive::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Business rules enforced by the database surface as 422 with their RROKA_* code.
        $exceptions->render(function (QueryException $e) {
            $sqlState = $e->errorInfo[0] ?? null;
            $map = [
                'P0001' => 'BUSINESS_RULE',
                '23514' => 'CHECK_VIOLATION',
                '23505' => 'DUPLICATE',
                '23503' => 'INVALID_REFERENCE',
            ];
            if (! isset($map[$sqlState])) {
                return null;
            }

            preg_match('/RROKA_[A-Z0-9_]+/', $e->getMessage(), $m);
            preg_match('/ERROR:\s+(.+?)(?:\n|\(Connection)/', $e->getMessage(), $detail);
            $code = $m[0] ?? $map[$sqlState];

            if (! request()->expectsJson() && ! request()->is('api/*')) {
                return back()->withInput()->withErrors(['rule' => __("rroka.errors.$code")]);
            }

            return response()->json(['error' => $code, 'message' => trim($detail[1] ?? '')], 422);
        });

        // Domain refusals thrown as HttpException('CODE[: detail]') get the same JSON shape.
        $exceptions->render(function (HttpException $e) {
            if (! preg_match('/^([A-Z][A-Z0-9_]+)(?::\s*(.*))?$/s', $e->getMessage(), $m)) {
                return null;
            }

            if (! request()->expectsJson() && ! request()->is('api/*')) {
                return back()->withInput()->withErrors(['rule' => __("rroka.errors.{$m[1]}")]);
            }

            return response()->json(['error' => $m[1], 'message' => $m[2] ?? ''], $e->getStatusCode());
        });
    })->create();
