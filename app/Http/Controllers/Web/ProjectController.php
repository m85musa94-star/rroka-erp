<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Quotation;
use App\Models\User;
use App\Support\ActivityLog;
use App\Support\ListView;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class ProjectController extends Controller
{
    public function index(Request $request): View
    {
        $status = fn (array $st) => fn ($q) => $q->whereIn('status', $st);
        $lv = new ListView($request,
            filters: [
                'open' => ['label' => __('الجارية'), 'group' => 'status', 'apply' => $status(['ACTIVE', 'IN_PRODUCTION', 'INSTALLATION'])],
                'on_hold' => ['label' => __('المتوقفة'), 'group' => 'status', 'apply' => $status(['ON_HOLD'])],
                'completed' => ['label' => __('المكتملة'), 'group' => 'status', 'apply' => $status(['COMPLETED'])],
                'late' => ['label' => __('متأخرة عن موعد التسليم'), 'group' => 'late', 'apply' => fn ($q) => $q->whereNotIn('status', ['COMPLETED', 'CANCELLED'])->whereDate('target_date', '<', today())],
            ],
            groups: [
                'status' => ['label' => __('المرحلة'), 'key' => fn ($p) => $p->status, 'title' => fn ($p) => __("rroka.status.$p->status")],
                'client' => ['label' => __('العميل'), 'key' => fn ($p) => $p->client_id, 'title' => fn ($p) => $p->client->business_name],
            ],
            views: ['list', 'kanban'],
        );

        $query = $lv->applyFilters(Project::with('client:id,business_name')->orderByDesc('id'));
        if ($lv->q !== '') {
            $search = $lv->q;
            $query->where(fn ($q) => $q->where('title', 'ilike', "%{$search}%")
                ->orWhere('project_no', 'ilike', "%{$search}%")
                ->orWhereHas('client', fn ($c) => $c->where('business_name', 'ilike', "%{$search}%")));
        }

        if ($lv->view === 'kanban') {
            $all = $query->limit(500)->get();
            $columns = collect(['ACTIVE', 'IN_PRODUCTION', 'INSTALLATION', 'COMPLETED', 'ON_HOLD', 'CANCELLED'])
                ->mapWithKeys(fn ($st) => [$st => $all->where('status', $st)->values()])
                ->filter(fn ($items, $st) => in_array($st, ['ACTIVE', 'IN_PRODUCTION', 'INSTALLATION', 'COMPLETED'], true) || $items->isNotEmpty());

            return view('projects.index', ['lv' => $lv, 'columns' => $columns, 'projects' => null, 'groups' => null]);
        }

        return view('projects.index', [
            'lv' => $lv,
            'columns' => null,
            'projects' => $lv->group ? null : $query->paginate(30)->withQueryString(),
            'groups' => $lv->group ? $lv->grouped($query->limit(1000)->get()) : null,
        ]);
    }

    public function create(Request $request): View
    {
        return view('projects.create', [
            // Approved quotations that do not have a project yet.
            'quotations' => Quotation::with('client:id,business_name')
                ->where('status', 'APPROVED')->doesntHave('project')->orderByDesc('id')->get(),
            'selected' => $request->query('quotation_id'),
            'users' => User::where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'quotation_id' => ['required', 'integer', 'exists:quotations,id'],
            'title' => ['required', 'string', 'max:255'],
            'start_date' => ['required', 'date'],
            'target_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'manager_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $project = new Project($data);
        $project->created_by = $request->user()->id;
        $project->save();

        return redirect()->route('projects.show', $project)->with('ok', __('تم إنشاء المشروع.'));
    }

    /** Stage change; allowed transitions are enforced by the database. */
    public function stage(Project $project, string $to): RedirectResponse
    {
        abort_unless(in_array($to, ['ACTIVE', 'IN_PRODUCTION', 'INSTALLATION', 'COMPLETED', 'ON_HOLD', 'CANCELLED'], true), 404);

        $project->forceFill(['status' => $to, 'completed_at' => $to === 'COMPLETED' ? now() : $project->completed_at])->save();

        return back()->with('ok', __('نُقل المشروع إلى مرحلة: ').__("rroka.status.$to"));
    }

    public function show(Request $request, Project $project): View
    {
        $cost = null;
        if ($request->user()->hasPermission('costing.view')) {
            $cost = $project->actualCost();
            $inner = trim((string) $cost->costing_gaps, '{}');
            $cost->gaps = $inner === '' ? [] : explode(',', $inner);
        }

        return view('projects.show', [
            'project' => $project->load('client', 'quotation', 'designs.versions', 'productionOrders'),
            'manager' => $project->manager_id ? DB::table('users')->where('id', $project->manager_id)->value('name') : null,
            'cost' => $cost,
            'activity' => ActivityLog::for(['projects' => [$project->id]]),
        ]);
    }
}
