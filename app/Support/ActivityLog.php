<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Human-readable activity feed ("chatter") built from the immutable audit_log.
 */
class ActivityLog
{
    private const IGNORED = ['id', 'created_at', 'updated_at', 'created_by', 'remember_token', 'password'];

    /**
     * @param  array<string, list<int>>  $records  table => ids
     */
    public static function for(array $records, int $limit = 50): Collection
    {
        $rows = DB::table('audit_log as a')
            ->leftJoin('users as u', 'u.id', '=', 'a.user_id')
            ->where(function ($q) use ($records) {
                foreach ($records as $table => $ids) {
                    if ($ids) {
                        $q->orWhere(fn ($w) => $w->where('a.table_name', $table)->whereIn('a.row_id', $ids));
                    }
                }
            })
            ->orderByDesc('a.id')
            ->limit($limit)
            ->get(['a.*', 'u.name as user_name']);

        return $rows->map(fn ($r) => self::describe($r))->filter()->values();
    }

    private static function describe(object $r): ?array
    {
        $old = $r->old_data ? json_decode($r->old_data, true) : [];
        $new = $r->new_data ? json_decode($r->new_data, true) : [];
        $entity = __("rroka.entities.{$r->table_name}");

        $changes = [];
        if ($r->action === 'UPDATE') {
            foreach ($new as $field => $value) {
                if (in_array($field, self::IGNORED, true) || ($old[$field] ?? null) == $value) {
                    continue;
                }
                $changes[] = [self::label($r->table_name, $field), self::value($field, $old[$field] ?? null), self::value($field, $value)];
            }
            if (! $changes) {
                return null;
            }
        }

        $summary = match ($r->action) {
            'INSERT' => $r->table_name === 'quotation_lines'
                ? __('أضاف بندًا: ').($new['description'] ?? '')
                : __('أنشأ :entity', ['entity' => $entity]),
            'DELETE' => $r->table_name === 'quotation_lines'
                ? __('حذف بندًا: ').($old['description'] ?? '')
                : __('حذف :entity', ['entity' => $entity]),
            default => __('عدّل :entity', ['entity' => $entity]),
        };

        return [
            'user' => $r->user_name ?? __('النظام'),
            'system' => $r->user_name === null,
            'at' => Carbon::parse($r->at)->timezone(config('app.timezone')),
            'summary' => $summary,
            'changes' => $changes,
        ];
    }

    private static function label(string $table, string $field): string
    {
        $key = "rroka.fields.$field";
        $t = __($key);

        return $t === $key ? $field : $t;
    }

    /** @var array<int|string, string> */
    private static array $users = [];

    private static function value(string $field, mixed $v): string
    {
        if ($v === null || $v === '') {
            return '—';
        }
        if ($field === 'status') {
            return __("rroka.status.$v");
        }
        // Who did it: show the user's name, not their id.
        if (in_array($field, ['approved_by', 'released_by', 'manager_id', 'created_by', 'inspector_id', 'uploaded_by'], true) && is_numeric($v)) {
            return self::$users[$v] ??= (string) (DB::table('users')->where('id', $v)->value('name') ?? $v);
        }
        if ($field === 'category') {
            return __("rroka.studio_category.$v");
        }
        if (is_bool($v)) {
            return $v ? __('نعم') : __('لا');
        }
        if (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}T/', $v)) {
            return Carbon::parse($v)->timezone(config('app.timezone'))->format('Y-m-d H:i');
        }

        return is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE);
    }
}
