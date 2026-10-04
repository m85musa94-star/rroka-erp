<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\CostCenter;
use App\Models\Employee;
use App\Models\EmployeeCostCard;
use App\Support\ActivityLog;
use App\Support\ListView;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Employee cost card: monthly cost ÷ practical productive hours = productive hour
 * rate (never salary ÷ products). Approval writes the worker's hour rate.
 */
class EmployeeCostCardController extends Controller
{
    public function index(Request $request): View
    {
        $st = fn (string $s) => fn ($q) => $q->where('status', $s);
        $lv = new ListView($request,
            filters: [
                'draft' => ['label' => __('مسودة'), 'group' => 'st', 'apply' => $st('DRAFT')],
                'approved' => ['label' => __('معتمدة'), 'group' => 'st', 'apply' => $st('APPROVED')],
                'estimated' => ['label' => __('أرقام تقديرية'), 'group' => 'e', 'apply' => fn ($q) => $q->where('estimated', true)],
                'self' => ['label' => __('اعتماد ذاتي (للمراجعة)'), 'group' => 'x', 'apply' => fn ($q) => $q->where('status', 'APPROVED')->whereColumn('approved_by', 'created_by')],
            ],
            groups: ['employee' => ['label' => __('الموظف'), 'key' => fn ($c) => $c->employee_id, 'title' => fn ($c) => $c->employee->name]],
        );
        $query = $lv->applyFilters(EmployeeCostCard::with('employee:id,name')->orderByDesc('effective_from')->orderByDesc('id'));
        if ($lv->q !== '') {
            $query->whereHas('employee', fn ($e) => $e->where('name', 'ilike', "%{$lv->q}%"));
        }

        return view('costing.employee-cards.index', [
            'lv' => $lv,
            'cards' => $lv->group ? null : $query->paginate(30)->withQueryString(),
            'groups' => $lv->group ? $lv->grouped($query->limit(1000)->get()) : null,
        ]);
    }

    /** "Fill from contract" copies the running contract's salary items; the rest is entered. */
    public function create(Request $request): View
    {
        $card = new EmployeeCostCard(['employee_id' => $request->integer('employee_id') ?: null, 'effective_from' => today()]);
        $contract = null;
        if ($card->employee_id && $request->boolean('from_contract')) {
            $contract = DB::table('employee_contracts')->where('employee_id', $card->employee_id)->where('status', 'RUNNING')->first();
            if ($contract) {
                $card->fill(['basic_salary' => $contract->basic_salary, 'housing' => $contract->housing_allowance,
                    'transportation' => $contract->transport_allowance, 'allowances' => $contract->other_allowance,
                    'source' => __('العقد :no', ['no' => $contract->contract_no])]);
            }
        }

        $hasContract = $card->employee_id && DB::table('employee_contracts')->where('employee_id', $card->employee_id)->where('status', 'RUNNING')->exists();

        return view('costing.employee-cards.form', ['card' => $card, 'contract' => $contract, 'hasContract' => $hasContract, 'employees' => $this->employees()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $card = new EmployeeCostCard($this->validated($request));
        $card->created_by = $request->user()->id;
        $card->save();

        return redirect()->route('costing.employee-cards.show', $card)->with('ok', __('حُفظت البطاقة كمسودة. وزّع الساعات على مراكز التكلفة ثم اعتمدها.'));
    }

    public function show(EmployeeCostCard $card): View
    {
        return view('costing.employee-cards.show', [
            'card' => $card->load('employee', 'shares.costCenter'),
            'centers' => CostCenter::where('is_active', true)->orderBy('code')->get(),
            'names' => DB::table('users')->whereIn('id', array_filter([$card->created_by, $card->approved_by]))->pluck('name', 'id'),
            'actualHours' => (float) DB::table('labor_logs')->where('worker_id', $card->employee_id)->where('work_date', '>=', $card->effective_from)->sum('hours'),
            'activity' => ActivityLog::for(['employee_cost_cards' => [$card->id], 'employee_cost_card_shares' => $card->shares->pluck('id')->all()]),
        ]);
    }

    public function edit(EmployeeCostCard $card): View
    {
        abort_unless($card->isDraft(), 404);

        return view('costing.employee-cards.form', ['card' => $card, 'contract' => null, 'hasContract' => false, 'employees' => $this->employees()]);
    }

    public function update(Request $request, EmployeeCostCard $card): RedirectResponse
    {
        if (! $card->isDraft()) {
            throw ValidationException::withMessages(['rule' => __('rroka.errors.RROKA_COST_RECORD_LOCKED')]);
        }
        $card->update($this->validated($request, $card));

        return redirect()->route('costing.employee-cards.show', $card)->with('ok', __('حُدّثت البطاقة.'));
    }

    public function shareStore(Request $request, EmployeeCostCard $card): RedirectResponse
    {
        $data = $request->validate([
            'cost_center_id' => ['required', 'integer', Rule::exists('cost_centers', 'id')->where('is_active', true),
                Rule::unique('employee_cost_card_shares')->where('card_id', $card->id)],
            'share_pct' => ['required', 'numeric', 'gt:0', 'max:100'],
        ]);
        $card->shares()->create($data);

        return back()->with('ok', __('أُضيفت حصة مركز التكلفة.'));
    }

    public function shareDestroy(EmployeeCostCard $card, int $share): RedirectResponse
    {
        $card->shares()->whereKey($share)->firstOrFail()->delete();

        return back()->with('ok', __('حُذفت الحصة.'));
    }

    private function validated(Request $request, ?EmployeeCostCard $card = null): array
    {
        $money = ['required', 'numeric', 'min:0', 'max:999999'];
        $hours = ['required', 'numeric', 'min:0', 'max:744'];
        $rules = ['employee_id' => $card ? ['exclude'] : ['required', 'integer', Rule::exists('workers', 'id')->where('is_active', true)],
            'effective_from' => ['required', 'date'],
            'basic_salary' => ['required', 'numeric', 'gt:0', 'max:999999'],
            'theoretical_hours' => ['required', 'numeric', 'gt:0', 'max:744'],
            'source' => ['required', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:2000']];
        foreach (array_slice(EmployeeCostCard::COSTS, 1) as $k) {
            $rules[$k] = $money;
        }
        foreach (EmployeeCostCard::DEDUCTIONS as $k) {
            $rules[$k] = $hours;
        }
        $data = $request->validate($rules);
        $deducted = array_sum(array_map(fn ($k) => (float) $data[$k], EmployeeCostCard::DEDUCTIONS));
        if ($deducted >= (float) $data['theoretical_hours']) {
            throw ValidationException::withMessages(['theoretical_hours' => __('الساعات غير المنتجة تستهلك كل الساعات النظرية.')]);
        }

        return $data + ['estimated' => $request->boolean('estimated')];
    }

    private function employees()
    {
        return Employee::where('is_active', true)->orderByDesc('is_direct_labor')->orderBy('name')->get(['id', 'name', 'is_direct_labor']);
    }
}
