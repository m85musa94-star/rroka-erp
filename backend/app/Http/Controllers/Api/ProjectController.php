<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProjectController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(
            Project::with('client:id,client_no,business_name')->orderByDesc('id')->paginate(50)
        );
    }

    /** The database refuses any quotation that is not APPROVED and copies client + contract value. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'quotation_id' => ['required', 'integer', 'exists:quotations,id'],
            'title' => ['required', 'string', 'max:255'],
            'start_date' => ['sometimes', 'date'],
            'target_date' => ['nullable', 'date'],
            'manager_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $project = new Project($data);
        $project->created_by = $request->user()->id;
        $project->save();

        return response()->json($project->refresh(), 201);
    }

    public function show(Project $project): JsonResponse
    {
        return response()->json($project->load('client:id,client_no,business_name', 'quotation:id,quotation_no'));
    }

    /**
     * Actual job cost. Components without real rates are null and listed in
     * costing_gaps; totals and profit stay null until every gap is closed.
     */
    public function costing(Project $project): JsonResponse
    {
        $cost = (array) $project->actualCost();
        $cost['costing_gaps'] = $this->pgArray($cost['costing_gaps']);
        $cost['is_complete'] = $cost['costing_gaps'] === [];

        return response()->json($cost);
    }

    private function pgArray(?string $value): array
    {
        $inner = trim((string) $value, '{}');

        return $inner === '' ? [] : explode(',', $inner);
    }
}
