<?php

namespace Tests\Feature;

use App\Imports\Importer;
use App\Imports\ImportRegistry;
use App\Models\Account;
use App\Models\Client;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\ImportBatch;
use App\Models\JournalEntry;
use App\Models\PaymentAccount;
use App\Models\PurchaseInvoice;
use App\Models\RawMaterial;
use App\Models\SalesInvoice;
use App\Models\Supplier;
use App\Support\Xlsx;
use App\Support\XlsxReader;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/** Templates for every entry screen: download, fill, check (nothing saved), import all or nothing, approve the batch. */
class ImportTest extends ApiTestCase
{
    private function file(array $header, array $rows, string $name = 'filled.xlsx'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, Xlsx::book([['name' => 'البيانات', 'rows' => [$header, ...$rows]]]));
    }

    private function upload($user, string $type, UploadedFile $file): ImportBatch
    {
        $this->actingAs($user)->post("/imports/{$type}", ['file' => $file])->assertSessionHasNoErrors()->assertRedirect();

        return ImportBatch::latest('id')->first();
    }

    private function acc(string $code): int
    {
        return Account::where('code', $code)->value('id');
    }

    public function test_every_screen_template_downloads_and_reads_back(): void
    {
        $admin = $this->admin();
        $this->assertGreaterThanOrEqual(45, count(ImportRegistry::all()));
        foreach (ImportRegistry::all() as $key => $def) {
            $res = $this->actingAs($admin)->get("/imports/{$key}/template")->assertOk();
            $this->assertStringContainsString('spreadsheetml', $res->headers->get('Content-Type'));
            $path = tempnam(sys_get_temp_dir(), 't');
            file_put_contents($path, $res->getContent());
            $parsed = Importer::parse($def, XlsxReader::rows($path, 't.xlsx'));
            $this->assertSame([], $parsed['missing'], $key);
            $this->assertSame([], $parsed['ignored'], $key);
            $this->assertNotNull(route($def['route'] === 'design-versions.bom' ? 'designs.index' : $def['screen']), $key);
            $this->actingAs($admin)->get("/imports/{$key}")->assertOk()->assertSee(__($def['label']));
        }
        $this->actingAs($admin)->get('/imports')->assertOk()->assertSee('سندات القبض')->assertSee('بطاقات تكلفة الموظفين');
    }

    public function test_a_check_saves_nothing_and_uses_no_document_number(): void
    {
        $admin = $this->admin();
        $before = DB::scalar('SELECT last_value FROM seq_supplier_no');
        $batch = $this->upload($admin, 'suppliers', $this->file(['اسم المورد *', 'الرقم الضريبي', 'المدينة', 'عمود إضافي'], [
            ['TEST timber', '300000000000003', 'الرياض', 'x'],
            ['TEST paints', '12345', 'جدة'],
        ]));

        $this->assertSame('PREVIEW', $batch->status);
        $this->assertSame(2, $batch->row_count);
        $this->assertSame(1, $batch->error_count);
        $this->assertSame(0, Supplier::count());
        $this->assertSame($before, DB::scalar('SELECT last_value FROM seq_supplier_no'));
        $bad = $batch->resultsByRow()[3];
        $this->assertFalse($bad['ok']);
        $this->assertStringContainsString('الرقم الضريبي', implode(' ', $bad['messages']));
        $this->actingAs($admin)->get("/imports/batches/{$batch->id}")->assertOk()->assertSee('تنزيل الملف مع الأخطاء')->assertDontSee('استيراد الآن');

        // The file with its errors: same columns plus the result, ready to correct and upload again.
        $res = $this->actingAs($admin)->get("/imports/batches/{$batch->id}/errors")->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'e');
        file_put_contents($path, $res->getContent());
        $rows = XlsxReader::rows($path, 'e.xlsx');
        $this->assertSame('نتيجة الفحص', end($rows[0]));
        $this->assertStringContainsString('الرقم الضريبي', end($rows[2]));
        $this->assertSame([], Importer::parse(ImportRegistry::get('suppliers'), $rows)['ignored']);

        // A file with errors is not imported.
        $this->actingAs($admin)->post("/imports/batches/{$batch->id}/import")->assertSessionHasErrors('rule');
        $this->assertSame(0, Supplier::count());
        $this->actingAs($admin)->post("/imports/batches/{$batch->id}/discard")->assertSessionHasNoErrors();
        $this->assertSame('DISCARDED', $batch->fresh()->status);
    }

    public function test_expenses_import_as_drafts_all_or_nothing_then_approve_the_batch(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post('/accounting/accounts/template')->assertSessionHasNoErrors();
        $this->actingAs($admin)->post('/treasury/accounts', ['kind' => 'CASH', 'name' => 'TEST cash box'])->assertSessionHasNoErrors();
        $cash = PaymentAccount::sole();
        $cat = ExpenseCategory::create(['name' => 'TEST boards', 'is_overhead' => false]);
        $this->actingAs($admin)->put('/accounting/posting/links', ['payment' => [$cash->id => $this->acc('1101')], 'category' => [$cat->id => $this->acc('5205')]])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post('/accounting/posting/auto', ['auto_posting' => 1])->assertSessionHasNoErrors();
        Supplier::create(['name' => 'TEST timber']);
        $header = ['التاريخ', 'التصنيف', 'المورد', 'المستفيد (بلا مورد مسجل)', 'البيان', 'المبلغ قبل الضريبة', 'ضريبة القيمة المضافة', 'دُفع من', 'رقم فاتورة المورد / المرجع'];
        $rows = [
            ['2026-03-01', 'TEST boards', 'TEST timber', '', 'TEST boards on credit', '2,000', '300', 'آجل', 'TT-501'],
            ['15/03/2026', 'test boards', '', 'TEST taxi', 'TEST delivery', '١٥٠', '0', 'TEST cash box', ''],
        ];
        $file = $this->file($header, $rows);
        $batch = $this->upload($admin, 'expenses', $file);
        $this->assertSame(0, $batch->error_count, json_encode($batch->result, JSON_UNESCAPED_UNICODE));
        $this->assertSame(0, Expense::count());
        $this->actingAs($admin)->get("/imports/batches/{$batch->id}")->assertOk()->assertSee('استيراد الآن');

        $this->actingAs($admin)->post("/imports/batches/{$batch->id}/import")->assertSessionHasNoErrors();
        $batch->refresh();
        $this->assertSame(['IMPORTED', 2], [$batch->status, $batch->record_count]);
        $credit = Expense::where('payment_method', 'CREDIT')->sole();
        $paid = Expense::where('payment_method', 'CASH')->sole();
        $this->assertSame(['DRAFT', 'TT-501', '2026-03-01'], [$credit->status, $credit->reference, $credit->expense_date->toDateString()]);
        $this->assertSame(['DRAFT', '150.00', '2026-03-15', $cash->id], [$paid->status, (string) $paid->amount, $paid->expense_date->toDateString(), $paid->payment_account_id]);
        $this->assertStringNotContainsString('-T', $credit->expense_no);
        $this->assertSame([$credit->id, $paid->id], $batch->records()->pluck('record_id')->sort()->values()->all());
        $this->actingAs($admin)->get("/imports/batches/{$batch->id}")->assertOk()->assertSee($credit->expense_no)->assertSee('اعتماد مسودات الدفعة (2)');

        // The same file is imported once.
        $this->actingAs($admin)->post('/imports/expenses', ['file' => $file])->assertSessionHasErrors('file');

        // Approve every draft of the batch in one step (each through the screen's approval).
        $this->actingAs($admin)->post("/imports/batches/{$batch->id}/approve")->assertSessionHasNoErrors();
        $this->assertSame(['APPROVED', 'APPROVED'], Expense::orderBy('id')->pluck('status')->all());
        $this->assertSame(2, JournalEntry::where('source_type', 'EXPENSE')->count());

        // All or nothing: one bad row and the other is not saved either.
        $again = $this->upload($admin, 'expenses', $this->file($header, [
            ['2026-03-02', 'TEST boards', 'TEST timber', '', 'TEST second invoice', '100', '15', 'آجل', 'TT-502'],
            ['2026-03-02', 'TEST boards', 'TEST timber', '', 'TEST same invoice again', '100', '15', 'آجل', 'TT-501'],
        ], 'second.xlsx'));
        $this->assertSame(1, $again->error_count);
        $this->assertStringContainsString('مسجّلة من قبل', implode(' ', $again->resultsByRow()[3]['messages']));
        $this->actingAs($admin)->post("/imports/batches/{$again->id}/import")->assertSessionHasErrors('rule');
        $this->assertSame(2, Expense::count());
    }

    public function test_documents_with_lines_group_their_rows_and_lookups_never_guess(): void
    {
        $admin = $this->admin();
        $supplier = Supplier::create(['name' => 'TEST timber']);
        RawMaterial::create(['code' => 'MDF-18', 'name' => 'TEST MDF 18', 'uom' => 'sheet', 'is_active' => true]);
        RawMaterial::create(['code' => 'GLUE', 'name' => 'TEST glue', 'uom' => 'kg', 'is_active' => true]);
        $header = ['رقم المستند في الملف', 'المورد', 'رقم فاتورة المورد', 'تاريخ الفاتورة', 'الخصم', 'ضريبة القيمة المضافة', 'الخامة', 'الكمية', 'سعر الوحدة'];
        $batch = $this->upload($admin, 'purchases', $this->file($header, [
            ['1', 'TEST timber', 'INV-1', '2026-03-01', '0', '45', 'MDF-18 - TEST MDF 18', '10', '25'],
            ['1', '', '', '', '', '', 'GLUE', '2', '25'],
            ['2', 'TEST timber', 'INV-2', '2026-03-02', '0', '0', 'NO-SUCH', '1', '5'],
        ]));
        $this->assertSame(1, $batch->error_count);
        $res = $batch->resultsByRow();
        $this->assertTrue($res[2]['ok'] && $res[3]['ok']);
        $this->assertStringContainsString('لا يوجد خامة بالقيمة «NO-SUCH»', implode(' ', $res[4]['messages']));

        // Header values differing on a later row of the same document are refused.
        $conflict = $this->upload($admin, 'purchases', $this->file($header, [
            ['1', 'TEST timber', 'INV-1', '2026-03-01', '0', '45', 'MDF-18', '10', '25'],
            ['1', 'TEST timber', 'INV-9', '', '', '', 'GLUE', '2', '25'],
        ], 'conflict.xlsx'));
        $this->assertStringContainsString('يختلف عن السطر الأول', implode(' ', $conflict->resultsByRow()[3]['messages']));

        $ok = $this->upload($admin, 'purchases', $this->file($header, [
            ['A', 'TEST timber', 'INV-1', '2026-03-01', '0', '45', 'MDF-18', '10', '25'],
            ['A', '', '', '', '', '', 'GLUE', '2', '25'],
        ], 'ok.xlsx'));
        $this->actingAs($admin)->post("/imports/batches/{$ok->id}/import")->assertSessionHasNoErrors();
        $p = PurchaseInvoice::sole();
        $this->assertSame([$supplier->id, 'DRAFT', 2], [$p->supplier_id, $p->status, $p->lines()->count()]);

        // Two clients with the same name: the import asks for the number instead of guessing.
        Client::create(['business_name' => 'TEST twin']);
        Client::create(['business_name' => 'TEST twin']);
        $twin = $this->upload($admin, 'customer_receipts', $this->file(['تاريخ القبض', 'العميل', 'استُلم في', 'المبلغ'], [['2026-03-05', 'TEST twin', 'x', '10']], 'twin.xlsx'));
        $this->assertStringContainsString('أكثر من عميل', implode(' ', $twin->resultsByRow()[2]['messages']));
    }

    public function test_a_credit_note_can_name_an_invoice_earlier_in_the_same_file(): void
    {
        $admin = $this->admin();
        Client::create(['business_name' => 'TEST client']);
        $header = ['النوع', 'رقم الفاتورة في دفترة', 'تاريخ الفاتورة', 'العميل', 'الفاتورة الأصلية (للإشعار الدائن)', 'البيان', 'المبلغ قبل الضريبة', 'ضريبة القيمة المضافة'];
        $batch = $this->upload($admin, 'sales_invoices', $this->file($header, [
            ['فاتورة', 'D-100', '2026-03-01', 'TEST client', '', 'TEST kitchen', '1000', '150'],
            ['إشعار دائن', 'D-101', '2026-03-05', '', 'D-100', 'TEST discount', '100', '15'],
        ]));
        $this->assertSame(0, $batch->error_count, json_encode($batch->result, JSON_UNESCAPED_UNICODE));
        $this->assertSame(0, SalesInvoice::count());
        $this->actingAs($admin)->post("/imports/batches/{$batch->id}/import")->assertSessionHasNoErrors();
        $note = SalesInvoice::where('kind', 'CREDIT_NOTE')->sole();
        $this->assertSame(SalesInvoice::where('daftra_invoice_no', 'D-100')->value('id'), $note->original_invoice_id);
    }

    public function test_only_who_may_enter_on_the_screen_may_import_into_it(): void
    {
        $viewer = $this->userWith(['clients.view']);
        $this->actingAs($viewer)->get('/imports/clients')->assertForbidden();
        $this->actingAs($viewer)->get('/imports/clients/template')->assertForbidden();
        $this->actingAs($viewer)->get('/imports')->assertOk()->assertSee('لا توجد قوالب متاحة');
        $this->actingAs($viewer)->get('/clients')->assertOk()->assertDontSee('استيراد من Excel');

        $manager = $this->userWith(['clients.view', 'clients.manage']);
        $this->actingAs($manager)->get('/clients')->assertOk()->assertSee('استيراد من Excel')->assertSee(route('imports.template', 'clients'), false);
        $this->actingAs($manager)->get('/clients/create')->assertOk()->assertSee(route('imports.show', 'clients'), false);
        $this->actingAs($manager)->get('/imports/expenses')->assertForbidden();
        $batch = $this->upload($manager, 'clients', $this->file(['اسم العميل', 'نوع العميل'], [['TEST company', 'منشأة'], ['TEST person', 'INDIVIDUAL']]));
        $this->assertSame(0, $batch->error_count, json_encode($batch->result, JSON_UNESCAPED_UNICODE));
        $this->actingAs($manager)->post("/imports/batches/{$batch->id}/import")->assertSessionHasNoErrors();
        $this->assertSame(['COMPANY', 'INDIVIDUAL'], Client::orderBy('id')->pluck('client_type')->all());
        $this->actingAs($this->userWith(['purchases.manage']))->get("/imports/batches/{$batch->id}")->assertForbidden();
    }
}
