<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * One delete action for every screen. A document goes only while it is a draft (the database
 * refuses otherwise: fn_delete_draft_only); master data goes only if nothing refers to it (the
 * foreign keys refuse otherwise, and it is archived instead). The audit log keeps the deleted row.
 */
class RecordDeleteController extends Controller
{
    /** type => [model, permission, list route, is a document (draft only)] */
    public const TYPES = [
        'clients' => [Models\Client::class, 'clients.manage', 'clients.index', false],
        'suppliers' => [Models\Supplier::class, 'purchases.manage', 'suppliers.index', false],
        'materials' => [Models\RawMaterial::class, 'inventory.move', 'materials.index', false],
        'expense-categories' => [Models\ExpenseCategory::class, 'expenses.manage', 'expense-categories.index', false],
        'accounts' => [Models\Account::class, 'accounting.manage', 'accounting.accounts.index', false],
        'employees' => [Models\Employee::class, 'hr.manage', 'employees.index', false],
        'quotations' => [Models\Quotation::class, 'quotations.manage', 'quotations.index', true],
        'expenses' => [Models\Expense::class, 'expenses.manage', 'expenses.index', true],
        'purchases' => [Models\PurchaseInvoice::class, 'purchases.manage', 'purchases.index', true],
        'transfers' => [Models\TreasuryTransfer::class, 'treasury.manage', 'treasury.transfers.index', true],
    ];

    /** Whether the delete button is offered for this record to this user. */
    public static function offered(string $type, Model $m): bool
    {
        [, $perm, , $document] = self::TYPES[$type];

        return auth()->user()?->hasPermission($perm)
            && (! $document || ($m->status === 'DRAFT' && empty($m->daftra_estimate_id)));
    }

    public function destroy(Request $request, string $type, int $id): RedirectResponse
    {
        [$class, $perm, $index] = self::TYPES[$type] ?? abort(404);
        abort_unless($request->user()->hasPermission($perm), 403);
        $m = $class::findOrFail($id);
        try {
            DB::transaction(fn () => $m->delete());   // a savepoint: a refused delete leaves the request usable
        } catch (QueryException $e) {
            if ($e->getCode() === '23503') {
                throw ValidationException::withMessages(['rule' => __('السجل مستخدم في سجلات أخرى فلا يُحذف؛ أوقفه (ألغِ «نشط») بدل الحذف.')]);
            }
            throw $e;
        }

        return redirect()->route($index)->with('ok', __('حُذف السجل.'));
    }
}
