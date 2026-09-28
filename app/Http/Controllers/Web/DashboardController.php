<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Quotation;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(): View
    {
        $costing = DB::table('v_project_actual_cost')->whereNotIn('status', ['CANCELLED'])->get();

        return view('dashboard', [
            'clients' => DB::table('clients')->count(),
            'awaiting' => Quotation::with('client:id,business_name')->where('status', 'SENT')->orderBy('issue_date')->get(),
            'drafts' => Quotation::where('status', 'DRAFT')->count(),
            'activeProjects' => DB::table('projects')->whereNotIn('status', ['COMPLETED', 'CANCELLED'])->count(),
            'openContractValue' => DB::table('projects')->whereNotIn('status', ['COMPLETED', 'CANCELLED'])->sum('contract_value'),
            'incompleteCosting' => $costing->filter(fn ($p) => $p->costing_gaps !== '{}')->count(),
            'ratesMissing' => [
                'workers' => DB::table('workers')->where('is_active', true)
                    ->whereNotExists(fn ($q) => $q->from('worker_rates')->whereColumn('worker_rates.worker_id', 'workers.id'))->count(),
                'machines' => DB::table('machines')->where('is_active', true)
                    ->whereNotExists(fn ($q) => $q->from('machine_rates')->whereColumn('machine_rates.machine_id', 'machines.id'))->count(),
                'overhead' => DB::table('overhead_rates')->doesntExist(),
            ],
        ]);
    }
}
