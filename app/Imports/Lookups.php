<?php

namespace App\Imports;

use Illuminate\Support\Facades\DB;

/**
 * Turns what a person writes in a template cell (a number, a code or a name) into
 * the record id the screen's form would send. A value matching more than one record
 * is refused (write the number instead); nothing is ever guessed.
 *
 * Each list: table, the columns matched in order, the text shown in the template's
 * dropdown (`list`, an SQL expression), and which rows the dropdown offers (`where`).
 * A dropdown value "code - name" matches by its code.
 */
class Lookups
{
    public static function all(): array
    {
        return [
            'client' => ['table' => 'clients', 'match' => ['client_no', 'business_name', 'vat_number'], 'list' => 'business_name', 'noun' => 'عميل'],
            'supplier' => ['table' => 'suppliers', 'match' => ['supplier_no', 'name', 'vat_number'], 'list' => 'name', 'where' => 'is_active', 'noun' => 'مورد'],
            'employee' => ['table' => 'workers', 'match' => ['employee_no', 'name', 'id_number'], 'list' => 'name', 'where' => 'is_active', 'noun' => 'موظف'],
            'department' => ['table' => 'departments', 'match' => ['name'], 'list' => 'name', 'where' => 'is_active', 'noun' => 'قسم'],
            'job' => ['table' => 'job_positions', 'match' => ['name'], 'list' => 'name', 'where' => 'is_active', 'noun' => 'مسمى وظيفي'],
            'expense_category' => ['table' => 'expense_categories', 'match' => ['name'], 'list' => 'name', 'where' => 'is_active', 'noun' => 'تصنيف مصروف'],
            'payment_account' => ['table' => 'payment_accounts', 'match' => ['name'], 'list' => 'name', 'where' => 'is_active', 'noun' => 'خزينة'],
            'account' => ['table' => 'accounts', 'match' => ['code', 'name'], 'list' => "code || ' - ' || name", 'where' => 'is_postable AND is_active', 'order' => 'code', 'noun' => 'حساب'],
            'account_group' => ['table' => 'accounts', 'match' => ['code', 'name'], 'list' => "code || ' - ' || name", 'where' => 'NOT is_postable AND is_active', 'filter' => 'NOT is_postable', 'order' => 'code', 'noun' => 'مجموعة حسابات'],
            'project' => ['table' => 'projects', 'match' => ['project_no', 'title'], 'list' => "project_no || ' - ' || title", 'where' => "status <> 'CANCELLED'", 'order' => 'id DESC', 'noun' => 'مشروع'],
            'quotation' => ['table' => 'quotations', 'match' => ['quotation_no'], 'list' => 'quotation_no', 'order' => 'id DESC', 'noun' => 'عرض سعر'],
            'approved_quotation' => ['table' => 'quotations', 'match' => ['quotation_no'], 'list' => 'quotation_no', 'where' => "status = 'APPROVED'", 'order' => 'id DESC', 'noun' => 'عرض سعر'],
            'material' => ['table' => 'raw_materials', 'match' => ['code', 'name'], 'list' => "code || ' - ' || name", 'where' => 'is_active', 'order' => 'code', 'noun' => 'خامة'],
            'vat_rate' => ['table' => 'vat_rates', 'match' => ['name'], 'list' => 'name', 'where' => 'is_active', 'noun' => 'نسبة ضريبة'],
            'cost_center' => ['table' => 'cost_centers', 'match' => ['code', 'name'], 'list' => "code || ' - ' || name", 'where' => 'is_active', 'order' => 'code', 'noun' => 'مركز تكلفة'],
            'machine' => ['table' => 'machines', 'match' => ['code', 'name'], 'list' => "code || ' - ' || name", 'where' => 'is_active', 'order' => 'code', 'noun' => 'آلة'],
            'user' => ['table' => 'users', 'match' => ['email', 'name'], 'list' => 'name', 'where' => 'is_active', 'noun' => 'مستخدم'],
            'leave_type' => ['table' => 'leave_types', 'match' => ['name'], 'list' => 'name', 'where' => 'is_active', 'noun' => 'نوع إجازة'],
            'production_order' => ['table' => 'production_orders', 'match' => ['order_no'], 'list' => 'order_no', 'where' => "status NOT IN ('COMPLETED', 'CANCELLED')", 'order' => 'id DESC', 'noun' => 'أمر تصنيع'],
            'sales_invoice' => ['table' => 'sales_invoices', 'match' => ['daftra_invoice_no', 'invoice_no'], 'list' => 'daftra_invoice_no', 'where' => "kind = 'INVOICE' AND status <> 'CANCELLED'", 'filter' => "kind = 'INVOICE'", 'order' => 'id DESC', 'noun' => 'فاتورة عميل'],
        ];
    }

    /**
     * @return int|null the id, or null for an empty cell
     *
     * @throws ImportError when nothing or more than one record matches
     */
    public static function resolve(string $name, string $value): ?int
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $def = self::all()[$name];
        $ids = self::match($def, $value);
        if (! $ids && str_contains($value, ' - ')) {
            $ids = self::match($def, trim(explode(' - ', $value, 2)[0]));
        }
        if (count($ids) === 1) {
            return (int) $ids[0];
        }
        throw new ImportError($ids
            ? __('أكثر من :noun بالقيمة «:v»؛ اكتب الرقم أو الرمز بدل الاسم.', ['noun' => __($def['noun']), 'v' => $value])
            : __('لا يوجد :noun بالقيمة «:v».', ['noun' => __($def['noun']), 'v' => $value]));
    }

    /** @return list<int> ids matching by the first column that matches anything */
    private static function match(array $def, string $value): array
    {
        foreach ($def['match'] as $col) {
            $q = DB::table($def['table'])->whereRaw("lower(trim({$col}::text)) = lower(?)", [$value]);
            if (! empty($def['filter'])) {
                $q->whereRaw($def['filter']);
            }
            $ids = $q->limit(3)->pluck('id')->all();
            if ($ids) {
                return $ids;
            }
        }

        return [];
    }

    /** @return list<string> the values the template's dropdown offers */
    public static function options(string $name, int $limit = 1500): array
    {
        $def = self::all()[$name];
        $q = DB::table($def['table'])->selectRaw("{$def['list']} AS v")->whereRaw("{$def['list']} IS NOT NULL");
        if (! empty($def['where'])) {
            $q->whereRaw($def['where']);
        }

        return $q->orderByRaw($def['order'] ?? 'v')->limit($limit)->pluck('v')->map(fn ($v) => (string) $v)->unique()->values()->all();
    }
}
