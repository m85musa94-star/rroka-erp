<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Design;
use App\Models\DesignBomLine;
use App\Models\DesignVersion;
use App\Models\Project;
use App\Models\RawMaterial;
use App\Support\ActivityLog;
use App\Support\ListView;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Designs of a project and their versions: client review cycle, release for
 * production (one released version per design) and the version's bill of materials.
 * Stage order and the frozen BOM are enforced by the database.
 */
class DesignController extends Controller
{
    public function index(Request $request): View
    {
        $latest = fn (array $st) => fn ($q) => $q->whereHas('versions', fn ($v) => $v->whereIn('status', $st)
            ->whereRaw('version_no = (select max(version_no) from design_versions x where x.design_id = design_versions.design_id)'));
        $lv = new ListView($request,
            filters: [
                'draft' => ['label' => __('قيد الإعداد'), 'group' => 'st', 'apply' => $latest(['DRAFT'])],
                'review' => ['label' => __('بانتظار موافقة العميل'), 'group' => 'st', 'apply' => $latest(['CLIENT_REVIEW'])],
                'approved' => ['label' => __('وافق العميل ولم يُصدر'), 'group' => 'st', 'apply' => $latest(['CLIENT_APPROVED'])],
                'released' => ['label' => __('مُصدَر للإنتاج'), 'group' => 'rel', 'apply' => fn ($q) => $q->whereHas('versions', fn ($v) => $v->where('status', 'RELEASED_FOR_PRODUCTION'))],
            ],
            groups: [
                'project' => ['label' => __('المشروع'), 'key' => fn ($d) => $d->project_id, 'title' => fn ($d) => $d->project->project_no.' — '.$d->project->title],
            ],
            keep: ['project_id'],
        );
        $query = $lv->applyFilters(Design::with(['project:id,project_no,title,client_id', 'project.client:id,business_name', 'versions'])->orderByDesc('id'))
            ->when($request->integer('project_id'), fn ($q, $id) => $q->where('project_id', $id));
        if ($lv->q !== '') {
            $s = $lv->q;
            $query->where(fn ($q) => $q->where('title', 'ilike', "%{$s}%")
                ->orWhereHas('project', fn ($p) => $p->where('title', 'ilike', "%{$s}%")->orWhere('project_no', 'ilike', "%{$s}%")));
        }

        return view('designs.index', [
            'lv' => $lv,
            'designs' => $lv->group ? null : $query->paginate(30)->withQueryString(),
            'groups' => $lv->group ? $lv->grouped($query->limit(1000)->get()) : null,
        ]);
    }

    public function create(Request $request): View
    {
        return view('designs.create', [
            'projects' => Project::whereNotIn('status', ['COMPLETED', 'CANCELLED'])->orderByDesc('id')->get(['id', 'project_no', 'title']),
            'selected' => $request->integer('project_id') ?: null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'project_id' => ['required', 'integer', 'exists:projects,id'],
            'title' => ['required', 'string', 'max:200'],
            'file_url' => ['nullable', 'url', 'max:1000'],
            'change_notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $version = DB::transaction(function () use ($data, $request) {
            $design = Design::create(['project_id' => $data['project_id'], 'title' => $data['title']]);
            $v = new DesignVersion(['design_id' => $design->id, 'version_no' => 1, 'file_url' => $data['file_url'] ?? null, 'change_notes' => $data['change_notes'] ?? null]);
            $v->created_by = $request->user()->id;
            $v->save();

            return $v;
        });

        return redirect()->route('design-versions.show', $version)->with('ok', __('تم إنشاء التصميم ونسخته الأولى.'));
    }

    public function show(Design $design): RedirectResponse
    {
        $v = $design->versions()->first();

        return redirect()->route('design-versions.show', $v);
    }

    public function version(DesignVersion $version): View
    {
        $version->load('design.project.client', 'design.versions', 'bomLines.material', 'productionOrders');

        return view('designs.version', [
            'v' => $version,
            'materials' => RawMaterial::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name', 'uom']),
            'activity' => ActivityLog::for([
                'design_versions' => [$version->id],
                'design_bom_lines' => DB::table('audit_log')->where('table_name', 'design_bom_lines')
                    ->whereRaw("(coalesce(new_data, old_data)->>'design_version_id')::bigint = ?", [$version->id])
                    ->pluck('row_id')->all(),
            ]),
        ]);
    }

    public function updateVersion(Request $request, DesignVersion $version): RedirectResponse
    {
        if (! $version->bomEditable()) {
            throw ValidationException::withMessages(['rule' => __('rroka.errors.RROKA_BOM_LOCKED')]);
        }
        $version->update($request->validate([
            'file_url' => ['nullable', 'url', 'max:1000'],
            'change_notes' => ['nullable', 'string', 'max:2000'],
        ]));

        return back()->with('ok', __('تم حفظ بيانات النسخة.'));
    }

    /** New version: the next number, starting as DRAFT with a copy of the latest version's BOM. */
    public function newVersion(Request $request, Design $design): RedirectResponse
    {
        $data = $request->validate(['change_notes' => ['required', 'string', 'max:2000']]);
        $version = DB::transaction(function () use ($design, $data, $request) {
            $from = $design->versions()->lockForUpdate()->first();
            $v = new DesignVersion(['design_id' => $design->id, 'version_no' => ($from?->version_no ?? 0) + 1,
                'file_url' => $from?->file_url, 'change_notes' => $data['change_notes']]);
            $v->created_by = $request->user()->id;
            $v->save();
            foreach ($from?->bomLines ?? [] as $l) {
                $v->bomLines()->create($l->only('material_id', 'quantity', 'waste_pct'));
            }

            return $v;
        });

        return redirect()->route('design-versions.show', $version)->with('ok', __('تم إنشاء نسخة جديدة من التصميم.'));
    }

    public function transition(Request $request, DesignVersion $version, string $action): RedirectResponse
    {
        $user = $request->user();
        $map = [
            'submit' => ['CLIENT_REVIEW', 'designs.manage', __('أُرسلت النسخة لمراجعة العميل.')],
            'revise' => ['DRAFT', 'designs.manage', __('أُعيدت النسخة للتعديل.')],
            'approve' => ['CLIENT_APPROVED', 'designs.manage', __('سُجّلت موافقة العميل على النسخة.')],
            'reject' => ['REJECTED', 'designs.manage', __('سُجّل رفض النسخة.')],
            'release' => ['RELEASED_FOR_PRODUCTION', 'designs.release', __('أُصدرت النسخة للإنتاج.')],
        ];
        abort_unless(isset($map[$action]), 404);
        [$status, $permission, $message] = $map[$action];
        abort_unless($user->hasPermission($permission), 403, __('ليست لديك صلاحية لهذه العملية.'));

        DB::transaction(function () use ($version, $status, $request, $user) {
            $changes = ['status' => $status];
            if ($status === 'CLIENT_APPROVED') {
                $request->validate(['client_approved_at' => ['required', 'date', 'before_or_equal:now']]);
                $changes['client_approved_at'] = $request->date('client_approved_at');
            }
            if ($status === 'RELEASED_FOR_PRODUCTION') {
                if ($version->bomLines()->doesntExist()) {
                    throw ValidationException::withMessages(['rule' => __('لا تُصدر نسخة بلا قائمة مواد.')]);
                }
                // The previously released version of this design is superseded by this one.
                DesignVersion::where('design_id', $version->design_id)->where('status', 'RELEASED_FOR_PRODUCTION')
                    ->each(fn ($old) => $old->forceFill(['status' => 'SUPERSEDED'])->save());
                $changes += ['released_at' => now(), 'released_by' => $user->id];
            }
            $version->forceFill($changes)->save();
        });

        return back()->with('ok', $message);
    }

    /** Add a material to the BOM, or change its quantity if already there. */
    public function bomStore(Request $request, DesignVersion $version): RedirectResponse
    {
        $data = $request->validate([
            'material_id' => ['required', 'integer', 'exists:raw_materials,id'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'waste_pct' => ['nullable', 'numeric', 'min:0', 'lt:100'],
        ]);
        $version->bomLines()->updateOrCreate(['material_id' => $data['material_id']],
            ['quantity' => $data['quantity'], 'waste_pct' => $data['waste_pct'] ?? 0]);

        return back()->with('ok', __('تم حفظ بند قائمة المواد.'));
    }

    public function bomDestroy(DesignBomLine $line): RedirectResponse
    {
        $line->delete();

        return back()->with('ok', __('تم حذف البند من قائمة المواد.'));
    }
}
