<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\StudioAsset;
use App\Services\StudioStorage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A supporting image added to an approved document (which never changes): kept beside it
 * in document_attachments, append-only. The "documents without an image" exception clears.
 */
class DocumentAttachmentController extends Controller
{
    /** Route type => [source type, table, permission to attach]. */
    public const TYPES = [
        'expenses' => ['EXPENSE', 'expenses', 'expenses.manage'],
        'purchases' => ['PURCHASE', 'purchase_invoices', 'purchases.manage'],
        'sales-invoices' => ['SALE', 'sales_invoices', 'sales.manage'],
        'receipts' => ['RECEIPT', 'customer_receipts', 'sales.manage'],
        'supplier-payments' => ['SUPPLIER_PAYMENT', 'supplier_payments', 'purchases.manage'],
        'owner-transactions' => ['OWNER', 'owner_transactions', 'accounting.manage'],
        'vat-returns' => ['VAT_RETURN', 'vat_returns', 'accounting.manage'],
    ];

    /** The images attached after approval to one document. */
    public static function for(string $sourceType, int $id)
    {
        return StudioAsset::whereIn('id', DB::table('document_attachments')->where('source_type', $sourceType)->where('source_id', $id)->pluck('asset_id'))
            ->orderBy('id')->get();
    }

    public function store(Request $request, string $type, int $id, StudioStorage $storage): RedirectResponse
    {
        [$source, $table, $perm] = self::TYPES[$type] ?? abort(404);
        abort_unless($request->user()->hasPermission($perm), 403);
        $doc = DB::table($table)->where('id', $id)->first() ?? abort(404);
        $request->validate(['document' => ['required', 'file', 'max:20480', 'mimetypes:image/jpeg,image/png,image/webp']]);
        if (! StudioStorage::isReady()) {
            throw ValidationException::withMessages(['document' => __('مخزن الصور غير مربوط بعد، فلا تُحفظ الصور حتى لا تضيع. راجع دليل النشر.')]);
        }
        $no = $doc->expense_no ?? $doc->purchase_no ?? $doc->invoice_no ?? $doc->receipt_no ?? $doc->payment_no ?? $doc->txn_no ?? $doc->return_no ?? $id;
        [$asset, $existed] = $storage->storeDocument($request->file('document'), __('مستند مؤيد').' '.$no, $request->user()->id);
        DB::table('document_attachments')->insertOrIgnore(['source_type' => $source, 'source_id' => $id, 'asset_id' => $asset->id, 'created_by' => $request->user()->id]);
        if ($existed) {
            session()->flash('warn', __('صورة المستند نفسها مرفقة سابقًا بـ :title — تأكد أنها ليست فاتورة مكررة.', ['title' => $asset->title]));
        }

        return back()->with('ok', __('أُرفقت الصورة بالمستند.'));
    }
}
