<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\CostCenter;
use App\Models\JournalEntry;
use App\Models\Project;
use App\Support\ActivityLog;
use App\Support\ListView;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Journal entries: a draft is prepared, then posted (the database checks balance, books start
 * and period, and numbers it). A posted entry never changes; it is corrected by a reversal.
 */
class JournalEntryController extends Controller
{
    public function index(Request $request): View
    {
        $lv = new ListView($request,
            filters: [
                'draft' => ['label' => __('مسودة'), 'group' => 'st', 'apply' => fn ($q) => $q->where('journal_entries.status', 'DRAFT')],
                'posted' => ['label' => __('مرحَّل'), 'group' => 'st', 'apply' => fn ($q) => $q->where('journal_entries.status', 'POSTED')],
                'manual' => ['label' => __('يدوي'), 'group' => 'src', 'apply' => fn ($q) => $q->where('source_type', 'MANUAL')],
                'opening' => ['label' => __('افتتاحي'), 'group' => 'src', 'apply' => fn ($q) => $q->where('source_type', 'OPENING')],
                'reversal' => ['label' => __('قيود عكسية'), 'group' => 'src', 'apply' => fn ($q) => $q->where('source_type', 'REVERSAL')],
                'self' => ['label' => __('ترحيل ذاتي (للمراجعة)'), 'group' => 'x', 'apply' => fn ($q) => $q->where('journal_entries.status', 'POSTED')->whereColumn('posted_by', 'journal_entries.created_by')],
                'this_month' => ['label' => __('هذا الشهر'), 'group' => 'date', 'apply' => fn ($q) => $q->where('entry_date', '>=', now()->startOfMonth())],
            ],
            groups: [
                'month' => ['label' => __('الشهر'), 'key' => fn ($e) => $e->entry_date->format('Y-m'), 'title' => fn ($e) => $e->entry_date->format('Y-m')],
                'status' => ['label' => __('الحالة'), 'key' => fn ($e) => $e->status, 'title' => fn ($e) => __("rroka.status.$e->status")],
                'source' => ['label' => __('النوع'), 'key' => fn ($e) => $e->source_type, 'title' => fn ($e) => __("rroka.journal_source.$e->source_type")],
            ],
            keep: ['account_id'],
        );
        $totals = DB::table('journal_lines')->groupBy('entry_id')->selectRaw('entry_id, sum(debit) AS debit, sum(credit) AS credit');
        $query = $lv->applyFilters(JournalEntry::query()
            ->leftJoinSub($totals, 't', 't.entry_id', '=', 'journal_entries.id')
            ->select('journal_entries.*', 't.debit', 't.credit')
            ->orderByDesc('entry_date')->orderByDesc('journal_entries.id'));
        if ($lv->q !== '') {
            $s = $lv->q;
            $query->where(fn ($q) => $q->where('entry_no', 'ilike', "%{$s}%")->orWhere('description', 'ilike', "%{$s}%")->orWhere('reference', 'ilike', "%{$s}%"));
        }

        return view('accounting.journal.index', [
            'lv' => $lv,
            'entries' => $lv->group ? null : $query->paginate(30)->withQueryString(),
            'groups' => $lv->group ? $lv->grouped($query->limit(2000)->get()) : null,
        ]);
    }

    public function create(): View
    {
        return view('accounting.journal.form', ['e' => new JournalEntry(['entry_date' => today(), 'source_type' => 'MANUAL']), 'lines' => [], ...$this->choices()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $e = new JournalEntry(collect($data)->except('lines')->all());
        $e->created_by = $request->user()->id;
        $e->save();
        $this->writeLines($e, $data['lines']);

        return redirect()->route('accounting.journal.show', $e)->with('ok', __('حُفظ القيد كمسودة.'));
    }

    public function show(JournalEntry $entry): View
    {
        $entry->load('lines.account', 'lines.project', 'lines.costCenter', 'reverses', 'reversedBy');

        return view('accounting.journal.show', [
            'e' => $entry,
            'debit' => (float) $entry->lines->sum('debit'),
            'credit' => (float) $entry->lines->sum('credit'),
            'names' => DB::table('users')->whereIn('id', array_filter([$entry->created_by, $entry->posted_by]))->pluck('name', 'id'),
            'activity' => ActivityLog::for(['journal_entries' => [$entry->id], 'journal_lines' => $entry->lines->pluck('id')->all()]),
        ]);
    }

    public function edit(JournalEntry $entry): View|RedirectResponse
    {
        if ($entry->isPosted() || $entry->source_type === 'REVERSAL') {
            return redirect()->route('accounting.journal.show', $entry)->withErrors(['rule' => __('rroka.errors.RROKA_JOURNAL_LOCKED')]);
        }

        return view('accounting.journal.form', [
            'e' => $entry,
            'lines' => $entry->lines->map(fn ($l) => $l->only('account_id', 'debit', 'credit', 'description', 'project_id', 'cost_center_id'))->all(),
            ...$this->choices(),
        ]);
    }

    public function update(Request $request, JournalEntry $entry): RedirectResponse
    {
        $this->draftOrFail($entry);
        if ($entry->source_type === 'REVERSAL') {
            throw ValidationException::withMessages(['rule' => __('القيد العكسي يُنشأ مطابقًا للأصل ولا يُعدَّل؛ احذفه وأعد إنشاءه إن لزم.')]);
        }
        $data = $this->validated($request);
        $entry->lines()->delete();
        $entry->update(collect($data)->except('lines')->all());
        $this->writeLines($entry, $data['lines']);

        return redirect()->route('accounting.journal.show', $entry)->with('ok', __('حُدّث القيد.'));
    }

    public function destroy(JournalEntry $entry): RedirectResponse
    {
        $this->draftOrFail($entry);
        $entry->delete();

        return redirect()->route('accounting.journal.index')->with('ok', __('حُذفت المسودة.'));
    }

    /** Posting is final. Self-posting is allowed (small team) and flagged for review. */
    public function post(Request $request, JournalEntry $entry): RedirectResponse
    {
        $this->draftOrFail($entry);
        $dr = round((float) $entry->lines()->sum('debit'), 2);
        $cr = round((float) $entry->lines()->sum('credit'), 2);
        if ($entry->lines()->count() < 2 || $dr !== $cr || $dr == 0.0) {
            throw ValidationException::withMessages(['rule' => __('القيد غير متوازن: المدين :dr والدائن :cr. يجب أن يتساويا ويزيدا على الصفر.', ['dr' => number_format($dr, 2), 'cr' => number_format($cr, 2)])]);
        }
        $entry->forceFill(['status' => 'POSTED', 'posted_by' => $request->user()->id])->save();

        return back()->with('ok', __('رُحِّل القيد :no.', ['no' => $entry->fresh()->entry_no]));
    }

    /** Prepares the mirror entry of a posted one (a draft, posted like any other entry). */
    public function reverse(Request $request, JournalEntry $entry): RedirectResponse
    {
        if (! $entry->isPosted()) {
            throw ValidationException::withMessages(['rule' => __('rroka.errors.RROKA_JOURNAL_REVERSAL')]);
        }
        if ($entry->reversedBy()->exists()) {
            return redirect()->route('accounting.journal.show', $entry->reversedBy)->withErrors(['rule' => __('لهذا القيد قيد عكسي بالفعل.')]);
        }
        $data = $request->validate([
            'entry_date' => ['required', 'date', 'after_or_equal:'.$entry->entry_date->toDateString()],
            'reason' => ['required', 'string', 'max:300'],
        ]);
        $r = new JournalEntry(['entry_date' => $data['entry_date'], 'source_type' => 'REVERSAL',
            'description' => __('عكس القيد :no — :reason', ['no' => $entry->entry_no, 'reason' => $data['reason']]), 'reference' => $entry->entry_no]);
        $r->reverses_id = $entry->id;
        $r->created_by = $request->user()->id;
        $r->save();
        foreach ($entry->lines as $l) {
            $r->lines()->create(['account_id' => $l->account_id, 'debit' => $l->credit, 'credit' => $l->debit, 'description' => $l->description,
                'project_id' => $l->project_id, 'cost_center_id' => $l->cost_center_id, 'partner_type' => $l->partner_type, 'partner_id' => $l->partner_id]);
        }

        return redirect()->route('accounting.journal.show', $r)->with('ok', __('أُعدّ القيد العكسي كمسودة؛ راجعه ثم رحّله.'));
    }

    private function validated(Request $request): array
    {
        $lines = collect($request->input('lines', []))
            ->filter(fn ($l) => is_array($l) && (($l['account_id'] ?? '') !== '' || (float) ($l['debit'] ?? 0) > 0 || (float) ($l['credit'] ?? 0) > 0))
            ->map(fn ($l) => ['debit' => $l['debit'] ?? null ?: 0, 'credit' => $l['credit'] ?? null ?: 0] + $l)
            ->values()->all();
        $request->merge(['lines' => $lines]);
        $data = $request->validate([
            'entry_date' => ['required', 'date'],
            'description' => ['required', 'string', 'max:500'],
            'reference' => ['nullable', 'string', 'max:100'],
            'source_type' => ['required', Rule::in(['MANUAL', 'OPENING'])],
            'lines' => ['required', 'array', 'min:2'],
            'lines.*.account_id' => ['required', 'integer', Rule::exists('accounts', 'id')->where('is_postable', true)->where('is_active', true)],
            'lines.*.debit' => ['required', 'numeric', 'min:0', 'max:999999999999'],
            'lines.*.credit' => ['required', 'numeric', 'min:0', 'max:999999999999'],
            'lines.*.description' => ['nullable', 'string', 'max:300'],
            'lines.*.project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'lines.*.cost_center_id' => ['nullable', 'integer', 'exists:cost_centers,id'],
        ], ['lines.min' => __('القيد يحتاج سطرين على الأقل.')]);
        foreach ($data['lines'] as $i => $l) {
            if (((float) $l['debit'] > 0) === ((float) $l['credit'] > 0)) {
                throw ValidationException::withMessages(["lines.$i.debit" => __('السطر :n: اكتب مبلغًا في المدين أو في الدائن، لا في كليهما ولا تتركهما فارغين.', ['n' => $i + 1])]);
            }
        }
        if ($data['source_type'] === 'OPENING') {
            $start = DB::table('accounting_settings')->value('books_start');
            if ($start && $data['entry_date'] !== $start) {
                throw ValidationException::withMessages(['entry_date' => __('القيد الافتتاحي يؤرَّخ بتاريخ بداية الدفاتر :d.', ['d' => $start])]);
            }
        }

        return $data;
    }

    private function writeLines(JournalEntry $e, array $lines): void
    {
        foreach ($lines as $l) {
            $e->lines()->create([
                'account_id' => $l['account_id'], 'debit' => $l['debit'], 'credit' => $l['credit'], 'description' => $l['description'] ?? null,
                'project_id' => $l['project_id'] ?? null, 'cost_center_id' => $l['cost_center_id'] ?? null,
            ]);
        }
    }

    private function choices(): array
    {
        return [
            'accounts' => Account::where('is_postable', true)->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name', 'name_en']),
            'projects' => Project::orderByDesc('id')->limit(500)->get(['id', 'project_no']),
            'centers' => CostCenter::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
        ];
    }

    private function draftOrFail(JournalEntry $e): void
    {
        if ($e->isPosted()) {
            throw ValidationException::withMessages(['rule' => __('rroka.errors.RROKA_JOURNAL_LOCKED')]);
        }
    }
}
