<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Project;
use App\Models\Supplier;
use App\Services\StudioStorage;
use App\Support\ActivityLog;
use App\Support\ListView;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Expenses: captured once here with their receipt; an approved expense linked to
 * a project is a direct cost of that project. Sent to Daftra once verified.
 */
class ExpenseController extends Controller
{
    public function index(Request $request): View
    {
        $st = fn (string $s) => fn ($q) => $q->where('expenses.status', $s);
        $lv = new ListView($request,
            filters: [
                'draft' => ['label' => __('مسودة'), 'group' => 'st', 'apply' => $st('DRAFT')],
                'approved' => ['label' => __('معتمدة'), 'group' => 'st', 'apply' => $st('APPROVED')],
                'project' => ['label' => __('على مشروع'), 'group' => 'p', 'apply' => fn ($q) => $q->whereNotNull('project_id')],
                'overhead' => ['label' => __('مصروفات غير مباشرة للورشة'), 'group' => 'p', 'apply' => fn ($q) => $q->whereNull('project_id')->whereHas('category', fn ($c) => $c->where('is_overhead', true))],
                'self' => ['label' => __('اعتماد ذاتي (للمراجعة)'), 'group' => 'x', 'apply' => fn ($q) => $q->where('expenses.status', 'APPROVED')->whereColumn('approved_by', 'expenses.created_by')],
                'no_doc' => ['label' => __('بلا صورة مستند'), 'group' => 'd', 'apply' => fn ($q) => $q->whereNull('attachment_id')],
                'this_month' => ['label' => __('هذا الشهر'), 'group' => 'date', 'apply' => fn ($q) => $q->where('expense_date', '>=', now()->startOfMonth())],
            ],
            groups: [
                'category' => ['label' => __('التصنيف'), 'key' => fn ($e) => $e->category_id, 'title' => fn ($e) => $e->category->name],
                'project' => ['label' => __('المشروع'), 'key' => fn ($e) => $e->project_id ?? 0, 'title' => fn ($e) => $e->project?->project_no ?? __('بلا مشروع')],
                'month' => ['label' => __('الشهر'), 'key' => fn ($e) => $e->expense_date->format('Y-m'), 'title' => fn ($e) => $e->expense_date->format('Y-m')],
                'method' => ['label' => __('طريقة الدفع'), 'key' => fn ($e) => $e->payment_method, 'title' => fn ($e) => __("rroka.payment_method.$e->payment_method")],
            ],
            keep: ['project_id'],
        );
        $query = $lv->applyFilters(Expense::with('category:id,name', 'project:id,project_no', 'supplier:id,name')->orderByDesc('expense_date')->orderByDesc('id'))
            ->when($request->integer('project_id'), fn ($q, $id) => $q->where('project_id', $id));
        if ($lv->q !== '') {
            $s = $lv->q;
            $query->where(fn ($q) => $q->where('expense_no', 'ilike', "%{$s}%")->orWhere('description', 'ilike', "%{$s}%")
                ->orWhere('payee', 'ilike', "%{$s}%")->orWhere('reference', 'ilike', "%{$s}%"));
        }

        return view('expenses.index', [
            'lv' => $lv,
            'expenses' => $lv->group ? null : $query->paginate(30)->withQueryString(),
            'groups' => $lv->group ? $lv->grouped($query->limit(2000)->get()) : null,
        ]);
    }

    public function create(Request $request): View
    {
        return view('expenses.form', ['x' => new Expense(['expense_date' => today(), 'payment_method' => 'CASH', 'project_id' => $request->integer('project_id') ?: null]), ...$this->choices()]);
    }

    public function store(Request $request, StudioStorage $storage): RedirectResponse
    {
        $x = DB::transaction(function () use ($request, $storage) {
            $x = new Expense($this->validated($request));
            $x->created_by = $request->user()->id;
            $x->save();
            $this->attach($x, $request, $storage);

            return $x;
        });

        return redirect()->route('expenses.show', $x)->with('ok', __('حُفظ المصروف كمسودة.'));
    }

    public function show(Expense $expense): View
    {
        return view('expenses.show', [
            'x' => $expense->load('category', 'project', 'supplier', 'paidBy', 'attachment'),
            'names' => DB::table('users')->whereIn('id', array_filter([$expense->created_by, $expense->approved_by]))->pluck('name', 'id'),
            'activity' => ActivityLog::for(['expenses' => [$expense->id]]),
        ]);
    }

    public function edit(Expense $expense): View|RedirectResponse
    {
        if ($expense->status !== 'DRAFT') {
            return redirect()->route('expenses.show', $expense)->withErrors(['rule' => __('rroka.errors.RROKA_EXPENSE_LOCKED')]);
        }

        return view('expenses.form', ['x' => $expense, ...$this->choices()]);
    }

    public function update(Request $request, Expense $expense, StudioStorage $storage): RedirectResponse
    {
        DB::transaction(function () use ($expense, $request, $storage) {
            $expense->update($this->validated($request));
            $this->attach($expense, $request, $storage);
        });

        return redirect()->route('expenses.show', $expense)->with('ok', __('حُدّث المصروف.'));
    }

    public function approve(Request $request, Expense $expense): RedirectResponse
    {
        $this->draftOrFail($expense);
        $expense->forceFill(['status' => 'APPROVED', 'approved_by' => $request->user()->id, 'approved_at' => now()])->save();

        return back()->with('ok', __('اعتُمد المصروف.'));
    }

    public function cancel(Expense $expense): RedirectResponse
    {
        $this->draftOrFail($expense);
        $expense->forceFill(['status' => 'CANCELLED'])->save();

        return back()->with('ok', __('أُلغيت المسودة.'));
    }

    public function categories(): View
    {
        return view('expenses.categories', ['categories' => ExpenseCategory::orderBy('name')->get()]);
    }

    public function categoryStore(Request $request): RedirectResponse
    {
        ExpenseCategory::create($request->validate([
            'name' => ['required', 'string', 'max:120', 'unique:expense_categories,name'],
            'daftra_account_ref' => ['nullable', 'string', 'max:120'],
        ]) + ['is_overhead' => $request->boolean('is_overhead')]);

        return back()->with('ok', __('تمت إضافة التصنيف.'));
    }

    public function categoryUpdate(Request $request, ExpenseCategory $category): RedirectResponse
    {
        $category->update($request->validate(['daftra_account_ref' => ['nullable', 'string', 'max:120']])
            + ['is_overhead' => $request->boolean('is_overhead'), 'is_active' => $request->boolean('is_active')]);

        return back()->with('ok', __('تم تحديث التصنيف.'));
    }

    private function attach(Expense $x, Request $request, StudioStorage $storage): void
    {
        if ($request->hasFile('document')) {
            [$asset, $existed] = $storage->storeDocument($request->file('document'), __('إيصال مصروف').' '.$x->expense_no, $request->user()->id);
            $x->forceFill(['attachment_id' => $asset->id])->save();
            if ($existed) {
                session()->flash('warn', __('صورة المستند نفسها مرفقة سابقًا بـ :title — تأكد أنها ليست فاتورة مكررة.', ['title' => $asset->title]));
            }
        }
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'expense_date' => ['required', 'date', 'before_or_equal:today'],
            'category_id' => ['required', 'integer', Rule::exists('expense_categories', 'id')->where('is_active', true)],
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id', 'required_without:payee'],
            'payee' => ['nullable', 'string', 'max:200'],
            'description' => ['required', 'string', 'max:500'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'vat_amount' => ['required', 'numeric', 'min:0'],
            'payment_method' => ['required', Rule::in(Expense::METHODS)],
            'paid_by_employee_id' => ['nullable', 'integer', 'exists:workers,id', 'required_if:payment_method,PETTY_CASH'],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'reference' => ['nullable', 'string', 'max:100'],
            'document' => ['nullable', 'file', 'max:20480', 'mimetypes:image/jpeg,image/png,image/webp'],
        ]);
    }

    private function choices(): array
    {
        return [
            'categories' => ExpenseCategory::where('is_active', true)->orderBy('name')->get(),
            'suppliers' => Supplier::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'projects' => Project::whereNotIn('status', ['CANCELLED'])->orderByDesc('id')->get(['id', 'project_no', 'title']),
            'employees' => Employee::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'canAttach' => StudioStorage::isReady(),
        ];
    }

    /** Approve/cancel act on a draft only (the database refuses changes after that). */
    private function draftOrFail(Expense $doc): void
    {
        if ($doc->status !== 'DRAFT') {
            throw ValidationException::withMessages(['rule' => __('rroka.errors.RROKA_EXPENSE_LOCKED')]);
        }
    }
}
