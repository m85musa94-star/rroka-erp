<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\CostCenter;
use App\Models\Employee;
use App\Models\Machine;
use App\Models\OverheadPool;
use App\Support\ActivityLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Overhead pools: expected indirect cost of a period per cost centre (or the whole
 * factory) ÷ practical driver capacity = absorption rate; and the selling & admin pool
 * (% added for the fully loaded cost). Approval freezes the figures.
 */
class OverheadPoolController extends Controller
{
    public function index(): View
    {
        return view('costing.pools.index', ['pools' => OverheadPool::with('costCenter')->withSum('lines', 'amount')
            ->orderByDesc('effective_from')->orderBy('kind')->orderByDesc('id')->get()]);
    }

    public function create(Request $request): View
    {
        $kind = $request->query('kind') === 'SELLING_ADMIN' ? 'SELLING_ADMIN' : 'MANUFACTURING';

        return view('costing.pools.form', [
            'pool' => new OverheadPool(['kind' => $kind, 'effective_from' => today()->startOfYear(), 'period_to' => today()->endOfYear(),
                'driver' => $kind === 'SELLING_ADMIN' ? 'PCT_OF_MANUFACTURING_COST' : 'LABOR_HOURS']),
            'centers' => CostCenter::where('is_active', true)->orderBy('code')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $pool = new OverheadPool($this->validated($request));
        $pool->created_by = $request->user()->id;
        $pool->save();

        return redirect()->route('costing.pools.show', $pool)->with('ok', __('حُفظ الوعاء كمسودة. أضف بنوده ثم اعتمده.'));
    }

    public function show(OverheadPool $pool): View
    {
        $pool->load('costCenter', 'lines.employee', 'lines.machine');
        $energy = $pool->kind === 'MANUFACTURING' && $pool->lines->contains('category', 'GENERAL_ELECTRICITY')
            ? (float) DB::scalar('select fn_machine_energy(?, ?, null)', [$pool->effective_from->toDateString(), $pool->period_to->toDateString()]) : 0.0;

        return view('costing.pools.show', [
            'pool' => $pool,
            'preview' => $this->preview($pool, $energy),
            'employees' => Employee::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'machines' => Machine::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'names' => DB::table('users')->whereIn('id', array_filter([$pool->created_by, $pool->approved_by]))->pluck('name', 'id'),
            'activity' => ActivityLog::for(['overhead_pools' => [$pool->id], 'overhead_pool_lines' => $pool->lines->pluck('id')->all()]),
        ]);
    }

    public function edit(OverheadPool $pool): View
    {
        abort_unless($pool->isDraft(), 404);

        return view('costing.pools.form', ['pool' => $pool, 'centers' => CostCenter::where('is_active', true)->orderBy('code')->get()]);
    }

    public function update(Request $request, OverheadPool $pool): RedirectResponse
    {
        if (! $pool->isDraft()) {
            throw ValidationException::withMessages(['rule' => __('rroka.errors.RROKA_COST_RECORD_LOCKED')]);
        }
        $pool->update(collect($this->validated($request, $pool))->except('kind', 'cost_center_id')->all());

        return redirect()->route('costing.pools.show', $pool)->with('ok', __('حُدّث الوعاء.'));
    }

    public function lineStore(Request $request, OverheadPool $pool): RedirectResponse
    {
        $pool->lines()->create($request->validate([
            'category' => ['required', Rule::in($pool->categories())],
            'description' => ['required', 'string', 'max:300'],
            'amount' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'employee_id' => ['nullable', 'integer', 'exists:workers,id', 'required_if:category,SUPERVISION,INDIRECT_LABOR'],
            'machine_id' => ['nullable', 'integer', 'exists:machines,id', 'prohibited_unless:category,DEPRECIATION'],
        ]));

        return back()->with('ok', __('أُضيف البند.'));
    }

    public function lineDestroy(OverheadPool $pool, int $line): RedirectResponse
    {
        $pool->lines()->whereKey($line)->firstOrFail()->delete();

        return back()->with('ok', __('حُذف البند.'));
    }

    /** The figures approval would freeze (the database recomputes them on approval). */
    private function preview(OverheadPool $pool, float $energy): array
    {
        if (! $pool->isDraft()) {
            return ['gross' => (float) $pool->gross_cost, 'energy' => (float) $pool->machine_energy_deduction, 'net' => (float) $pool->net_cost, 'rate' => (float) $pool->rate];
        }
        $gross = (float) $pool->lines->sum('amount');
        $net = $gross - $energy;
        $base = $pool->kind === 'SELLING_ADMIN' ? (float) $pool->budgeted_manufacturing_cost : (float) $pool->practical_capacity;
        $rate = $base > 0 && $pool->lines->isNotEmpty() ? ($pool->kind === 'SELLING_ADMIN' ? $net / $base * 100 : $net / $base) : null;

        return ['gross' => $gross, 'energy' => $energy, 'net' => $net, 'rate' => $rate];
    }

    private function validated(Request $request, ?OverheadPool $pool = null): array
    {
        $kind = $pool?->kind ?? $request->input('kind');

        return $request->validate([
            'kind' => [$pool ? 'nullable' : 'required', Rule::in(['MANUFACTURING', 'SELLING_ADMIN'])],
            'cost_center_id' => ['nullable', 'integer', 'exists:cost_centers,id', Rule::prohibitedIf($kind === 'SELLING_ADMIN')],
            'effective_from' => ['required', 'date'],
            'period_to' => ['required', 'date', 'after_or_equal:effective_from'],
            'driver' => ['required', Rule::in($kind === 'SELLING_ADMIN' ? ['PCT_OF_MANUFACTURING_COST'] : ['LABOR_HOURS', 'MACHINE_HOURS'])],
            'theoretical_capacity' => ['nullable', 'numeric', 'gt:0', Rule::prohibitedIf($kind === 'SELLING_ADMIN')],
            'practical_capacity' => ['nullable', 'numeric', 'gt:0', Rule::requiredIf($kind === 'MANUFACTURING'), Rule::prohibitedIf($kind === 'SELLING_ADMIN'),
                ...($request->filled('theoretical_capacity') ? ['lte:theoretical_capacity'] : [])],
            'budgeted_manufacturing_cost' => ['nullable', 'numeric', 'gt:0', Rule::requiredIf($kind === 'SELLING_ADMIN'), Rule::prohibitedIf($kind === 'MANUFACTURING')],
            'source' => ['required', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]) + ['estimated' => $request->boolean('estimated')];
    }
}
