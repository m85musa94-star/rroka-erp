<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Project;
use App\Models\Quotation;
use App\Models\User;
use App\Models\VatRate;
use App\Support\ActivityLog;
use App\Support\ListView;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
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

    /** Stages a past project can be entered at, in the order the stage bar walks them. */
    public const PAST_STAGES = ['ACTIVE', 'IN_PRODUCTION', 'INSTALLATION', 'COMPLETED'];

    /**
     * A project from before the system: the agreed price becomes an approved quotation
     * exempt from the cost sheet (with the reason, never sent to Daftra), and the
     * project is created at the stage it has reached.
     */
    public function createPast(): View
    {
        return view('projects.past', [
            'clients' => Client::orderBy('business_name')->get(['id', 'business_name']),
            'vatRates' => VatRate::where('is_active', true)->orderBy('name')->get(),
            'users' => User::where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function storePast(Request $request): RedirectResponse
    {
        abort_unless($request->user()->hasPermission('quotations.approve'), 403, __('ليست لديك صلاحية لهذه العملية.'));
        $data = $request->validate([
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'title' => ['required', 'string', 'max:255'],
            'contract_value' => ['required', 'numeric', 'gt:0'],
            'vat_rate_id' => ['nullable', 'integer', Rule::exists('vat_rates', 'id')->where('is_active', true)],
            'start_date' => ['required', 'date', 'before_or_equal:today'],
            'target_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'stage' => ['required', Rule::in(self::PAST_STAGES)],
            'completed_on' => ['nullable', 'required_if:stage,COMPLETED', 'date', 'after_or_equal:start_date', 'before_or_equal:today'],
            'manager_id' => ['nullable', 'integer', 'exists:users,id'],
            'reason' => ['required', 'string', 'max:500'],
        ]);
        $uid = $request->user()->id;

        $project = DB::transaction(function () use ($data, $uid) {
            $q = new Quotation(['client_id' => $data['client_id'], 'issue_date' => $data['start_date'], 'discount_amount' => 0,
                'vat_rate_id' => $data['vat_rate_id'] ?? null, 'notes' => __('مشروع سابق أُدخل يدويًا')]);
            $q->forceFill(['is_legacy' => true, 'costing_exempt_reason' => $data['reason'], 'created_by' => $uid])->save();
            $q->lines()->create(['line_no' => 1, 'description' => $data['title'], 'quantity' => 1, 'unit' => __('مشروع'), 'unit_price' => $data['contract_value']]);
            $q->forceFill(['status' => 'SENT'])->save();
            $q->forceFill(['status' => 'APPROVED', 'approved_by' => $uid, 'approved_at' => now()])->save();

            $project = new Project(['quotation_id' => $q->id, 'title' => $data['title'], 'start_date' => $data['start_date'],
                'target_date' => $data['target_date'] ?? null, 'manager_id' => $data['manager_id'] ?? null]);
            $project->created_by = $uid;
            $project->save();
            // Walk the stage bar up to the stage reached (each step is checked by the database).
            foreach (array_slice(self::PAST_STAGES, 1, array_search($data['stage'], self::PAST_STAGES, true)) as $to) {
                $project->forceFill(['status' => $to, 'completed_at' => $to === 'COMPLETED' ? $data['completed_on'] : null])->save();
            }

            return $project;
        });

        return redirect()->route('projects.show', $project)->with('ok', __('أُنشئ المشروع السابق.'));
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
            'sales' => $request->user()->hasPermission('sales.view') || $request->user()->hasPermission('sales.manage')
                ? (DB::table('v_project_sales')->where('project_id', $project->id)->first() ?? (object) ['revenue' => 0, 'invoiced' => 0, 'collected' => 0, 'balance' => 0])
                : null,
            'activity' => ActivityLog::for(['projects' => [$project->id]]),
        ]);
    }
}
