<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Quotation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class ProjectController extends Controller
{
    public function index(): View
    {
        return view('projects.index', [
            'projects' => Project::with('client:id,business_name')->orderByDesc('id')->paginate(30),
        ]);
    }

    public function create(Request $request): View
    {
        return view('projects.create', [
            // Approved quotations that do not have a project yet.
            'quotations' => Quotation::with('client:id,business_name')
                ->where('status', 'APPROVED')->doesntHave('project')->orderByDesc('id')->get(),
            'selected' => $request->query('quotation_id'),
            'users' => User::where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'quotation_id' => ['required', 'integer', 'exists:quotations,id'],
            'title' => ['required', 'string', 'max:255'],
            'start_date' => ['required', 'date'],
            'target_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'manager_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $project = new Project($data);
        $project->created_by = $request->user()->id;
        $project->save();

        return redirect()->route('projects.show', $project)->with('ok', 'تم إنشاء المشروع.');
    }

    public function show(Request $request, Project $project): View
    {
        $cost = null;
        if ($request->user()->hasPermission('costing.view')) {
            $cost = $project->actualCost();
            $inner = trim((string) $cost->costing_gaps, '{}');
            $cost->gaps = $inner === '' ? [] : explode(',', $inner);
        }

        return view('projects.show', [
            'project' => $project->load('client', 'quotation'),
            'manager' => $project->manager_id ? DB::table('users')->where('id', $project->manager_id)->value('name') : null,
            'cost' => $cost,
        ]);
    }
}
