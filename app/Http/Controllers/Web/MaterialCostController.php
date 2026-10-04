<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\MaterialStandardPrice;
use App\Models\RawMaterial;
use App\Models\WasteDefault;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Standard material prices and standard waste. The last purchase price and the
 * average stock cost are shown as evidence; the standard is what the user enters.
 */
class MaterialCostController extends Controller
{
    public function prices(Request $request): View
    {
        $today = today()->toDateString();
        $q = trim((string) $request->query('q', ''));

        return view('costing.prices', [
            'q' => $q,
            'materials' => RawMaterial::where('is_active', true)
                ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('code', 'ilike', "%{$q}%")->orWhere('name', 'ilike', "%{$q}%")))
                ->orderBy('code')->get()
                ->map(fn ($m) => (object) [
                    'm' => $m,
                    'standard' => DB::scalar('select fn_standard_price(?, ?)', [$m->id, $today]),
                    'waste' => DB::scalar('select fn_standard_waste_pct(?, ?)', [$m->id, $today]),
                    'last' => DB::table('purchase_invoice_lines as l')->join('purchase_invoices as p', 'p.id', '=', 'l.purchase_invoice_id')
                        ->where('p.status', 'APPROVED')->where('l.material_id', $m->id)->orderByDesc('p.invoice_date')->orderByDesc('l.id')
                        ->first(['l.unit_price', 'p.invoice_date', 'p.purchase_no']),
                    'avg' => DB::table('stock_balances')->where('material_id', $m->id)->value('avg_unit_cost'),
                ]),
            'drafts' => MaterialStandardPrice::with('material')->where('status', 'DRAFT')->orderBy('id')->get(),
            'history' => MaterialStandardPrice::with('material')->where('status', '<>', 'DRAFT')->latest('id')->limit(30)->get(),
        ]);
    }

    public function priceStore(Request $request): RedirectResponse
    {
        $p = new MaterialStandardPrice($request->validate([
            'material_id' => ['required', 'integer', 'exists:raw_materials,id'],
            'effective_from' => ['required', 'date'],
            'unit_price' => ['required', 'numeric', 'gt:0'],
            'price_basis' => ['required', Rule::in(MaterialStandardPrice::BASES)],
            'source' => ['required', 'string', 'max:500'],
        ]) + ['estimated' => $request->boolean('estimated')]);
        $p->created_by = $request->user()->id;
        $p->save();

        return back()->with('ok', __('حُفظ السعر المعياري كمسودة؛ يُستخدم بعد اعتماده.'));
    }

    public function waste(): View
    {
        return view('costing.waste', [
            'rows' => WasteDefault::with('material')->orderByRaw("status = 'DRAFT' DESC")->orderBy('subject_key')->orderByDesc('effective_from')->get(),
            'materials' => RawMaterial::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name', 'category']),
            'categories' => RawMaterial::whereNotNull('category')->distinct()->orderBy('category')->pluck('category'),
        ]);
    }

    public function wasteStore(Request $request): RedirectResponse
    {
        $request->merge(['category' => $request->filled('category') ? trim((string) $request->input('category')) : null]);
        $w = new WasteDefault($request->validate([
            'material_id' => ['nullable', 'integer', 'exists:raw_materials,id', 'required_without:category', 'prohibits:category'],
            'category' => ['nullable', 'string', 'max:100'],
            'effective_from' => ['required', 'date'],
            'waste_pct' => ['required', 'numeric', 'min:0', 'lt:100'],
            'source' => ['required', 'string', 'max:500'],
        ]) + ['estimated' => $request->boolean('estimated')]);
        $w->created_by = $request->user()->id;
        $w->save();

        return back()->with('ok', __('حُفظت نسبة الهالك كمسودة؛ تُستخدم بعد اعتمادها.'));
    }
}
