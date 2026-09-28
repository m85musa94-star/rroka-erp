<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Tells the database who is acting so audit_log rows carry the user id.
 * The setting is transaction-local (set_config(..., true)): it can never leak to
 * another request, even through a transaction-mode connection pooler.
 * Must be called inside an open transaction.
 */
class AuditContext
{
    public static function apply(?int $userId): void
    {
        if ($userId !== null) {
            DB::select("SELECT set_config('rroka.user_id', ?, true)", [(string) $userId]);
        }
    }
}
