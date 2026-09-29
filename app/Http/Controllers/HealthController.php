<?php

namespace App\Http\Controllers;

use App\Services\StudioStorage;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Public, secret-free deployment diagnostics at /api/health (no session or DB
 * needed to render). Lets the owner screenshot what is failing after a deploy.
 */
class HealthController extends Controller
{
    public function __invoke(): Response
    {
        $checks = [];
        $checks[] = ['مفتاح التطبيق (APP_KEY)', filled(config('app.key')), filled(config('app.key')) ? 'موجود' : 'غير موجود'];

        $driver = (string) config('database.default');
        $checks[] = ['نوع قاعدة البيانات', $driver === 'pgsql', $driver];

        $connected = false;
        try {
            DB::select('select 1');
            $connected = true;
            $checks[] = ['الاتصال بقاعدة البيانات', true, 'متصل'];
        } catch (Throwable $e) {
            $checks[] = ['الاتصال بقاعدة البيانات', false, $this->reason($e)];
        }

        if ($connected) {
            foreach (['migrations' => 'تهيئة الجداول', 'clients' => 'جداول النظام', 'sessions' => 'جدول الجلسات', 'cache' => 'جدول التخزين المؤقت'] as $table => $label) {
                $ok = $this->safe(fn () => Schema::hasTable($table));
                $checks[] = [$label, $ok, $ok ? 'موجود' : 'غير موجود — لم يُنفَّذ أمر php artisan migrate --force'];
            }
            $users = $this->safe(fn () => Schema::hasTable('users') && DB::table('users')->exists());
            $checks[] = ['حساب المدير', $users, $users ? 'موجود' : 'غير موجود — لم يُنفَّذ أمر php artisan rroka:bootstrap-admin أو متغيراته ناقصة'];
        }

        $allOk = collect($checks)->every(fn ($c) => $c[1]);
        $lines = ['فحص حالة نظام إر روكا — '.($allOk ? 'كل البنود سليمة' : 'يوجد بند فاشل'), ''];
        foreach ($checks as [$label, $ok, $detail]) {
            $lines[] = ($ok ? '[سليم] ' : '[فاشل] ').$label.': '.$detail;
        }
        // Informational: the site works without it, only studio uploads are refused.
        $studio = (string) config('filesystems.disks.studio.driver');
        $lines[] = (StudioStorage::isReady() ? '[سليم] ' : '[تنبيه] ').'مخزن صور الاستوديو: '.$studio
            .(StudioStorage::isReady() ? '' : ' — لم يُربط مخزن ملفات باسم studio، فرفع الصور موقوف');

        return response(implode("\n", $lines)."\n", $allOk ? 200 : 503)
            ->header('Content-Type', 'text/plain; charset=utf-8')
            ->header('Cache-Control', 'no-store');
    }

    private function safe(callable $fn): bool
    {
        try {
            return (bool) $fn();
        } catch (Throwable) {
            return false;
        }
    }

    /** Classify the failure without echoing hosts, users or passwords. */
    private function reason(Throwable $e): string
    {
        $m = $e->getMessage();

        return match (true) {
            str_contains($m, 'does not exist') && str_contains($m, 'sqlite') => 'لا توجد قاعدة بيانات مربوطة (القيمة الافتراضية SQLite)',
            str_contains($m, 'Connection refused') => 'تعذّر الوصول إلى الخادم — قاعدة البيانات غير مربوطة بالتطبيق أو متوقفة',
            str_contains($m, 'password authentication failed') => 'بيانات الدخول إلى قاعدة البيانات غير صحيحة',
            str_contains($m, 'could not find driver') => 'مشغّل PostgreSQL غير مثبت في الخادم',
            str_contains($m, 'timeout') || str_contains($m, 'timed out') => 'انتهت مهلة الاتصال بقاعدة البيانات',
            default => 'خطأ آخر: '.class_basename($e).(preg_match('/SQLSTATE\[\w+\]/', $m, $s) ? ' '.$s[0] : ''),
        };
    }
}
