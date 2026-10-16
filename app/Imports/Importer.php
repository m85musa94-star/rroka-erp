<?php

namespace App\Imports;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * Runs the rows of an uploaded template through the screen's own save action.
 *
 * A check (preview) runs every row inside a savepoint that is rolled back: the rows
 * meet exactly the checks and database rules a manual save meets, and nothing stays
 * (the database refuses to commit anything written while `rroka.import_preview` is on,
 * and no document number is used up). The import runs the same rows again for real and
 * keeps them only if every row passes — all rows or none.
 */
class Importer
{
    public const MAX_ROWS = 1000;

    /** The helper column that groups the rows of one document (documents with lines). */
    public const DOC = '_doc';

    public const DOC_LABEL = 'رقم المستند في الملف';

    public const RESULT_LABEL = 'نتيجة الفحص';

    private const DIGITS = ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9'];

    /** The event dispatcher the «created» listener is registered on (a new app has a new one). */
    private static ?int $listening = null;

    private static ?array $created = null;

    public function __construct(private readonly string $key, private readonly User $user) {}

    /** The list separator of the current language. */
    public static function sep(): string
    {
        return app()->getLocale() === 'ar' ? '، ' : ', ';
    }

    public function def(): array
    {
        return ImportRegistry::get($this->key);
    }

    /** Header columns, then line columns; documents with lines start with the grouping column. */
    public static function columns(array $def): array
    {
        $cols = [];
        if (! empty($def['lines'])) {
            $cols[] = Col::text(self::DOC, self::DOC_LABEL, true, 'الأسطر التي تحمل الرقم نفسه مستند واحد', '1');
        }
        foreach ($def['columns'] as $c) {
            $cols[] = $c;
        }
        foreach ($def['lines'] ?? [] as $c) {
            $cols[] = ['line' => true] + $c;
        }

        return $cols;
    }

    /** May this user import into the screen: the save route's own permissions, and the definition's if it names one. */
    public static function may(User $user, array $def): bool
    {
        return self::allowed($user, $def['route'])
            && (empty($def['permission']) || collect(explode('|', $def['permission']))->contains(fn ($p) => $user->hasPermission($p)));
    }

    /** May this user call a route (its permission middleware)? */
    public static function allowed(User $user, string $routeName): bool
    {
        $route = Route::getRoutes()->getByName($routeName);
        if (! $route || ! $user->is_active) {
            return false;
        }
        foreach ($route->gatherMiddleware() as $m) {
            if (is_string($m) && str_starts_with($m, 'permission:')
                && ! collect(explode('|', substr($m, 11)))->contains(fn ($p) => $user->hasPermission($p))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Reads the sheet: the first row names the columns (Arabic or English label, with or
     * without the "*", or the field name); empty rows are skipped.
     *
     * @return array{rows: list<array{row: int, v: array<string, string>}>, ignored: list<string>, missing: list<string>}
     */
    public static function parse(array $def, array $sheet): array
    {
        $cols = self::columns($def);
        $known = [];
        foreach ($cols as $c) {
            foreach ([$c['field'], __($c['label'], [], 'ar'), __($c['label'], [], 'en')] as $name) {
                $known[self::norm($name)] = $c['field'];
            }
        }
        $result = [self::norm(__(self::RESULT_LABEL, [], 'ar')), self::norm(__(self::RESULT_LABEL, [], 'en'))];

        $header = $sheet[0] ?? [];
        $map = $ignored = [];
        foreach ($header as $i => $name) {
            $n = self::norm($name);
            if (isset($known[$n])) {
                $map[$i] = $known[$n];
            } elseif ($n !== '' && ! in_array($n, $result, true)) {
                $ignored[] = trim($name);
            }
        }
        $present = array_flip($map);
        $missing = [];
        foreach ($cols as $c) {
            if ($c['required'] && ! isset($present[$c['field']])) {
                $missing[] = __($c['label']);
            }
        }

        $rows = [];
        foreach (array_slice($sheet, 1, null, true) as $i => $cells) {
            $v = [];
            foreach ($map as $col => $field) {
                $value = trim((string) ($cells[$col] ?? ''));
                if ($value !== '') {
                    $v[$field] = $value;
                }
            }
            if ($v) {
                $rows[] = ['row' => $i + 1, 'v' => $v];
            }
        }

        return ['rows' => $rows, 'ignored' => $ignored, 'missing' => $missing];
    }

    /** "  التاريخ * " → "التاريخ" for matching headers. */
    private static function norm(string $s): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', rtrim(trim($s), '*'))));
    }

    /**
     * Checks the rows without keeping anything.
     *
     * @return array{results: list<array>, errors: int}
     */
    public function check(array $rows): array
    {
        DB::beginTransaction();
        try {
            DB::select("SELECT set_config('rroka.import_preview', 'on', true)");
            DB::insert('INSERT INTO import_preview_marks DEFAULT VALUES');

            return $this->runUnits($rows);
        } finally {
            DB::rollBack();
        }
    }

    /**
     * Imports the rows for real: every row or none.
     *
     * @return array{results: list<array>, errors: int, records: list<array{row: int, table: string, id: int}>}
     */
    public function import(array $rows): array
    {
        DB::beginTransaction();
        try {
            $out = $this->runUnits($rows);
        } catch (Throwable $e) {
            DB::rollBack();
            throw $e;
        }
        if ($out['errors'] > 0) {
            DB::rollBack();
        } else {
            DB::commit();
        }

        return $out;
    }

    /**
     * Approves (or posts) the drafts a batch created, through the screen's approve action;
     * every one or none.
     *
     * @param  list<int>  $ids
     * @return list<array{id: int, ok: bool, messages: list<string>}>
     */
    public function approve(array $ids): array
    {
        [$route, $params] = $this->def()['approve'];
        $results = [];
        $failed = false;
        DB::beginTransaction();
        try {
            foreach ($ids as $id) {
                try {
                    DB::transaction(fn () => $this->call($route, [], $params($id)));
                    $results[] = ['id' => $id, 'ok' => true, 'messages' => []];
                } catch (Throwable $e) {
                    $failed = true;
                    $results[] = ['id' => $id, 'ok' => false, 'messages' => array_column($this->messages($e, []), 'message')];
                }
            }
        } finally {
            $failed ? DB::rollBack() : DB::commit();
        }

        return $results;
    }

    private function runUnits(array $rows): array
    {
        $def = $this->def();
        $this->labels();
        $results = [];
        foreach ($rows as $r) {
            $results[$r['row']] = ['row' => $r['row'], 'ok' => true, 'messages' => []];
        }
        $errors = 0;
        $records = [];
        foreach ($this->units($def, $rows) as $unit) {
            $rowNos = array_column($unit['rows'], 'row');
            try {
                $created = DB::transaction(function () use ($def, $unit) {
                    $input = $this->input($def, $unit);
                    if (isset($def['prepare'])) {
                        $input = ($def['prepare'])($input);
                    }
                    $params = isset($def['params']) ? ($def['params'])($input) : [];

                    return $this->capture(fn () => $this->call($def['route'], self::strip($input), $params), $def['model']);
                });
                if ($created) {
                    $records[] = ['row' => $rowNos[0], 'table' => $created->getTable(), 'id' => (int) $created->getKey()];
                    $results[$rowNos[0]]['record'] = (int) $created->getKey();
                    // Numbers given by the database (defaults) are read back after the insert.
                    $results[$rowNos[0]]['number'] = isset($def['number'])
                        ? (string) ($created->getAttribute($def['number']) ?? DB::table($created->getTable())->where($created->getKeyName(), $created->getKey())->value($def['number']))
                        : null;
                }
            } catch (Throwable $e) {
                foreach ($this->messages($e, $unit) as $m) {
                    $at = $m['row'] ?? $rowNos[0];
                    $results[$at]['ok'] = false;
                    $results[$at]['messages'][] = $m['message'];
                }
                foreach ($rowNos as $n) {
                    if ($results[$n]['ok'] && $n !== $rowNos[0]) {
                        $results[$n]['messages'][] = __('لم يُحفظ لأن مستنده فيه خطأ.');
                    }
                }
            }
        }
        foreach ($results as $r) {
            $errors += $r['ok'] ? 0 : 1;
        }

        return ['results' => array_values($results), 'errors' => $errors, 'records' => $records];
    }

    /** One unit = one record, or one document with its lines (rows sharing the grouping number). */
    private function units(array $def, array $rows): array
    {
        if (empty($def['lines'])) {
            return array_map(fn ($r) => ['rows' => [$r]], $rows);
        }
        $units = [];
        foreach ($rows as $r) {
            $doc = trim((string) ($r['v'][self::DOC] ?? ''));
            $units[$doc === '' ? '#'.$r['row'] : 'd:'.$doc]['rows'][] = $r;
        }

        return array_values($units);
    }

    /** The form input a person would send from the screen. */
    private function input(array $def, array $unit): array
    {
        $first = $unit['rows'][0];
        if (! empty($def['lines']) && trim((string) ($first['v'][self::DOC] ?? '')) === '') {
            throw new ImportError(__('«:col» مطلوب لتجميع أسطر المستند.', ['col' => __(self::DOC_LABEL)]), self::DOC);
        }
        // Every unreadable cell of the document is reported at once, on its own row.
        $errors = [];
        $read = function (array $c, ?string $raw, int $row) use (&$errors) {
            try {
                return $this->convert($c, $raw);
            } catch (ImportError $e) {
                $errors[] = ['row' => $row, 'message' => '«'.__($c['label']).'»: '.$e->getMessage()];

                return null;
            }
        };
        $input = $def['defaults'] ?? [];
        foreach ($def['columns'] as $c) {
            // Header values are read from the document's first row; a different value on a later row is an error.
            foreach (array_slice($unit['rows'], 1) as $r) {
                $later = $r['v'][$c['field']] ?? '';
                if ($later !== '' && $later !== ($first['v'][$c['field']] ?? '')) {
                    $errors[] = ['row' => $r['row'], 'message' => __('«:col» يختلف عن السطر الأول للمستند نفسه.', ['col' => __($c['label'])])];
                }
            }
            $value = $read($c, $first['v'][$c['field']] ?? null, $first['row']);
            if ($value !== null) {
                $input[$c['field']] = $value;
            }
        }
        if (! empty($def['lines'])) {
            $input['lines'] = [];
            foreach ($unit['rows'] as $i => $r) {
                $line = [];
                foreach ($def['lines'] as $c) {
                    $value = $read($c, $r['v'][$c['field']] ?? null, $r['row']);
                    if ($value !== null) {
                        $line[$c['field']] = $value;
                    }
                }
                $input['lines'][$i] = $line;
            }
        }
        if ($errors) {
            throw new RowErrors($errors);
        }

        return $input;
    }

    /** A cell as the form would send it; null for an empty cell. */
    private function convert(array $c, ?string $raw): mixed
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }
        $raw = trim($raw);
        try {
            return match ($c['type']) {
                'number' => self::number($raw),
                'date' => self::date($raw, false),
                'datetime' => self::date($raw, true),
                'bool' => self::bool($raw),
                'enum' => self::enum($c, $raw),
                'lookup' => self::option($c['extra'] ?? [], $raw) ?? Lookups::resolve($c['lookup'], $raw),
                default => $raw,
            };
        } catch (ImportError $e) {
            throw new ImportError($e->getMessage(), $c['field']);
        }
    }

    public static function number(string $raw): string
    {
        $s = strtr(strtr($raw, self::DIGITS), ['٫' => '.', '٬' => '', ',' => '', ' ' => '', 'ر.س' => '', 'SAR' => '']);

        return is_numeric($s) ? $s : $raw;
    }

    /** Excel date cells (serial days since 1899-12-30) or text: 2026-03-15, 15/03/2026, 2026/03/15 (with a time for date-times). */
    public static function date(string $raw, bool $withTime): string
    {
        $s = trim(strtr($raw, self::DIGITS));
        if (is_numeric($s) && (float) $s > 20000 && (float) $s < 80000) {
            $d = Carbon::create(1899, 12, 30, 0, 0, 0, config('app.timezone'))->addSeconds((int) round((float) $s * 86400));

            return $withTime ? $d->format('Y-m-d H:i') : $d->format('Y-m-d');
        }
        $formats = $withTime
            ? ['Y-m-d H:i', 'Y-m-d H:i:s', 'Y/m/d H:i', 'd/m/Y H:i', 'd-m-Y H:i', 'Y-m-d\TH:i', 'Y-m-d\TH:i:s']
            : ['Y-m-d', 'Y/m/d', 'd/m/Y', 'd-m-Y', 'd.m.Y'];
        foreach ($formats as $f) {
            $d = \DateTime::createFromFormat('!'.$f, $s);
            if ($d && $d->format($f) === $s) {
                return $d->format($withTime ? 'Y-m-d H:i' : 'Y-m-d');
            }
        }
        if (preg_match('#^(\d{4})[-/](\d{1,2})[-/](\d{1,2})$#', $s, $m) || preg_match('#^(\d{1,2})[-/](\d{1,2})[-/](\d{4})$#', $s, $m2)) {
            [$y, $mo, $dd] = isset($m[1]) ? [$m[1], $m[2], $m[3]] : [$m2[3], $m2[2], $m2[1]];
            if (checkdate((int) $mo, (int) $dd, (int) $y)) {
                return sprintf('%04d-%02d-%02d', $y, $mo, $dd).($withTime ? ' 00:00' : '');
            }
        }
        throw new ImportError($withTime
            ? __('تاريخ ووقت غير مفهوم «:v»؛ اكتبه هكذا: 2026-03-15 08:00', ['v' => $raw])
            : __('تاريخ غير مفهوم «:v»؛ اكتبه هكذا: 2026-03-15', ['v' => $raw]));
    }

    private static function bool(string $raw): string
    {
        $v = mb_strtolower(trim($raw));
        if (in_array($v, ['1', 'نعم', 'yes', 'y', 'true', 'صح', '✓', 'x'], true)) {
            return '1';
        }
        if (in_array($v, ['0', 'لا', 'no', 'n', 'false', 'خطأ'], true)) {
            return '0';
        }
        throw new ImportError(__('اكتب «نعم» أو «لا» بدل «:v».', ['v' => $raw]));
    }

    private static function enum(array $c, string $raw): string
    {
        return self::option($c['options'], $raw)
            ?? throw new ImportError(__('القيمة «:v» ليست من القيم المقبولة: :list', ['v' => $raw, 'list' => implode(self::sep(), array_map(fn ($l) => __($l), $c['options']))]));
    }

    /** Matches a code or its label in either language. */
    private static function option(array $options, string $raw): ?string
    {
        $v = mb_strtolower(trim($raw));
        foreach ($options as $code => $label) {
            if (in_array($v, [mb_strtolower((string) $code), mb_strtolower(__($label, [], 'ar')), mb_strtolower(__($label, [], 'en'))], true)) {
                return (string) $code;
            }
        }

        return null;
    }

    private static function strip(array $input): array
    {
        foreach ($input as $k => $v) {
            if (str_starts_with((string) $k, '_')) {
                unset($input[$k]);
            } elseif ($k === 'lines' && is_array($v)) {
                $input[$k] = array_map(fn ($l) => array_filter($l, fn ($kk) => ! str_starts_with((string) $kk, '_'), ARRAY_FILTER_USE_KEY), $v);
            }
        }

        return $input;
    }

    /** Calls the screen's save action as if its form were submitted by this user. */
    private function call(string $routeName, array $input, array $params): void
    {
        $route = Route::getRoutes()->getByName($routeName);
        $request = Request::create('/'.ltrim($route->uri(), '/'), 'POST', $input);
        $request->setUserResolver(fn () => $this->user);
        $request->setRouteResolver(fn () => $route);
        if (app()->bound('session.store')) {
            $request->setLaravelSession(app('session.store'));
        }
        $flash = session()->get('_flash.new', []);
        $previous = app('request');
        app()->instance('request', $request);
        try {
            app()->call([app()->make($route->getControllerClass()), $route->getActionMethod()], ['request' => $request] + $params);
        } finally {
            app()->instance('request', $previous);
            // Messages a save flashes for the next page («saved as a draft») are not shown after an import.
            foreach (array_diff(session()->get('_flash.new', []), $flash) as $key) {
                session()->forget($key);
            }
            session()->put('_flash.new', $flash);
        }
    }

    /** The record of the definition's model created by the save (its first one). */
    private function capture(callable $save, ?string $model): mixed
    {
        $dispatcher = spl_object_id(Event::getFacadeRoot());
        if (self::$listening !== $dispatcher) {
            Event::listen('eloquent.created: *', function (string $event, array $data) {
                if (self::$created !== null) {
                    self::$created[] = $data[0];
                }
            });
            self::$listening = $dispatcher;
        }
        self::$created = [];
        try {
            $save();

            return $model ? collect(self::$created)->first(fn ($m) => $m instanceof $model) : null;
        } finally {
            self::$created = null;
        }
    }

    /** Validation messages name the template's columns. */
    private function labels(): void
    {
        $def = $this->def();
        $locale = app()->getLocale();
        $attributes = Lang::get('validation.attributes', [], $locale);
        $attributes = is_array($attributes) ? $attributes : [];
        foreach ($def['columns'] as $c) {
            $attributes[$c['field']] = __($c['label']);
        }
        foreach ($def['lines'] ?? [] as $c) {
            $attributes['lines.*.'.$c['field']] = __($c['label']);
        }
        Lang::get('validation.required', [], $locale);
        app('translator')->addLines(['validation.attributes' => $attributes], $locale);
    }

    /** @return list<array{row?: int, message: string}> */
    private function messages(Throwable $e, array $unit): array
    {
        $def = $this->def();
        $label = function (?string $field) use ($def): ?string {
            foreach ([...self::columns($def)] as $c) {
                if ($c['field'] === $field) {
                    return __($c['label']);
                }
            }

            return null;
        };
        $rows = array_column($unit['rows'] ?? [], 'row');

        if ($e instanceof ValidationException) {
            $out = [];
            foreach ($e->errors() as $field => $msgs) {
                $row = null;
                $col = $field;
                if (preg_match('/^lines\.(\d+)\.(.+)$/', $field, $m)) {
                    $row = $rows[(int) $m[1]] ?? null;
                    $col = $m[2];
                }
                $name = $label($col);
                foreach ($msgs as $msg) {
                    $out[] = ['row' => $row, 'message' => $name && ! str_contains($msg, $name) ? "«{$name}»: {$msg}" : $msg];
                }
            }

            return $out;
        }
        if ($e instanceof RowErrors) {
            return $e->errors;
        }
        if ($e instanceof ImportError) {
            $name = $label($e->field);

            return [['message' => $name ? "«{$name}»: {$e->getMessage()}" : $e->getMessage()]];
        }
        if ($e instanceof QueryException) {
            $map = ['P0001' => 'BUSINESS_RULE', '23514' => 'CHECK_VIOLATION', '23505' => 'DUPLICATE', '23503' => 'INVALID_REFERENCE'];
            preg_match('/RROKA_[A-Z0-9_]+/', $e->getMessage(), $m);
            $code = $m[0] ?? $map[$e->errorInfo[0] ?? ''] ?? null;
            if ($code && Lang::has("rroka.errors.$code")) {
                return [['message' => __("rroka.errors.$code")]];
            }
            report($e);

            return [['message' => __('خطأ غير متوقع في هذا السطر.')]];
        }
        if ($e instanceof HttpException) {
            preg_match('/^([A-Z][A-Z0-9_]+)/', $e->getMessage(), $m);

            return [['message' => isset($m[1]) && Lang::has("rroka.errors.{$m[1]}") ? __("rroka.errors.{$m[1]}")
                : ($e->getStatusCode() === 403 ? __('ليست لديك صلاحية لهذه العملية.') : ($e->getMessage() ?: __('خطأ غير متوقع في هذا السطر.')))]];
        }
        if ($e instanceof ModelNotFoundException) {
            return [['message' => __('السجل المطلوب غير موجود.')]];
        }
        report($e);

        return [['message' => __('خطأ غير متوقع في هذا السطر.')]];
    }
}
