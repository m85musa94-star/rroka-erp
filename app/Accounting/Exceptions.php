<?php

namespace App\Accounting;

use App\Http\Controllers\Web\PostingController;
use App\Models\JournalEntry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The exceptions board: every detective control in one place, so review is by
 * exception. RED blocks closing a month (enforced by the database for the
 * month-specific ones), ORANGE needs review before the VAT return, YELLOW is
 * for periodic review. Each item lists its records, each with its link.
 */
class Exceptions
{
    public const OVERDUE_DAYS = 60;

    /** @return array<string, array{severity: string, label: string, hint: string}> */
    public static function definitions(): array
    {
        return [
            'backlog' => ['severity' => 'RED', 'label' => __('مستندات معتمدة لم تُرحَّل للدفاتر'), 'hint' => __('رحّلها أو استبعدها بسبب مكتوب من «مستندات لم تُرحَّل». تمنع إقفال شهرها.')],
            'reconciliation' => ['severity' => 'RED', 'label' => __('فروق مطابقة الدفاتر'), 'hint' => __('سجل تفصيلي لا يساوي حسابه في الدفاتر. التفاصيل في «مطابقة الدفاتر».')],
            'partnerless' => ['severity' => 'RED', 'label' => __('مبالغ على العملاء أو الموردين بلا اسم الطرف'), 'hint' => __('سطور قديمة قبل فرض القاعدة؛ تُصحَّح بقيد عكسي ثم قيد يذكر الطرف. تمنع إقفال شهرها.')],
            'vat_rate' => ['severity' => 'ORANGE', 'label' => __('ضريبة بنسبة غير 15% أو صفر'), 'hint' => __('غالبًا خطأ إدخال؛ راجعها قبل الإقرار الضريبي.')],
            'vat_verify' => ['severity' => 'ORANGE', 'label' => __('ضريبة مورد بلا رقم ضريبي'), 'hint' => __('أضف الرقم الضريبي للمورد إن وُجد؛ وإلا لا تُخصم إلا بقرار في الإقرار.')],
            'no_image' => ['severity' => 'ORANGE', 'label' => __('مستندات معتمدة بلا صورة'), 'hint' => __('المستند المؤيد مطلوب للمراجعة والزكاة والضريبة.')],
            'self_approved' => ['severity' => 'YELLOW', 'label' => __('اعتماد ذاتي (للمراجعة)'), 'hint' => __('أدخله واعتمده المستخدم نفسه؛ يراجعه شخص آخر دوريًا.')],
            'supplier_overdue' => ['severity' => 'YELLOW', 'label' => __('فواتير موردين آجلة متأخرة السداد'), 'hint' => __('معتمدة منذ أكثر من :d يومًا ولها رصيد قائم.', ['d' => self::OVERDUE_DAYS])],
            'client_overdue' => ['severity' => 'YELLOW', 'label' => __('فواتير عملاء غير محصّلة'), 'hint' => __('أكثر من :d يومًا ولها رصيد قائم (حسب ربط سند القبض بالفاتورة).', ['d' => self::OVERDUE_DAYS])],
        ];
    }

    /** Items that have something, in severity order: key, severity, label, hint, count, amount. */
    public static function summary(): array
    {
        $out = [];
        foreach (self::definitions() as $key => $def) {
            $rows = self::rows($key);
            if ($rows->isNotEmpty()) {
                $out[] = $def + ['key' => $key, 'count' => $rows->count(), 'amount' => $rows->every(fn ($r) => $r->amount === null) ? null : (float) $rows->sum(fn ($r) => abs((float) $r->amount))];
            }
        }

        return $out;
    }

    /** Count of RED and ORANGE items (the home card). */
    public static function attentionCount(): int
    {
        return collect(self::summary())->whereIn('severity', ['RED', 'ORANGE'])->sum('count');
    }

    /** The records of one item: doc_no, doc_date, label, amount, url. */
    public static function rows(string $key): Collection
    {
        $lock = DB::table('accounting_settings')->value('vat_locked_until') ?? '1900-01-01';
        $old = today()->subDays(self::OVERDUE_DAYS)->toDateString();
        $docs = fn (string $sql, array $b = []) => collect(DB::select($sql, $b))->map(function ($r) {
            $r->url = JournalEntry::documentUrl($r->source_type, (int) $r->source_id);

            return $r;
        });

        return match ($key) {
            'backlog' => $docs('SELECT source_type, source_id, doc_no, doc_date, description AS label, amount FROM v_posting_backlog ORDER BY doc_date'),
            'reconciliation' => self::reconciliation(),
            'partnerless' => collect(DB::select("SELECT l.entry_id, l.entry_no AS doc_no, l.entry_date AS doc_date, a.code || ' ' || a.name AS label, l.net AS amount
                  FROM v_ledger_lines l JOIN accounts a ON a.id = l.account_id
                 WHERE a.detail_type IN ('RECEIVABLE', 'PAYABLE') AND l.partner_id IS NULL ORDER BY l.entry_date"))
                ->map(fn ($r) => tap($r, fn ($r) => $r->url = route('accounting.journal.show', $r->entry_id))),
            'vat_rate' => $docs('SELECT source_type, source_id, doc_no, doc_date, party AS label, vat AS amount FROM v_vat_documents
                 WHERE NOT rate_ok AND doc_date > ? ORDER BY doc_date', [$lock]),
            'vat_verify' => $docs("SELECT source_type, source_id, doc_no, doc_date, party AS label, vat AS amount FROM v_vat_documents
                 WHERE bucket = 'PURCH_VERIFY' AND doc_date > ? ORDER BY doc_date", [$lock]),
            'no_image' => $docs('SELECT * FROM ('.self::approvedDocs().') d WHERE attachment_id IS NULL ORDER BY doc_date'),
            'self_approved' => $docs('SELECT * FROM ('.self::approvedDocs().') d WHERE approved_by = created_by ORDER BY doc_date'),
            'supplier_overdue' => $docs("SELECT * FROM (
                    SELECT 'EXPENSE' AS source_type, e.id AS source_id, e.expense_no AS doc_no, e.expense_date AS doc_date, s.name || ' — ' || e.reference AS label,
                           e.amount + e.vat_amount - COALESCE((SELECT sum(amount) FROM supplier_payments WHERE expense_id = e.id AND status = 'APPROVED'), 0) AS amount
                      FROM expenses e JOIN suppliers s ON s.id = e.supplier_id
                     WHERE e.payment_method = 'CREDIT' AND e.status = 'APPROVED' AND e.expense_date < ?
                    UNION ALL
                    SELECT 'PURCHASE', p.id, p.purchase_no, p.invoice_date, s.name || ' — ' || p.supplier_invoice_no,
                           t.total - COALESCE((SELECT sum(amount) FROM supplier_payments WHERE purchase_invoice_id = p.id AND status = 'APPROVED'), 0)
                      FROM purchase_invoices p JOIN v_purchase_totals t ON t.purchase_invoice_id = p.id JOIN suppliers s ON s.id = p.supplier_id
                     WHERE p.status = 'APPROVED' AND p.invoice_date < ?) d
                 WHERE amount > 0.005 ORDER BY doc_date", [$old, $old]),
            'client_overdue' => $docs("SELECT * FROM (
                    SELECT 'SALE' AS source_type, si.id AS source_id, si.invoice_no AS doc_no, si.invoice_date AS doc_date, c.business_name || ' — ' || si.daftra_invoice_no AS label,
                           si.total - COALESCE((SELECT sum(total) FROM sales_invoices cn WHERE cn.original_invoice_id = si.id AND cn.status = 'APPROVED'), 0)
                                    - COALESCE((SELECT sum(amount) FROM customer_receipts r WHERE r.sales_invoice_id = si.id AND r.status = 'APPROVED'), 0) AS amount
                      FROM sales_invoices si JOIN clients c ON c.id = si.client_id
                     WHERE si.kind = 'INVOICE' AND si.status = 'APPROVED' AND si.invoice_date < ?) d
                 WHERE amount > 0.005 ORDER BY doc_date", [$old]),
            default => abort(404),
        };
    }

    /** Approved documents of every kind, with their attachment and who entered / approved them (a transfer has no image: 0). */
    private static function approvedDocs(): string
    {
        return "SELECT 'EXPENSE' AS source_type, id AS source_id, expense_no AS doc_no, expense_date AS doc_date, description AS label, amount + vat_amount AS amount,
                       attachment_id, created_by, approved_by FROM expenses WHERE status = 'APPROVED'
                UNION ALL SELECT 'PURCHASE', p.id, p.purchase_no, p.invoice_date, p.supplier_invoice_no, t.total, p.attachment_id, p.created_by, p.approved_by
                  FROM purchase_invoices p JOIN v_purchase_totals t ON t.purchase_invoice_id = p.id WHERE p.status = 'APPROVED'
                UNION ALL SELECT 'SALE', id, invoice_no, invoice_date, description, total, attachment_id, created_by, approved_by FROM sales_invoices WHERE status = 'APPROVED'
                UNION ALL SELECT 'RECEIPT', id, receipt_no, receipt_date, COALESCE(reference, ''), amount, attachment_id, created_by, approved_by FROM customer_receipts WHERE status = 'APPROVED'
                UNION ALL SELECT 'SUPPLIER_PAYMENT', id, payment_no, payment_date, COALESCE(reference, ''), amount, attachment_id, created_by, approved_by FROM supplier_payments WHERE status = 'APPROVED'
                UNION ALL SELECT 'OWNER', id, txn_no, txn_date, description, amount, attachment_id, created_by, approved_by FROM owner_transactions WHERE status = 'APPROVED'
                UNION ALL SELECT 'TRANSFER', id, transfer_no, transfer_date, COALESCE(notes, ''), amount, 0::bigint, created_by, approved_by FROM treasury_transfers WHERE status = 'APPROVED'";
    }

    private static function reconciliation(): Collection
    {
        $d = PostingController::reconciliationData();
        $lines = collect($d['rows'])->merge($d['custody'])
            ->filter(fn ($r) => $r['gl'] !== null && abs(round($r['sub'] - $r['gl'], 2)) >= 0.01)
            ->map(fn ($r) => (object) ['doc_no' => $r['account']?->code ?? '—', 'doc_date' => null, 'label' => $r['label'], 'amount' => round($r['sub'] - $r['gl'], 2),
                'url' => route('accounting.posting.reconciliation')]);
        foreach ($d['projects'] as $p) {
            if (abs(round($p->sub - $p->gl, 2)) >= 0.01) {
                $lines->push((object) ['doc_no' => $p->project_no, 'doc_date' => null, 'label' => __('أعمال تحت التنفيذ — :t', ['t' => $p->title]),
                    'amount' => round($p->sub - $p->gl, 2), 'url' => route('accounting.posting.reconciliation')]);
            }
        }

        return $lines->values();
    }
}
