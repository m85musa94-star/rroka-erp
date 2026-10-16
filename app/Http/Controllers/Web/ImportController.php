<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Imports\Importer;
use App\Imports\ImportRegistry;
use App\Imports\Template;
use App\Models\ImportBatch;
use App\Models\ImportBatchRecord;
use App\Models\User;
use App\Support\ActivityLog;
use App\Support\XlsxReader;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Data import from filled templates: download a screen's template, upload it, see every
 * row checked by that screen's own rules (nothing saved), then import all rows or none.
 * Documents arrive as drafts; the batch keeps the link to every record it created.
 */
class ImportController extends Controller
{
    /** The imports this user may use, by section. */
    public static function available(User $user): array
    {
        $out = [];
        foreach (ImportRegistry::all() as $key => $def) {
            if (Importer::may($user, $def)) {
                $out[$def['section']][$key] = $def;
            }
        }

        return $out;
    }

    /** The imports offered on a screen (its list or its entry form). */
    public static function forScreen(?string $route, User $user): array
    {
        if (! $route) {
            return [];
        }
        $out = [];
        foreach (ImportRegistry::all() as $key => $def) {
            $create = str_ends_with($def['route'], '.store') ? substr($def['route'], 0, -6).'.create' : null;
            if (($def['screen'] === $route || $create === $route) && Importer::may($user, $def)) {
                $out[$key] = $def;
            }
        }

        return $out;
    }

    public function index(Request $request): View
    {
        return view('imports.index', [
            'sections' => self::available($request->user()),
            'batches' => ImportBatch::with('creator:id,name')->orderByDesc('id')->limit(30)->get()
                ->filter(fn ($b) => $b->def() && Importer::may($request->user(), $b->def())),
        ]);
    }

    public function show(Request $request, string $type): View
    {
        $def = $this->defOrFail($request, $type);

        return view('imports.show', [
            'type' => $type, 'def' => $def, 'columns' => Importer::columns($def),
            'batches' => ImportBatch::with('creator:id,name')->where('import_type', $type)->orderByDesc('id')->limit(20)->get(),
        ]);
    }

    public function template(Request $request, string $type): Response
    {
        $this->defOrFail($request, $type);

        return $this->xlsx(Template::build($type), Template::fileName($type));
    }

    public function upload(Request $request, string $type): RedirectResponse
    {
        $def = $this->defOrFail($request, $type);
        $request->validate(['file' => ['required', 'file', 'max:5120']], [], ['file' => __('الملف')]);
        $file = $request->file('file');
        try {
            $sheet = XlsxReader::rows($file->getRealPath(), $file->getClientOriginalName());
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }
        $parsed = Importer::parse($def, $sheet);
        if ($parsed['missing']) {
            throw ValidationException::withMessages(['file' => __('أعمدة مطلوبة غير موجودة في الورقة الأولى: :cols. استخدم القالب المنزّل من هذه الشاشة.', ['cols' => implode(Importer::sep(), $parsed['missing'])])]);
        }
        if (! $parsed['rows']) {
            throw ValidationException::withMessages(['file' => __('لا توجد أسطر بيانات في الورقة الأولى.')]);
        }
        if (count($parsed['rows']) > Importer::MAX_ROWS) {
            throw ValidationException::withMessages(['file' => __('الحد الأقصى :n سطر في الملف الواحد؛ قسّم الملف.', ['n' => Importer::MAX_ROWS])]);
        }
        $sha = hash_file('sha256', $file->getRealPath());
        $done = ImportBatch::where('import_type', $type)->where('file_sha256', $sha)->where('status', 'IMPORTED')->first();
        if ($done) {
            throw ValidationException::withMessages(['file' => __('هذا الملف نفسه استُورد من قبل في الدفعة :no.', ['no' => $done->batch_no])]);
        }

        $batch = new ImportBatch(['import_type' => $type, 'file_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'file_sha256' => $sha, 'rows' => $parsed['rows'], 'row_count' => count($parsed['rows'])]);
        $batch->created_by = $request->user()->id;
        $batch->save();
        $this->runCheck($batch, $request->user());
        if ($parsed['ignored']) {
            session()->flash('warn', __('أعمدة غير معروفة تُركت دون قراءة: :cols', ['cols' => implode(Importer::sep(), $parsed['ignored'])]));
        }

        return redirect()->route('imports.batch', $batch);
    }

    public function batch(Request $request, ImportBatch $batch): View
    {
        $def = $this->batchDef($request, $batch);
        $records = $batch->records()->get();
        $model = $def['model'] ?? null;
        $drafts = $model && $batch->status === 'IMPORTED' && isset($def['approve'])
            ? $model::whereIn('id', $records->pluck('record_id'))->where('status', 'DRAFT')->count() : 0;

        return view('imports.batch', [
            'batch' => $batch->load('creator:id,name', 'importer:id,name'), 'def' => $def, 'columns' => Importer::columns($def),
            'results' => $batch->resultsByRow(), 'records' => $records->keyBy('row_no'), 'drafts' => $drafts,
            'canApprove' => $drafts > 0 && Importer::allowed($request->user(), $def['approve'][0]),
            'activity' => ActivityLog::for(['import_batches' => [$batch->id]]),
        ]);
    }

    public function check(Request $request, ImportBatch $batch): RedirectResponse
    {
        $this->batchDef($request, $batch);
        $this->previewOnly($batch);
        $this->runCheck($batch, $request->user());

        return redirect()->route('imports.batch', $batch)->with('ok', __('أُعيد فحص الملف.'));
    }

    public function import(Request $request, ImportBatch $batch): RedirectResponse
    {
        $this->batchDef($request, $batch);
        $this->previewOnly($batch);
        $user = $request->user();
        $out = (new Importer($batch->import_type, $user))->import($batch->rows);
        if ($out['errors'] > 0) {
            $this->saveResult($batch, $out);

            return redirect()->route('imports.batch', $batch)
                ->withErrors(['rule' => __('لم يُستورد شيء: ظهرت أخطاء عند التنفيذ (تغيّرت البيانات منذ الفحص). صحّحها ثم أعد المحاولة.')]);
        }
        foreach ($out['records'] as $r) {
            ImportBatchRecord::create(['batch_id' => $batch->id, 'row_no' => $r['row'], 'record_table' => $r['table'], 'record_id' => $r['id']]);
        }
        $batch->forceFill(['result' => $out['results'], 'error_count' => 0, 'checked_at' => now(),
            'record_count' => count($out['records']), 'status' => 'IMPORTED', 'imported_by' => $user->id, 'imported_at' => now()]);
        $batch->save();

        return redirect()->route('imports.batch', $batch)->with('ok', __('استُورد :n سطر.', ['n' => $batch->row_count]));
    }

    public function approve(Request $request, ImportBatch $batch): RedirectResponse
    {
        $def = $this->batchDef($request, $batch);
        abort_unless($batch->status === 'IMPORTED' && isset($def['approve']) && Importer::allowed($request->user(), $def['approve'][0]), 403, __('ليست لديك صلاحية لهذه العملية.'));
        $ids = $def['model']::whereIn('id', $batch->records()->pluck('record_id'))->where('status', 'DRAFT')->orderBy('id')->pluck('id')->all();
        $results = (new Importer($batch->import_type, $request->user()))->approve($ids);
        $failed = array_filter($results, fn ($r) => ! $r['ok']);
        if ($failed) {
            $first = reset($failed);

            return back()->withErrors(['rule' => __('لم يُعتمد شيء: السجل رقم :id رُفض — :msg', ['id' => $first['id'], 'msg' => implode(' ', $first['messages'])])]);
        }

        return back()->with('ok', __('اعتُمدت :n مسودة.', ['n' => count($ids)]));
    }

    public function discard(Request $request, ImportBatch $batch): RedirectResponse
    {
        $this->batchDef($request, $batch);
        $this->previewOnly($batch);
        $batch->forceFill(['status' => 'DISCARDED'])->save();

        return redirect()->route('imports.show', $batch->import_type)->with('ok', __('تُرك الملف دون استيراد.'));
    }

    public function errors(Request $request, ImportBatch $batch): Response
    {
        $this->batchDef($request, $batch);

        return $this->xlsx(Template::build($batch->import_type, $batch->rows, $batch->resultsByRow()), Template::fileName($batch->import_type, 'checked'));
    }

    private function runCheck(ImportBatch $batch, User $user): void
    {
        $this->saveResult($batch, (new Importer($batch->import_type, $user))->check($batch->rows));
    }

    private function saveResult(ImportBatch $batch, array $out): void
    {
        $batch->forceFill(['result' => $out['results'], 'error_count' => $out['errors'], 'checked_at' => now()])->save();
    }

    private function previewOnly(ImportBatch $batch): void
    {
        if ($batch->status !== 'PREVIEW') {
            throw ValidationException::withMessages(['rule' => __('rroka.errors.RROKA_IMPORT_LOCKED')]);
        }
    }

    private function defOrFail(Request $request, string $type): array
    {
        $def = ImportRegistry::get($type) ?? abort(404);
        abort_unless(Importer::may($request->user(), $def), 403, __('ليست لديك صلاحية لهذه العملية.'));

        return $def;
    }

    private function batchDef(Request $request, ImportBatch $batch): array
    {
        return $this->defOrFail($request, $batch->import_type);
    }

    private function xlsx(string $bytes, string $name): Response
    {
        return response($bytes, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
            'Cache-Control' => 'no-store',
        ]);
    }
}
