<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Odoo-style chart of accounts: detailed account type (deciding the class) and
 * "allow reconciliation". Accounts of the proposed chart get their type; any other
 * postable account is left for the owner to classify. Same SQL is at the end of
 * rroka_schema.sql.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
-- ---------------------------------------------------------------------
-- Accounting (2026-10-07): Odoo-style chart. Every postable account has a
-- detailed type (Receivable, Bank and Cash, Current Assets, Cost of
-- Revenue, ...) that decides its class and its place in the statements;
-- groups (non-postable) carry none. Receivable/payable accounts always
-- allow reconciliation; only one current-year-earnings account.
-- ---------------------------------------------------------------------

ALTER TABLE accounts ADD COLUMN IF NOT EXISTS detail_type text;
ALTER TABLE accounts ADD COLUMN IF NOT EXISTS reconcile boolean NOT NULL DEFAULT false;
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'accounts_detail_type_check') THEN
        ALTER TABLE accounts ADD CONSTRAINT accounts_detail_type_check CHECK (detail_type IN (
            'RECEIVABLE', 'BANK_CASH', 'CURRENT_ASSETS', 'NON_CURRENT_ASSETS', 'PREPAYMENTS', 'FIXED_ASSETS',
            'PAYABLE', 'CREDIT_CARD', 'CURRENT_LIABILITIES', 'NON_CURRENT_LIABILITIES',
            'EQUITY', 'CURRENT_YEAR_EARNINGS', 'INCOME', 'OTHER_INCOME', 'EXPENSES', 'DEPRECIATION', 'COST_OF_REVENUE'));
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'accounts_group_no_detail_type') THEN
        ALTER TABLE accounts ADD CONSTRAINT accounts_group_no_detail_type CHECK (is_postable OR detail_type IS NULL);
    END IF;
END $$;
CREATE UNIQUE INDEX IF NOT EXISTS ux_accounts_current_year_earnings ON accounts ((true)) WHERE detail_type = 'CURRENT_YEAR_EARNINGS';

CREATE OR REPLACE FUNCTION fn_account_class(p_detail text) RETURNS text AS $$
    SELECT CASE
        WHEN p_detail IN ('RECEIVABLE', 'BANK_CASH', 'CURRENT_ASSETS', 'NON_CURRENT_ASSETS', 'PREPAYMENTS', 'FIXED_ASSETS') THEN 'ASSET'
        WHEN p_detail IN ('PAYABLE', 'CREDIT_CARD', 'CURRENT_LIABILITIES', 'NON_CURRENT_LIABILITIES') THEN 'LIABILITY'
        WHEN p_detail IN ('EQUITY', 'CURRENT_YEAR_EARNINGS') THEN 'EQUITY'
        WHEN p_detail IN ('INCOME', 'OTHER_INCOME') THEN 'REVENUE'
        WHEN p_detail IN ('EXPENSES', 'DEPRECIATION', 'COST_OF_REVENUE') THEN 'EXPENSE'
    END
$$ LANGUAGE sql IMMUTABLE;

-- Runs before fn_account_guard (trigger names sort alphabetically), so the
-- class it derives is the one the parent check sees.
CREATE OR REPLACE FUNCTION fn_account_detail() RETURNS trigger AS $$
BEGIN
    IF NEW.detail_type IS NOT NULL THEN
        NEW.account_type := fn_account_class(NEW.detail_type);
        IF NEW.detail_type IN ('RECEIVABLE', 'PAYABLE') THEN
            NEW.reconcile := true;
        END IF;
    ELSIF NEW.is_postable AND (TG_OP = 'INSERT' OR OLD.detail_type IS NOT NULL) THEN
        RAISE EXCEPTION 'RROKA_ACCOUNT_DETAIL_TYPE: a postable account needs its detailed type' USING ERRCODE = 'P0001';
    END IF;
    RETURN NEW;
END $$ LANGUAGE plpgsql;
DROP TRIGGER IF EXISTS trg_account_detail ON accounts;
CREATE TRIGGER trg_account_detail BEFORE INSERT OR UPDATE ON accounts FOR EACH ROW EXECUTE FUNCTION fn_account_detail();

-- Accounts of the proposed chart get their detailed type (matched on code and name, so an
-- account the owner renamed or re-purposed is left for review rather than guessed).
UPDATE accounts a SET detail_type = m.detail
  FROM (VALUES ('1101', 'النقدية في الصندوق', 'BANK_CASH'), ('1103', 'عهد الموظفين', 'CURRENT_ASSETS'),
               ('1110', 'العملاء (الذمم المدينة)', 'RECEIVABLE'), ('1120', 'مخزون الخامات والمستلزمات', 'CURRENT_ASSETS'),
               ('1130', 'أعمال تحت التنفيذ (تكلفة المشاريع الجارية)', 'CURRENT_ASSETS'), ('1140', 'ضريبة القيمة المضافة — المدخلات', 'CURRENT_ASSETS'),
               ('1150', 'مصروفات مدفوعة مقدمًا', 'PREPAYMENTS'), ('1160', 'سلف الموظفين', 'CURRENT_ASSETS'),
               ('1201', 'المكائن والمعدات', 'FIXED_ASSETS'), ('1202', 'الأثاث والتجهيزات المكتبية', 'FIXED_ASSETS'),
               ('1203', 'السيارات', 'FIXED_ASSETS'), ('1209', 'مجمع إهلاك الأصول الثابتة', 'FIXED_ASSETS'),
               ('2101', 'الموردون (الذمم الدائنة)', 'PAYABLE'), ('2110', 'ضريبة القيمة المضافة — المخرجات', 'CURRENT_LIABILITIES'),
               ('2111', 'ضريبة القيمة المضافة — صافي المستحق للهيئة', 'CURRENT_LIABILITIES'), ('2120', 'دفعات مقدمة من العملاء', 'CURRENT_LIABILITIES'),
               ('2130', 'رواتب مستحقة', 'CURRENT_LIABILITIES'), ('2140', 'مصروفات مستحقة', 'CURRENT_LIABILITIES'),
               ('2150', 'مخصص الزكاة', 'CURRENT_LIABILITIES'), ('2201', 'مخصص مكافأة نهاية الخدمة', 'NON_CURRENT_LIABILITIES'),
               ('3101', 'رأس المال', 'EQUITY'), ('3201', 'جاري المالك', 'EQUITY'), ('3301', 'الأرباح المبقاة', 'EQUITY'),
               ('4101', 'إيرادات المشاريع (تصنيع وتوريد وتركيب)', 'INCOME'), ('4190', 'خصومات المبيعات', 'INCOME'),
               ('4201', 'إيرادات أخرى', 'OTHER_INCOME'), ('5101', 'تكلفة المشاريع المسلّمة', 'COST_OF_REVENUE'),
               ('5201', 'أجور غير مباشرة', 'EXPENSES'), ('5202', 'إيجار الورشة', 'EXPENSES'), ('5203', 'كهرباء ومياه', 'EXPENSES'),
               ('5204', 'صيانة المكائن والمعدات', 'EXPENSES'), ('5205', 'أدوات ومستهلكات الورشة', 'EXPENSES'),
               ('5206', 'إهلاك المكائن والمعدات', 'DEPRECIATION'), ('5301', 'رواتب إدارية', 'EXPENSES'),
               ('5302', 'رسوم حكومية وتأشيرات وإقامات', 'EXPENSES'), ('5303', 'تأمينات اجتماعية وطبي', 'EXPENSES'),
               ('5304', 'اتصالات وإنترنت وبرامج', 'EXPENSES'), ('5305', 'أتعاب مهنية', 'EXPENSES'), ('5306', 'رسوم بنكية', 'EXPENSES'),
               ('5307', 'ضيافة ونثريات مكتبية', 'EXPENSES'), ('5308', 'مصاريف التأسيس', 'EXPENSES'), ('5309', 'وقود ونقل', 'EXPENSES'),
               ('5390', 'مصروفات أخرى', 'EXPENSES'), ('5401', 'تسويق وإعلان', 'EXPENSES'), ('5901', 'مصروف الزكاة', 'EXPENSES'))
       AS m(code, name, detail)
 WHERE a.code = m.code AND a.name = m.name AND a.detail_type IS NULL AND a.is_postable
   AND fn_account_class(m.detail) = a.account_type;
SQL);
    }
};
