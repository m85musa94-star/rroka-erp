<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\RawMaterial;
use App\Models\StockMovement;
use App\Support\ActivityLog;
use App\Support\ListView;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Inventory: raw materials, their derived balances and the manual movements (receipts, adjustments). */
class MaterialController extends Controller
{
    public function index(Request $request): View
    {
        $lv = new ListView($request,
            filters: [
                'active' => ['label' => __('النشطة'), 'group' => 'active', 'apply' => fn ($q) => $q->where('raw_materials.is_active', true)],
                'inactive' => ['label' => __('الموقوفة'), 'group' => 'active', 'apply' => fn ($q) => $q->where('raw_materials.is_active', false)],
                'in_stock' => ['label' => __('متوفرة في المخزن'), 'group' => 'stock', 'apply' => fn ($q) => $q->where('b.qty_on_hand', '>', 0)],
                'out_of_stock' => ['label' => __('نفدت أو لم تُستلم'), 'group' => 'stock', 'apply' => fn ($q) => $q->whereRaw('coalesce(b.qty_on_hand, 0) = 0')],
                'reserved' => ['label' => __('عليها حجز'), 'group' => 'reserved', 'apply' => fn ($q) => $q->where('b.qty_reserved', '>', 0)],
            ],
            groups: [
                'category' => ['label' => __('الفئة'), 'key' => fn ($m) => $m->category ?? '', 'title' => fn ($m) => $m->category ?: __('بلا فئة')],
            ],
        );
        $query = $lv->applyFilters(RawMaterial::query()
            ->leftJoin('stock_balances as b', 'b.material_id', '=', 'raw_materials.id')
            ->select('raw_materials.*', 'b.qty_on_hand', 'b.qty_reserved', 'b.avg_unit_cost')
            ->orderBy('raw_materials.code'));
        if ($lv->q !== '') {
            $s = $lv->q;
            $query->where(fn ($q) => $q->where('code', 'ilike', "%{$s}%")->orWhere('name', 'ilike', "%{$s}%")->orWhere('category', 'ilike', "%{$s}%"));
        }

        return view('inventory.index', [
            'lv' => $lv,
            'materials' => $lv->group ? null : $query->paginate(50)->withQueryString(),
            'groups' => $lv->group ? $lv->grouped($query->limit(2000)->get()) : null,
        ]);
    }

    public function create(): View
    {
        return view('inventory.form', ['material' => new RawMaterial(['is_active' => true]), 'categories' => $this->categories()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $material = RawMaterial::create($this->validated($request));

        return redirect()->route('materials.show', $material)->with('ok', __('تمت إضافة الخامة.'));
    }

    public function show(RawMaterial $material): View
    {
        $movements = $material->movements()->with('project:id,project_no', 'productionOrder:id,order_no')->limit(200)->get();
        $u = request()->user();
        $accounting = collect(['accounting.view', 'accounting.manage', 'accounting.post', 'accounting.close'])->contains(fn ($p) => $u->hasPermission($p));

        return view('inventory.show', [
            'material' => $material->load('balance'),
            'movements' => $movements,
            'entries' => $accounting ? DB::table('journal_entries')->where('source_type', 'STOCK')->whereIn('source_id', $movements->pluck('id'))->get(['id', 'entry_no', 'source_id'])->keyBy('source_id') : null,
            'activity' => ActivityLog::for(['raw_materials' => [$material->id]]),
        ]);
    }

    public function edit(RawMaterial $material): View
    {
        return view('inventory.form', ['material' => $material, 'categories' => $this->categories()]);
    }

    public function update(Request $request, RawMaterial $material): RedirectResponse
    {
        $material->update($this->validated($request, $material));

        return redirect()->route('materials.show', $material)->with('ok', __('تم تحديث الخامة.'));
    }

    /**
     * Manual movements are adjustments only (count differences, damage, the opening
     * balance). Receipts come from approved supplier invoices; reservations and
     * issues from manufacturing orders.
     */
    public function move(Request $request, RawMaterial $material): RedirectResponse
    {
        $data = $request->validate([
            'movement_type' => ['required', Rule::in(['ADJUST_IN', 'ADJUST_OUT'])],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'unit_cost' => ['nullable', 'numeric', 'min:0', 'required_if:movement_type,ADJUST_IN'],
            'reference' => ['nullable', 'string', 'max:200'],
            'reason' => ['nullable', 'string', 'max:500', 'required_if:movement_type,ADJUST_IN', 'required_if:movement_type,ADJUST_OUT'],
            'moved_at' => ['nullable', 'date', 'before_or_equal:now'],
        ]);
        if ($data['movement_type'] === 'ADJUST_OUT') {
            $data['unit_cost'] = null; // priced by the database at average cost
        }
        $movement = new StockMovement($data + ['material_id' => $material->id, 'moved_at' => $data['moved_at'] ?? now()]);
        $movement->created_by = $request->user()->id;
        $movement->save();

        return back()->with('ok', __('تم تسجيل الحركة.'));
    }

    private function validated(Request $request, ?RawMaterial $material = null): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:40', Rule::unique('raw_materials', 'code')->ignore($material?->id)],
            'name' => ['required', 'string', 'max:200'],
            'category' => ['nullable', 'string', 'max:100'],
            'uom' => ['required', 'string', 'max:30'],
            'is_active' => ['boolean'],
        ]) + ['is_active' => $request->boolean('is_active')];
    }

    private function categories(): array
    {
        return RawMaterial::whereNotNull('category')->distinct()->orderBy('category')->pluck('category')->all();
    }
}
