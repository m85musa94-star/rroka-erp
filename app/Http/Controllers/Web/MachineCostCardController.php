<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\CostCenter;
use App\Models\Machine;
use App\Models\MachineCostCard;
use App\Support\ActivityLog;
use App\Support\ListView;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Machine cost card → machine hour rate (depreciation + electricity + maintenance +
 * spare parts + other, per practical hour). Approval writes the machine's hour rate.
 */
class MachineCostCardController extends Controller
{
    public function index(Request $request): View
    {
        $st = fn (string $s) => fn ($q) => $q->where('status', $s);
        $lv = new ListView($request,
            filters: [
                'draft' => ['label' => __('مسودة'), 'group' => 'st', 'apply' => $st('DRAFT')],
                'approved' => ['label' => __('معتمدة'), 'group' => 'st', 'apply' => $st('APPROVED')],
                'estimated' => ['label' => __('أرقام تقديرية'), 'group' => 'e', 'apply' => fn ($q) => $q->where('estimated', true)],
            ],
            groups: ['machine' => ['label' => __('الآلة'), 'key' => fn ($c) => $c->machine_id, 'title' => fn ($c) => $c->machine->code.' — '.$c->machine->name]],
        );
        $query = $lv->applyFilters(MachineCostCard::with('machine.costCenter')->orderByDesc('effective_from')->orderByDesc('id'));
        if ($lv->q !== '') {
            $query->whereHas('machine', fn ($m) => $m->where('name', 'ilike', "%{$lv->q}%")->orWhere('code', 'ilike', "%{$lv->q}%"));
        }

        return view('costing.machine-cards.index', [
            'lv' => $lv,
            'cards' => $lv->group ? null : $query->paginate(30)->withQueryString(),
            'groups' => $lv->group ? $lv->grouped($query->limit(1000)->get()) : null,
            'machines' => Machine::with('costCenter')->orderBy('code')->get(),
            'centers' => CostCenter::where('is_active', true)->orderBy('code')->get(),
        ]);
    }

    public function create(Request $request): View
    {
        return view('costing.machine-cards.form', [
            'card' => new MachineCostCard(['machine_id' => $request->integer('machine_id') ?: null, 'effective_from' => today()]),
            'machines' => Machine::where('is_active', true)->orderBy('code')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $card = new MachineCostCard($this->validated($request));
        $card->created_by = $request->user()->id;
        $card->save();

        return redirect()->route('costing.machine-cards.show', $card)->with('ok', __('حُفظت البطاقة كمسودة.'));
    }

    public function show(MachineCostCard $card): View
    {
        return view('costing.machine-cards.show', [
            'card' => $card->load('machine.costCenter'),
            'names' => DB::table('users')->whereIn('id', array_filter([$card->created_by, $card->approved_by]))->pluck('name', 'id'),
            'actualHours' => (float) DB::table('machine_logs')->where('machine_id', $card->machine_id)->where('work_date', '>=', $card->effective_from)->sum('hours'),
            'activity' => ActivityLog::for(['machine_cost_cards' => [$card->id]]),
        ]);
    }

    public function edit(MachineCostCard $card): View
    {
        abort_unless($card->isDraft(), 404);

        return view('costing.machine-cards.form', ['card' => $card, 'machines' => Machine::where('is_active', true)->orderBy('code')->get()]);
    }

    public function update(Request $request, MachineCostCard $card): RedirectResponse
    {
        if (! $card->isDraft()) {
            throw ValidationException::withMessages(['rule' => __('rroka.errors.RROKA_COST_RECORD_LOCKED')]);
        }
        $card->update($this->validated($request, $card));

        return redirect()->route('costing.machine-cards.show', $card)->with('ok', __('حُدّثت البطاقة.'));
    }

    /** A machine (master data) and the cost centre it works in. */
    public function machineStore(Request $request): RedirectResponse
    {
        $request->merge(['code' => strtoupper(trim((string) $request->input('code')))]);
        Machine::create($request->validate([
            'code' => ['required', 'string', 'max:30', 'unique:machines,code'],
            'name' => ['required', 'string', 'max:255'],
            'cost_center_id' => ['nullable', 'integer', 'exists:cost_centers,id'],
        ]));

        return back()->with('ok', __('تمت إضافة الآلة.'));
    }

    public function machineUpdate(Request $request, Machine $machine): RedirectResponse
    {
        $machine->update($request->validate(['cost_center_id' => ['nullable', 'integer', 'exists:cost_centers,id']]));

        return back()->with('ok', __('حُدّث مركز تكلفة الآلة.'));
    }

    private function validated(Request $request, ?MachineCostCard $card = null): array
    {
        $num = fn (string $min = 'min:0') => ['required', 'numeric', $min, 'max:99999999'];

        return $request->validate([
            'machine_id' => $card ? ['exclude'] : ['required', 'integer', Rule::exists('machines', 'id')->where('is_active', true)],
            'effective_from' => ['required', 'date'],
            'acquisition_cost' => $num(),
            'residual_value' => [...$num(), 'lte:acquisition_cost'],
            'useful_life_years' => $num('gt:0'),
            'theoretical_annual_hours' => [...$num('gt:0'), 'max:8784'],
            'practical_annual_hours' => [...$num('gt:0'), 'lte:theoretical_annual_hours'],
            'power_kw' => $num(),
            'load_factor' => ['required', 'numeric', 'min:0', 'max:1'],
            'annual_maintenance' => $num(),
            'annual_spare_parts' => $num(),
            'annual_other' => $num(),
            'source' => ['required', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]) + ['estimated' => $request->boolean('estimated')];
    }
}
