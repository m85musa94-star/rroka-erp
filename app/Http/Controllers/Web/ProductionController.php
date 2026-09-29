<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\DesignVersion;
use App\Models\LaborLog;
use App\Models\Machine;
use App\Models\MachineLog;
use App\Models\ProductionOrder;
use App\Models\QualityInspection;
use App\Models\StockMovement;
use App\Models\Worker;
use App\Support\ActivityLog;
use App\Support\ListView;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Manufacturing orders (Odoo MRP style): components from the released design
 * version's BOM, reservation and issue of materials, labour and machine time,
 * and quality inspections. Every rule (released design only, stock limits,
 * stage order, final QC before completion) is enforced by the database.
 */
class ProductionController extends Controller
{
    public function index(Request $request): View
    {
        $st = fn (array $s) => fn ($q) => $q->whereIn('production_orders.status', $s);
        $lv = new ListView($request,
            filters: [
                'planned' => ['label' => __('مخطط'), 'group' => 'st', 'apply' => $st(['PLANNED'])],
                'in_progress' => ['label' => __('قيد التنفيذ'), 'group' => 'st', 'apply' => $st(['IN_PROGRESS'])],
                'done' => ['label' => __('مكتمل'), 'group' => 'st', 'apply' => $st(['COMPLETED'])],
                'late' => ['label' => __('متأخر عن الموعد المخطط'), 'group' => 'late', 'apply' => fn ($q) => $q->whereIn('production_orders.status', ['PLANNED', 'IN_PROGRESS'])->whereDate('planned_end', '<', today())],
            ],
            groups: [
                'status' => ['label' => __('الحالة'), 'key' => fn ($o) => $o->status, 'title' => fn ($o) => __("rroka.status.$o->status")],
                'project' => ['label' => __('المشروع'), 'key' => fn ($o) => $o->project_id, 'title' => fn ($o) => $o->project->project_no.' — '.$o->project->title],
            ],
            views: ['list', 'kanban'],
            keep: ['project_id'],
        );
        $query = $lv->applyFilters(ProductionOrder::with('project:id,project_no,title', 'designVersion.design:id,title')->orderByDesc('id'))
            ->when($request->integer('project_id'), fn ($q, $id) => $q->where('project_id', $id));
        if ($lv->q !== '') {
            $s = $lv->q;
            $query->where(fn ($q) => $q->where('order_no', 'ilike', "%{$s}%")
                ->orWhereHas('project', fn ($p) => $p->where('title', 'ilike', "%{$s}%")->orWhere('project_no', 'ilike', "%{$s}%")));
        }

        if ($lv->view === 'kanban') {
            $all = $query->limit(500)->get();
            $columns = collect(ProductionOrder::STATUSES)->mapWithKeys(fn ($s) => [$s => $all->where('status', $s)->values()])
                ->filter(fn ($items, $s) => $s !== 'CANCELLED' || $items->isNotEmpty());

            return view('production.index', ['lv' => $lv, 'columns' => $columns, 'orders' => null, 'groups' => null]);
        }

        return view('production.index', [
            'lv' => $lv, 'columns' => null,
            'orders' => $lv->group ? null : $query->paginate(30)->withQueryString(),
            'groups' => $lv->group ? $lv->grouped($query->limit(1000)->get()) : null,
        ]);
    }

    public function create(Request $request): View
    {
        return view('production.create', [
            'versions' => DesignVersion::with('design.project:id,project_no,title,status')
                ->where('status', 'RELEASED_FOR_PRODUCTION')
                ->whereHas('design.project', fn ($p) => $p->whereNotIn('status', ['COMPLETED', 'CANCELLED', 'ON_HOLD']))
                ->orderByDesc('id')->get(),
            'selected' => $request->integer('design_version_id') ?: null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'design_version_id' => ['required', 'integer', 'exists:design_versions,id'],
            'planned_start' => ['nullable', 'date'],
            'planned_end' => ['nullable', 'date', 'after_or_equal:planned_start'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $version = DesignVersion::with('design')->findOrFail($data['design_version_id']);
        $order = new ProductionOrder($data + ['project_id' => $version->design->project_id]);
        $order->created_by = $request->user()->id;
        $order->save();

        return redirect()->route('production.show', $order)->with('ok', __('تم إنشاء أمر التصنيع.'));
    }

    public function show(ProductionOrder $order): View
    {
        $order->load('project.client', 'designVersion.design', 'designVersion.bomLines.material.balance',
            'laborLogs.worker', 'machineLogs.machine', 'inspections.inspector', 'movements.material');

        $components = $order->designVersion->bomLines->map(function ($l) use ($order) {
            $reserved = (float) DB::scalar('select fn_project_reserved(?, ?)', [$order->project_id, $l->material_id]);
            $issued = (float) DB::scalar('select fn_project_issued(?, ?)', [$order->project_id, $l->material_id]);
            $planned = $l->plannedQty();

            return (object) [
                'line' => $l, 'material' => $l->material, 'planned' => $planned, 'reserved' => $reserved, 'issued' => $issued,
                'remaining' => max(0, round($planned - $issued - $reserved, 4)),
                'available' => $l->material->balance?->available() ?? 0.0,
            ];
        });

        return view('production.show', [
            'o' => $order,
            'components' => $components,
            'workers' => Worker::where('is_active', true)->orderBy('name')->get(['id', 'name', 'trade']),
            'machines' => Machine::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'activity' => ActivityLog::for(['production_orders' => [$order->id]]),
        ]);
    }

    public function transition(Request $request, ProductionOrder $order, string $to): RedirectResponse
    {
        abort_unless(in_array($to, ['IN_PROGRESS', 'COMPLETED', 'CANCELLED'], true), 404);
        $order->forceFill(['status' => $to, 'completed_at' => $to === 'COMPLETED' ? now() : $order->completed_at])->save();

        return back()->with('ok', __('أمر التصنيع الآن: ').__("rroka.status.$to"));
    }

    /** Reserve / unreserve / issue / return one component, recorded against this order. */
    public function material(Request $request, ProductionOrder $order): RedirectResponse
    {
        $data = $request->validate([
            'movement_type' => ['required', Rule::in(['RESERVE', 'UNRESERVE', 'ISSUE', 'RETURN'])],
            'material_id' => ['required', 'integer', 'exists:raw_materials,id'],
            'quantity' => ['required', 'numeric', 'gt:0'],
        ]);
        $this->openOrFail($order);
        $fromReservation = $data['movement_type'] === 'ISSUE'
            && (float) DB::scalar('select fn_project_reserved(?, ?)', [$order->project_id, $data['material_id']]) >= (float) $data['quantity'];
        $this->move($order, $data['material_id'], $data['movement_type'], $data['quantity'], $fromReservation, $request->user()->id);

        return back()->with('ok', __('تم تسجيل الحركة.'));
    }

    /** "Check availability": reserve what is still needed, up to what is available. */
    public function reserveAll(Request $request, ProductionOrder $order): RedirectResponse
    {
        $this->openOrFail($order);
        $done = 0;
        $short = [];
        foreach ($order->designVersion->bomLines()->with('material.balance')->get() as $l) {
            $reserved = (float) DB::scalar('select fn_project_reserved(?, ?)', [$order->project_id, $l->material_id]);
            $issued = (float) DB::scalar('select fn_project_issued(?, ?)', [$order->project_id, $l->material_id]);
            $need = round($l->plannedQty() - $issued - $reserved, 4);
            if ($need <= 0) {
                continue;
            }
            $qty = min($need, $l->material->balance?->available() ?? 0.0);
            if ($qty > 0) {
                $this->move($order, $l->material_id, 'RESERVE', $qty, false, $request->user()->id);
                $done++;
            }
            if ($qty < $need) {
                $short[] = $l->material->code;
            }
        }
        $msg = __('حُجز :n بند.', ['n' => $done]);
        if ($short) {
            $msg .= ' '.__('نقص في المخزون: :list', ['list' => implode(__('، '), $short)]);
        }

        return back()->with('ok', $msg);
    }

    public function labor(Request $request, ProductionOrder $order): RedirectResponse
    {
        $data = $request->validate([
            'worker_id' => ['required', 'integer', Rule::exists('workers', 'id')->where('is_active', true)],
            'work_date' => ['required', 'date', 'before_or_equal:today'],
            'hours' => ['required', 'numeric', 'gt:0', 'max:24'],
            'activity' => ['nullable', 'string', 'max:200'],
        ]);
        $log = new LaborLog($data + ['production_order_id' => $order->id]);
        $log->created_by = $request->user()->id;
        $log->save();

        return back()->with('ok', __('تم تسجيل ساعات العامل.'));
    }

    public function machine(Request $request, ProductionOrder $order): RedirectResponse
    {
        $data = $request->validate([
            'machine_id' => ['required', 'integer', Rule::exists('machines', 'id')->where('is_active', true)],
            'work_date' => ['required', 'date', 'before_or_equal:today'],
            'hours' => ['required', 'numeric', 'gt:0', 'max:24'],
        ]);
        $log = new MachineLog($data + ['production_order_id' => $order->id]);
        $log->created_by = $request->user()->id;
        $log->save();

        return back()->with('ok', __('تم تسجيل ساعات الآلة.'));
    }

    public function inspect(Request $request, ProductionOrder $order): RedirectResponse
    {
        $data = $request->validate([
            'stage' => ['required', Rule::in(['IN_PROCESS', 'FINAL'])],
            'result' => ['required', Rule::in(QualityInspection::RESULTS)],
            'findings' => ['nullable', 'string', 'max:2000', 'required_unless:result,PASS'],
        ]);
        $qi = new QualityInspection($data + ['production_order_id' => $order->id]);
        $qi->inspector_id = $request->user()->id;
        $qi->save();

        return back()->with('ok', __('تم تسجيل فحص الجودة.'));
    }

    private function openOrFail(ProductionOrder $order): void
    {
        if (in_array($order->status, ['COMPLETED', 'CANCELLED'], true)) {
            throw ValidationException::withMessages(['rule' => __('rroka.errors.RROKA_PRODUCTION_ORDER_CLOSED')]);
        }
    }

    private function move(ProductionOrder $order, int $materialId, string $type, float|string $qty, bool $fromReservation, int $userId): void
    {
        $m = new StockMovement([
            'material_id' => $materialId, 'movement_type' => $type, 'quantity' => $qty,
            'project_id' => $order->project_id, 'production_order_id' => $order->id,
            'from_reservation' => $fromReservation, 'reference' => $order->order_no, 'moved_at' => now(),
        ]);
        $m->created_by = $userId;
        $m->save();
    }
}
