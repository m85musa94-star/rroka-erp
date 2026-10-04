<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\CostCenter;
use App\Models\EmployeeCostCard;
use App\Models\EnergyRate;
use App\Models\MachineCostCard;
use App\Models\MaterialStandardPrice;
use App\Models\OverheadPool;
use App\Models\WasteDefault;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Costing engine home: what is still missing before a standard cost can be computed,
 * cost centres, electricity rates, and the approval of every versioned cost record.
 */
class CostingController extends Controller
{
    /** Versioned record types that share approve / cancel. */
    public const TYPES = [
        'energy' => EnergyRate::class,
        'employee-cards' => EmployeeCostCard::class,
        'machine-cards' => MachineCostCard::class,
        'prices' => MaterialStandardPrice::class,
        'waste' => WasteDefault::class,
        'pools' => OverheadPool::class,
    ];

    /** The cost centres listed in the costing specification (section 6); created only on request. */
    public const SPEC_CENTERS = [
        ['CARP', 'النجارة', 'LABOR_HOURS'], ['CNC', 'الماكينات', 'MACHINE_HOURS'], ['UPH', 'التنجيد', 'LABOR_HOURS'],
        ['PAINT', 'الدهان', 'LABOR_HOURS'], ['ASSY', 'التجميع', 'LABOR_HOURS'], ['INST', 'التركيب', 'LABOR_HOURS'],
        ['SUPPORT', 'خدمات الإنتاج', 'LABOR_HOURS'],
    ];

    public function index(): View
    {
        $today = today()->toDateString();
        $approvedOn = fn (string $table, string $key) => DB::table($table)->where('status', 'APPROVED')->where('effective_from', '<=', $today)->select($key)->distinct();

        return view('costing.index', [
            'centers' => CostCenter::count(),
            'energy' => DB::selectOne('select * from fn_energy_rate_on(?)', [$today]),
            'workersMissing' => DB::table('workers')->where('is_active', true)->where('is_direct_labor', true)
                ->whereNotIn('id', $approvedOn('employee_cost_cards', 'employee_id'))->orderBy('name')->get(['id', 'name']),
            'machinesMissing' => DB::table('machines')->where('is_active', true)
                ->whereNotIn('id', $approvedOn('machine_cost_cards', 'machine_id'))->orderBy('code')->get(['id', 'code', 'name']),
            'pools' => OverheadPool::with('costCenter')->where('status', 'APPROVED')->where('effective_from', '<=', $today)->where('period_to', '>=', $today)->get(),
            'materialsMissing' => DB::table('raw_materials')->where('is_active', true)
                ->whereNotIn('id', $approvedOn('material_standard_prices', 'material_id'))->count(),
            'drafts' => collect(self::TYPES)->map(fn ($class) => $class::where('status', 'DRAFT')->count()),
        ]);
    }

    public function centers(): View
    {
        return view('costing.centers', [
            'centers' => CostCenter::orderBy('code')->get(),
            'departments' => DB::table('departments')->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function centerStore(Request $request): RedirectResponse
    {
        CostCenter::create($this->centerData($request));

        return back()->with('ok', __('تمت إضافة مركز التكلفة.'));
    }

    public function centerUpdate(Request $request, CostCenter $center): RedirectResponse
    {
        $center->update($this->centerData($request, $center) + ['is_active' => $request->boolean('is_active')]);

        return back()->with('ok', __('تم تحديث مركز التكلفة.'));
    }

    /** Creates the centres named in the specification, only when none exist yet. */
    public function centersFromSpec(): RedirectResponse
    {
        if (CostCenter::exists()) {
            throw ValidationException::withMessages(['rule' => __('توجد مراكز تكلفة بالفعل.')]);
        }
        foreach (self::SPEC_CENTERS as [$code, $name, $driver]) {
            CostCenter::create(['code' => $code, 'name' => $name, 'driver' => $driver]);
        }

        return back()->with('ok', __('أُنشئت مراكز التكلفة الواردة في المواصفة. راجع محرك كل مركز وعدّله إن لزم.'));
    }

    public function energy(): View
    {
        return view('costing.energy', ['rates' => EnergyRate::orderByDesc('effective_from')->orderByDesc('id')->get(), 'names' => $this->userNames()]);
    }

    public function energyStore(Request $request): RedirectResponse
    {
        $rate = new EnergyRate($request->validate([
            'effective_from' => ['required', 'date'],
            'rate_per_kwh' => ['required', 'numeric', 'gt:0'],
            'source' => ['required', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]) + ['estimated' => $request->boolean('estimated')]);
        $rate->created_by = $request->user()->id;
        $rate->save();

        return back()->with('ok', __('حُفظ سعر الكهرباء كمسودة؛ يُستخدم بعد اعتماده.'));
    }

    /** Approval makes a cost record final (the database freezes it and checks its rules). */
    public function approve(Request $request, string $type, int $id): RedirectResponse
    {
        $record = $this->record($type, $id);
        $record->forceFill(['status' => 'APPROVED', 'approved_by' => $request->user()->id, 'approved_at' => now()])->save();

        return back()->with('ok', __('اعتُمد السجل وأصبح نهائيًا.'));
    }

    public function cancel(string $type, int $id): RedirectResponse
    {
        $this->record($type, $id)->forceFill(['status' => 'CANCELLED'])->save();

        return back()->with('ok', __('أُلغيت المسودة.'));
    }

    private function record(string $type, int $id): Model
    {
        abort_unless(isset(self::TYPES[$type]), 404);
        $record = self::TYPES[$type]::findOrFail($id);
        if ($record->status !== 'DRAFT') {
            throw ValidationException::withMessages(['rule' => __('rroka.errors.RROKA_COST_RECORD_LOCKED')]);
        }

        return $record;
    }

    private function centerData(Request $request, ?CostCenter $c = null): array
    {
        $request->merge(['code' => strtoupper(trim((string) $request->input('code')))]);

        return $request->validate([
            'code' => ['required', 'string', 'max:20', Rule::unique('cost_centers', 'code')->ignore($c?->id)],
            'name' => ['required', 'string', 'max:120', Rule::unique('cost_centers', 'name')->ignore($c?->id)],
            'driver' => ['required', Rule::in(CostCenter::DRIVERS)],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
    }

    private function userNames()
    {
        return DB::table('users')->pluck('name', 'id');
    }
}
