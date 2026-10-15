<?php

namespace App\Http\Controllers\Web;

use App\Accounting\VatReport;
use App\Http\Controllers\Controller;
use App\Models\PaymentAccount;
use App\Models\VatReturn;
use App\Services\StudioStorage;
use App\Support\ActivityLog;
use App\Support\Xlsx;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * VAT: the tax periods and their returns, the report of a period from the approved
 * documents (print / Excel), the filed return (approval locks the period and posts
 * the settlement; then its payment), and reopening a filed period with a reason.
 */
class VatController extends Controller
{
    public function index(): View
    {
        $s = DB::table('accounting_settings')->first();

        return view('accounting.vat.index', [
            'settings' => $s,
            'periods' => VatReport::periods($s->vat_period),
            'returns' => VatReturn::orderByDesc('period_start')->get(),
            'events' => DB::table('vat_lock_events as e')->join('users as u', 'u.id', '=', 'e.created_by')->orderByDesc('e.id')->limit(10)->get(['e.*', 'u.name']),
        ]);
    }

    public function saveSettings(Request $request): RedirectResponse
    {
        $data = $request->validate(['vat_period' => ['required', Rule::in(['MONTHLY', 'QUARTERLY'])]]);
        DB::table('accounting_settings')->where('id', 1)->update($data);

        return back()->with('ok', __('حُفظت دورية الإقرار.'));
    }

    public function report(Request $request): View|Response
    {
        [$from, $to] = $this->period($request);
        $r = VatReport::build($from, $to);
        $data = ['from' => $from, 'to' => $to, 'r' => $r, 'company' => __('إر روكا للأثاث'),
            'docs' => collect(VatReport::BUCKETS)->mapWithKeys(fn ($b) => [$b => VatReport::documents($from, $to, $b)])];
        if ($request->query('export') === 'xlsx') {
            return $this->xlsx($from, $to, $r, $data['docs']);
        }

        return view($request->boolean('print') ? 'accounting.vat.print' : 'accounting.vat.report', $data);
    }

    public function create(Request $request): View
    {
        [$from, $to] = $this->period($request);

        return view('accounting.vat.form', ['x' => new VatReturn(['period_start' => $from, 'period_end' => $to]), 'r' => VatReport::build($from, $to),
            'canAttach' => StudioStorage::isReady()]);
    }

    public function store(Request $request, StudioStorage $storage): RedirectResponse
    {
        $x = new VatReturn;
        $x->created_by = $request->user()->id;
        $this->save($request, $x, $storage);

        return redirect()->route('accounting.vat.show', $x)->with('ok', __('حُفظ الإقرار كمسودة.'));
    }

    public function show(VatReturn $return): View
    {
        $from = $return->period_start->toDateString();
        $to = $return->period_end->toDateString();

        return view('accounting.vat.show', [
            'x' => $return->load('paidAccount', 'attachment'),
            'r' => VatReport::build($from, $to),
            'accounts' => PaymentAccount::where('is_active', true)->whereIn('kind', ['BANK', 'CASH'])->orderBy('kind')->orderBy('name')->get(['id', 'name', 'kind']),
            'names' => DB::table('users')->whereIn('id', array_filter([$return->created_by, $return->approved_by, $return->paid_by]))->pluck('name', 'id'),
            'activity' => ActivityLog::for(['vat_returns' => [$return->id]]),
        ]);
    }

    public function edit(VatReturn $return): View|RedirectResponse
    {
        if ($return->status !== 'DRAFT') {
            return redirect()->route('accounting.vat.show', $return)->withErrors(['rule' => __('rroka.errors.RROKA_SALES_LOCKED')]);
        }

        return view('accounting.vat.form', ['x' => $return, 'r' => VatReport::build($return->period_start->toDateString(), $return->period_end->toDateString()),
            'canAttach' => StudioStorage::isReady()]);
    }

    public function update(Request $request, VatReturn $return, StudioStorage $storage): RedirectResponse
    {
        $this->save($request, $return, $storage);

        return redirect()->route('accounting.vat.show', $return)->with('ok', __('حُدّث الإقرار.'));
    }

    public function approve(Request $request, VatReturn $return): RedirectResponse
    {
        $this->draftOrFail($return);
        if ($return->filed_output_vat === null || $return->filed_input_vat === null) {
            throw ValidationException::withMessages(['rule' => __('اكتب الأرقام المقدَّمة فعلًا للهيئة قبل الاعتماد.')]);
        }
        $return->forceFill(['status' => 'APPROVED', 'approved_by' => $request->user()->id, 'approved_at' => now()])->save();

        return back()->with('ok', __('اعتُمد الإقرار، وأُقفلت الفترة ضريبيًا، وسُجّل قيد التسوية.'));
    }

    public function cancel(VatReturn $return): RedirectResponse
    {
        $this->draftOrFail($return);
        $return->forceFill(['status' => 'CANCELLED'])->save();

        return back()->with('ok', __('أُلغيت المسودة.'));
    }

    public function pay(Request $request, VatReturn $return): RedirectResponse
    {
        if ($return->status !== 'APPROVED' || $return->paid_date !== null) {
            throw ValidationException::withMessages(['rule' => __('rroka.errors.RROKA_SALES_LOCKED')]);
        }
        $data = $request->validate([
            'paid_date' => ['required', 'date', 'before_or_equal:today'],
            'paid_account_id' => ['required', 'integer', Rule::exists('payment_accounts', 'id')->where('is_active', true)],
            'paid_amount' => ['required', 'numeric', 'gt:0'],
            'paid_reference' => ['nullable', 'string', 'max:100'],
        ]);
        $return->forceFill($data + ['paid_by' => $request->user()->id])->save();

        return back()->with('ok', __('سُجّل سداد الضريبة للهيئة.'));
    }

    public function reopen(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'until' => ['nullable', 'date'],
            'reason' => ['required', 'string', 'max:500'],
        ]);
        DB::select('SELECT fn_vat_reopen(?, ?, ?)', [$data['until'] ?? null, $data['reason'], $request->user()->id]);

        return back()->with('ok', __('أُعيد فتح الفترة الضريبية، وسُجّل السبب.'));
    }

    private function save(Request $request, VatReturn $x, StudioStorage $storage): void
    {
        $data = $request->validate([
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'filed_output_vat' => ['nullable', 'numeric', 'min:0'],
            'filed_input_vat' => ['nullable', 'numeric', 'min:0'],
            'include_verify' => ['boolean'],
            'verify_reason' => ['nullable', 'required_if_accepted:include_verify', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'document' => ['nullable', 'file', 'max:20480', 'mimetypes:image/jpeg,image/png,image/webp'],
        ]);
        $data['include_verify'] = $request->boolean('include_verify');
        unset($data['document']);

        DB::transaction(function () use ($x, $data, $request, $storage) {
            $x->fill($data)->save();
            if ($request->hasFile('document')) {
                [$asset] = $storage->storeDocument($request->file('document'), __('إقرار ضريبي').' '.$x->return_no, $request->user()->id);
                $x->forceFill(['attachment_id' => $asset->id])->save();
            }
        });
    }

    /** The period asked, or the last ended month. */
    private function period(Request $request): array
    {
        $v = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);
        $from = $v['from'] ?? today()->subMonthNoOverflow()->startOfMonth()->toDateString();
        $to = $v['to'] ?? Carbon::parse($from)->endOfMonth()->toDateString();

        return [$from, $to];
    }

    private function xlsx(string $from, string $to, array $r, $docs): Response
    {
        $m = fn ($v) => ['v' => (float) $v, 's' => 'money'];
        $b = $r['buckets'];
        $rows = [[['v' => __('إقرار ضريبة القيمة المضافة'), 's' => 'title']], [__('من :from إلى :to', ['from' => $from, 'to' => $to])], [],
            [['v' => __('البند'), 's' => 'head'], ['v' => __('المبلغ قبل الضريبة'), 's' => 'head'], ['v' => __('الضريبة'), 's' => 'head'], ['v' => __('عدد المستندات'), 's' => 'head']]];
        foreach (VatReport::BUCKETS as $k) {
            $rows[] = [__("rroka.vat_bucket.$k"), $m($b[$k]->amount), $m($b[$k]->vat), (int) $b[$k]->docs];
        }
        $rows[] = [];
        $rows[] = [['v' => __('ضريبة المخرجات'), 's' => 'bold'], '', ['v' => $r['output'], 's' => 'money_bold']];
        $rows[] = [['v' => __('ضريبة المدخلات القابلة للخصم'), 's' => 'bold'], '', ['v' => $r['input'], 's' => 'money_bold']];
        $rows[] = [['v' => __('صافي المستحق للهيئة (أو المسترد)'), 's' => 'bold'], '', ['v' => $r['net'], 's' => 'money_bold']];
        $rows[] = [];
        $rows[] = [['v' => __('المستند'), 's' => 'head'], ['v' => __('التاريخ'), 's' => 'head'], ['v' => __('الطرف'), 's' => 'head'], ['v' => __('الرقم الضريبي'), 's' => 'head'],
            ['v' => __('البند'), 's' => 'head'], ['v' => __('المبلغ قبل الضريبة'), 's' => 'head'], ['v' => __('الضريبة'), 's' => 'head']];
        foreach ($docs as $k => $list) {
            foreach ($list as $d) {
                $rows[] = [$d->doc_no, $d->doc_date, (string) $d->party, (string) $d->party_vat_no, __("rroka.vat_bucket.$k"), $m($d->amount), $m($d->vat)];
            }
        }

        return response(Xlsx::build(__('إقرار ضريبة القيمة المضافة'), $rows, [26, 16, 28, 18, 30, 16, 14], app()->getLocale() === 'ar'), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="vat-'.$from.'-'.$to.'.xlsx"',
        ]);
    }

    private function draftOrFail(VatReturn $doc): void
    {
        if ($doc->status !== 'DRAFT') {
            throw ValidationException::withMessages(['rule' => __('rroka.errors.RROKA_SALES_LOCKED')]);
        }
    }
}
