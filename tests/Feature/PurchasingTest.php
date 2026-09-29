<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\PurchaseInvoice;
use App\Models\RawMaterial;
use App\Models\StudioAsset;
use App\Models\Supplier;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class PurchasingTest extends ApiTestCase
{
    private function supplierAndMaterials($admin): array
    {
        $this->actingAs($admin)->post('/suppliers', ['name' => 'TEST timber', 'vat_number' => '300000000000003', 'iban' => 'sa03 8000 0000 6080 1016 7519'])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post('/inventory', ['code' => 'PLY', 'name' => 'TEST plywood', 'uom' => 'sheet', 'is_active' => 1]);
        $this->actingAs($admin)->post('/inventory', ['code' => 'GLUE', 'name' => 'TEST glue', 'uom' => 'L', 'is_active' => 1]);

        return [Supplier::sole(), RawMaterial::where('code', 'PLY')->sole(), RawMaterial::where('code', 'GLUE')->sole()];
    }

    public function test_supplier_invoice_approval_moves_stock_at_net_cost_once(): void
    {
        Storage::fake('studio');
        $admin = $this->admin();
        [$s, $ply, $glue] = $this->supplierAndMaterials($admin);
        $this->assertSame('SA0380000000608010167519', $s->iban);

        $invoice = ['supplier_id' => $s->id, 'supplier_invoice_no' => 'INV-77', 'invoice_date' => now()->toDateString(),
            'discount_amount' => 30, 'vat_amount' => 145.5,
            'lines' => [['material_id' => $ply->id, 'quantity' => 10, 'unit_price' => 90], ['material_id' => $glue->id, 'quantity' => 5, 'unit_price' => 20]],
            'document' => UploadedFile::fake()->image('inv.jpg')];
        $this->actingAs($admin)->post('/purchases', $invoice)->assertRedirect();
        $p = PurchaseInvoice::sole();
        $this->assertSame('DRAFT', $p->status);
        $this->assertSame(0, DB::table('stock_movements')->count(), 'a draft does not move stock');
        $this->assertSame('DOCUMENT', StudioAsset::sole()->category);

        // Same supplier invoice number again is refused (case/space-insensitive).
        $this->actingAs($admin)->post('/purchases', ['supplier_invoice_no' => ' inv-77'] + $invoice)->assertSessionHasErrors('rule');

        $this->actingAs($admin)->post("/purchases/{$p->id}/approve")->assertSessionHasNoErrors();
        $this->assertSame('APPROVED', $p->fresh()->status);
        $this->assertEquals(87.3, (float) DB::table('stock_movements')->where('material_id', $ply->id)->value('unit_cost'));
        $this->assertEquals(10, (float) DB::table('stock_balances')->where('material_id', $ply->id)->value('qty_on_hand'));
        $this->assertEquals(970, round((float) DB::table('stock_movements')->selectRaw('sum(quantity * unit_cost) v')->value('v'), 2));

        $this->actingAs($admin)->put("/purchases/{$p->id}", collect($invoice)->except('document')->all())->assertSessionHasErrors('rule');
        $this->actingAs($admin)->post("/purchases/{$p->id}/approve")->assertSessionHasErrors('rule');
        $this->assertSame(2, DB::table('stock_movements')->count(), 'approval posts exactly once');

        $this->actingAs($admin)->get("/purchases/{$p->id}")->assertOk()->assertSee('87.3000')->assertSee('1,115.50')->assertSee('اعتماد ذاتي');
        $this->actingAs($admin)->get('/purchases?f[]=self')->assertSee($p->purchase_no);
        $this->actingAs($admin)->get("/inventory/{$ply->id}")->assertSee($p->purchase_no);
        $this->actingAs($admin)->get('/reports/purchases')->assertOk()->assertSee('970.00');

        // Receipts are hidden from the studio and readable only with purchasing/expenses rights.
        $doc = StudioAsset::sole();
        $this->actingAs($admin)->get('/studio')->assertDontSee($doc->asset_no);
        $this->actingAs($admin)->get("/studio/{$doc->id}")->assertNotFound();
        $this->actingAs($this->userWith(['studio.view']))->get("/studio/{$doc->id}/file/full")->assertForbidden();
        $this->actingAs($this->userWith(['purchases.view']))->get("/studio/{$doc->id}/file/full")->assertOk();
    }

    public function test_manual_stock_receipt_is_no_longer_possible_and_permissions(): void
    {
        $admin = $this->admin();
        [$s, $ply] = $this->supplierAndMaterials($admin);
        $this->actingAs($admin)->post("/inventory/{$ply->id}/move", ['movement_type' => 'RECEIPT', 'quantity' => 1, 'unit_cost' => 1])->assertSessionHasErrors('movement_type');
        $this->actingAs($admin)->post("/inventory/{$ply->id}/move", ['movement_type' => 'ADJUST_IN', 'quantity' => 1, 'unit_cost' => 1])->assertSessionHasErrors('reason');

        $clerk = $this->userWith(['purchases.manage', 'purchases.view']);
        $this->actingAs($clerk)->post('/purchases', ['supplier_id' => $s->id, 'supplier_invoice_no' => 'A1', 'invoice_date' => now()->toDateString(),
            'discount_amount' => 0, 'vat_amount' => 0, 'lines' => [['material_id' => $ply->id, 'quantity' => 1, 'unit_price' => 10]]])->assertRedirect();
        $p = PurchaseInvoice::sole();
        $this->actingAs($clerk)->post("/purchases/{$p->id}/approve")->assertForbidden();
        $this->actingAs($admin)->post("/purchases/{$p->id}/approve")->assertSessionHasNoErrors();
        $this->assertFalse($p->fresh()->selfApproved());
        $this->actingAs($this->userWith(['clients.view']))->get('/purchases')->assertForbidden();

        // Suppliers have their own tile on the home screen, only for purchasing users.
        $this->actingAs($clerk)->get('/')->assertSee(route('suppliers.index'), false);
        $this->actingAs($clerk)->get("/suppliers/{$s->id}")->assertSee('class="tb-menu"', false)->assertSee('كل الموردين');
        $this->actingAs($this->userWith(['clients.view']))->get('/')->assertDontSee(route('suppliers.index'), false);
    }

    public function test_expenses_capture_approval_and_project_cost(): void
    {
        $admin = $this->admin();
        $q = $this->draftQuotation($admin);
        $this->actingAs($admin)->post("/quotations/{$q['id']}/send");
        $this->actingAs($admin)->post("/quotations/{$q['id']}/approve");
        $this->actingAs($admin)->post('/projects', ['quotation_id' => $q['id'], 'title' => 'TEST kitchen', 'start_date' => now()->toDateString()]);
        $projectId = (int) DB::table('projects')->value('id');

        $this->actingAs($admin)->post('/expense-categories', ['name' => 'TEST transport'])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post('/expense-categories', ['name' => 'TEST rent', 'is_overhead' => 1])->assertSessionHasNoErrors();
        $transport = ExpenseCategory::where('name', 'TEST transport')->sole();
        $rent = ExpenseCategory::where('name', 'TEST rent')->sole();

        $base = ['expense_date' => now()->toDateString(), 'category_id' => $transport->id, 'description' => 'TEST delivery', 'amount' => 250, 'vat_amount' => 37.5, 'payment_method' => 'CASH'];
        $this->actingAs($admin)->post('/expenses', $base)->assertSessionHasErrors('supplier_id'); // supplier or payee
        $this->actingAs($admin)->post('/expenses', ['payee' => 'TEST truck', 'payment_method' => 'PETTY_CASH'] + $base)->assertSessionHasErrors('paid_by_employee_id');
        $this->actingAs($admin)->post('/expenses', ['payee' => 'TEST truck', 'project_id' => $projectId] + $base)->assertRedirect();
        $this->actingAs($admin)->post('/expenses', ['payee' => 'TEST landlord', 'category_id' => $rent->id, 'amount' => 3000, 'vat_amount' => 0, 'description' => 'TEST rent'] + $base)->assertRedirect();

        $cost = fn () => (float) DB::table('v_project_actual_cost')->where('project_id', $projectId)->value('direct_expense_cost');
        $this->assertSame(0.0, $cost(), 'a draft is not a cost');
        $x = Expense::where('project_id', $projectId)->sole();
        $this->actingAs($admin)->post("/expenses/{$x->id}/approve")->assertSessionHasNoErrors();
        $this->actingAs($admin)->post('/expenses/'.Expense::where('amount', 3000)->value('id').'/approve');
        $this->assertSame(250.0, $cost());
        $this->actingAs($admin)->put("/expenses/{$x->id}", ['payee' => 'x'] + $base)->assertSessionHasErrors('rule');

        $this->actingAs($admin)->get("/projects/{$projectId}")->assertOk()->assertSee('250.00');
        $this->actingAs($admin)->get('/expenses?g=category')->assertOk()->assertSee('TEST transport');
        $this->actingAs($admin)->get('/reports/expenses?rows=kind')->assertOk()->assertSee('3,000.00')->assertSee('250.00');
        $this->actingAs($admin)->get('/reports/consumption')->assertOk();
        $this->actingAs($this->userWith(['expenses.manage', 'expenses.view']))->post("/expenses/{$x->id}/approve")->assertForbidden();
    }
}
