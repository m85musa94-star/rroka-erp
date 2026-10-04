<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\CostCenter;
use App\Models\CostEstimate;
use App\Models\Employee;
use App\Models\Machine;
use App\Models\PricingPolicy;
use App\Models\Quotation;
use App\Models\RawMaterial;
use App\Support\ActivityLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Cost sheet of one quotation line: BOM, routing, direct costs → direct, manufacturing
 * and fully loaded cost, recommended price and the margin of the quoted price. All
 * figures come from the database view (v_estimate_costs); approval of the quotation
 * freezes them.
 */
class CostEstimateController extends Controller
{
    public function show(Quotation $quotation, int $line): View
    {
        $l = $quotation->lines()->where('line_no', $line)->firstOrFail();
        $estimate = CostEstimate::where('quotation_id', $quotation->id)->where('line_no', $line)->first();
        $policy = PricingPolicy::inForceOn($quotation->issue_date);

        return view('costing.estimate', [
            'q' => $quotation, 'line' => $l, 'e' => $estimate, 'policy' => $policy,
            'costs' => $estimate?->costs(),
            'snapshot' => $estimate?->snapshot,
            'materialLines' => $estimate ? DB::table('v_estimate_material_lines')->where('estimate_id', $estimate->id)->orderBy('id')->get() : collect(),
            'operationLines' => $estimate ? DB::table('v_estimate_operation_lines')->where('estimate_id', $estimate->id)->orderBy('seq')->orderBy('id')->get() : collect(),
            'directCosts' => $estimate ? $estimate->directCosts : collect(),
            'editable' => in_array($quotation->status, ['DRAFT', 'SENT'], true) && auth()->user()->hasPermission('quotations.manage'),
            'materials' => RawMaterial::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name', 'uom']),
            'centers' => CostCenter::where('is_active', true)->orderBy('code')->get(),
            'employees' => Employee::where('is_active', true)->where('is_direct_labor', true)->orderBy('name')->get(['id', 'name']),
            'machines' => Machine::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'activity' => $estimate ? ActivityLog::for(['cost_estimates' => [$estimate->id]]) : collect(),
        ]);
    }

    /** Creates or updates the estimate header (pricing method and target, product details). */
    public function save(Request $request, Quotation $quotation, int $line): RedirectResponse
    {
        $quotation->lines()->where('line_no', $line)->firstOrFail();
        $data = $request->validate([
            'pricing_method' => ['required', Rule::in(PricingPolicy::METHODS)],
            'target_pct' => ['required', 'numeric', 'min:0', $request->input('pricing_method') === 'MARGIN' ? 'lt:100' : 'max:1000'],
            'min_margin_pct' => ['nullable', 'numeric', 'min:0', 'lt:100'],
            'product_category' => ['nullable', 'string', 'max:120'],
            'dimensions' => ['nullable', 'string', 'max:300'],
            'specifications' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]) + ['installation_required' => $request->boolean('installation_required'), 'delivery_required' => $request->boolean('delivery_required')];

        $estimate = CostEstimate::firstOrNew(['quotation_id' => $quotation->id, 'line_no' => $line]);
        $estimate->fill($data);
        $estimate->created_by ??= $request->user()->id;
        $estimate->save();

        return back()->with('ok', __('حُفظت بيانات التقدير.'));
    }

    public function materialStore(Request $request, Quotation $quotation, int $line): RedirectResponse
    {
        $data = $request->validate([
            'material_id' => ['required', 'integer', 'exists:raw_materials,id'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'waste_pct' => ['nullable', 'numeric', 'min:0', 'lt:100'],
            'unit_price' => ['nullable', 'numeric', 'gt:0'],
            'price_source' => ['nullable', 'string', 'max:300', 'required_with:unit_price'],
        ]);
        $this->estimate($quotation, $line)->materials()->create($data);

        return back()->with('ok', __('أُضيفت المادة.'));
    }

    public function operationStore(Request $request, Quotation $quotation, int $line): RedirectResponse
    {
        $data = $request->validate([
            'operation' => ['required', 'string', 'max:120'],
            'cost_center_id' => ['required', 'integer', Rule::exists('cost_centers', 'id')->where('is_active', true)],
            'employee_id' => ['nullable', 'integer', 'exists:workers,id'],
            'labor_hours' => ['required', 'numeric', 'min:0'],
            'setup_hours' => ['required', 'numeric', 'min:0'],
            'machine_id' => ['nullable', 'integer', 'exists:machines,id', 'required_unless:machine_hours,0,machine_hours,null'],
            'machine_hours' => ['nullable', 'numeric', 'min:0'],
            'machine_setup_hours' => ['nullable', 'numeric', 'min:0'],
        ]);
        $data['machine_hours'] = (float) ($data['machine_hours'] ?? 0);
        $data['machine_setup_hours'] = (float) ($data['machine_setup_hours'] ?? 0);
        if ($data['labor_hours'] + $data['setup_hours'] + $data['machine_hours'] + $data['machine_setup_hours'] <= 0) {
            throw ValidationException::withMessages(['labor_hours' => __('أدخل ساعات العملية.')]);
        }
        $estimate = $this->estimate($quotation, $line);
        $estimate->operations()->create($data + ['seq' => (int) $estimate->operations()->max('seq') + 1]);

        return back()->with('ok', __('أُضيفت العملية.'));
    }

    public function directStore(Request $request, Quotation $quotation, int $line): RedirectResponse
    {
        $this->estimate($quotation, $line)->directCosts()->create($request->validate([
            'cost_type' => ['required', Rule::in(CostEstimate::DIRECT_TYPES)],
            'description' => ['required', 'string', 'max:300'],
            'amount' => ['required', 'numeric', 'min:0'],
            'basis' => ['required', Rule::in(['PER_UNIT', 'ONE_TIME'])],
        ]));

        return back()->with('ok', __('أُضيفت التكلفة المباشرة.'));
    }

    /** Removes one material / operation / direct cost line of the estimate. */
    public function destroyItem(Quotation $quotation, int $line, string $kind, int $id): RedirectResponse
    {
        $relation = ['materials' => 'materials', 'operations' => 'operations', 'direct' => 'directCosts'][$kind] ?? abort(404);
        $this->estimate($quotation, $line)->{$relation}()->whereKey($id)->firstOrFail()->delete();

        return back()->with('ok', __('حُذف البند.'));
    }

    /** Adding a component needs the estimate header first (method and target are explicit). */
    private function estimate(Quotation $quotation, int $line): CostEstimate
    {
        $estimate = CostEstimate::where('quotation_id', $quotation->id)->where('line_no', $line)->first();
        if (! $estimate) {
            throw ValidationException::withMessages(['rule' => __('احفظ طريقة التسعير والنسبة المستهدفة أولًا.')]);
        }

        return $estimate;
    }
}
