<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Machine;
use App\Models\MachineRate;
use App\Models\OverheadRate;
use App\Models\Worker;
use App\Models\WorkerRate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Cost rates are entered by the owner from real figures. Nothing is defaulted;
 * history is append-only (a change = a new row with a new effective date).
 */
class CostRateController extends Controller
{
    public function index(): View
    {
        return view('settings.rates', [
            'workers' => Worker::with('rates')->orderBy('name')->get(),
            'machines' => Machine::with('rates')->orderBy('name')->get(),
            'overheads' => OverheadRate::orderByDesc('effective_from')->get(),
        ]);
    }

    public function storeWorker(Request $request): RedirectResponse
    {
        Worker::create($request->validate([
            'name' => ['required', 'string', 'max:255'],
            'trade' => ['nullable', 'string', 'max:100'],
        ]));

        return back()->with('ok', __('تمت إضافة العامل. أدخل أجر ساعته حين يتوفر الرقم الحقيقي.'));
    }

    public function storeMachine(Request $request): RedirectResponse
    {
        Machine::create($request->validate([
            'code' => ['required', 'string', 'max:30', 'unique:machines,code'],
            'name' => ['required', 'string', 'max:255'],
        ]));

        return back()->with('ok', __('تمت إضافة الآلة.'));
    }

    public function storeWorkerRate(Request $request, Worker $worker): RedirectResponse
    {
        WorkerRate::create($this->rate($request) + ['worker_id' => $worker->id]);

        return back()->with('ok', __('تم تسجيل أجر الساعة للعامل :name.', ['name' => $worker->name]));
    }

    public function storeMachineRate(Request $request, Machine $machine): RedirectResponse
    {
        MachineRate::create($this->rate($request) + ['machine_id' => $machine->id]);

        return back()->with('ok', __('تم تسجيل تكلفة الساعة للآلة :name.', ['name' => $machine->name]));
    }

    public function storeOverhead(Request $request): RedirectResponse
    {
        OverheadRate::create($request->validate([
            'basis' => ['required', Rule::in(['PCT_OF_DIRECT_LABOR', 'PCT_OF_PRIME_COST'])],
            'rate_pct' => ['required', 'numeric', 'min:0', 'max:1000'],
            'effective_from' => ['required', 'date'],
            'basis_note' => ['required', 'string', 'max:1000'],
        ]) + ['entered_by' => $request->user()->id]);

        return back()->with('ok', __('تم تسجيل نسبة المصروفات غير المباشرة.'));
    }

    private function rate(Request $request): array
    {
        return $request->validate([
            'hourly_cost' => ['required', 'numeric', 'gt:0'],
            'effective_from' => ['required', 'date'],
            'basis_note' => ['required', 'string', 'max:1000'],
        ]) + ['entered_by' => $request->user()->id];
    }
}
