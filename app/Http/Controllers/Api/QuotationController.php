<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Quotation;
use App\Services\Daftra\DaftraSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class QuotationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Quotation::with('client:id,client_no,business_name')->orderByDesc('id');
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        return response()->json($query->paginate(50));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $quotation = DB::transaction(function () use ($data, $request) {
            $quotation = new Quotation(collect($data)->except('lines')->all());
            $quotation->created_by = $request->user()->id;
            $quotation->save();
            $this->writeLines($quotation, $data['lines']);

            return $quotation;
        });

        return $this->show($quotation->refresh(), 201);
    }

    public function show(Quotation $quotation, int $status = 200): JsonResponse
    {
        $quotation->load('lines', 'client:id,client_no,business_name,daftra_client_id');

        return response()->json($quotation->toArray() + ['totals' => $quotation->totals()], $status);
    }

    /** Replace header and lines while DRAFT (the database refuses otherwise). */
    public function update(Request $request, Quotation $quotation): JsonResponse
    {
        $data = $this->validated($request);

        DB::transaction(function () use ($quotation, $data) {
            // Lines first: a customer's photo on an old line must not block changing the customer.
            $quotation->lines()->delete();
            $quotation->update(collect($data)->except('lines')->all());
            $this->writeLines($quotation, $data['lines']);
        });

        return $this->show($quotation->refresh());
    }

    public function send(Quotation $quotation): JsonResponse
    {
        $quotation->forceFill(['status' => 'SENT'])->save();

        return $this->show($quotation->refresh());
    }

    public function approve(Request $request, Quotation $quotation): JsonResponse
    {
        $quotation->forceFill([
            'status' => 'APPROVED',
            'approved_at' => now(),
            'approved_by' => $request->user()->id,
        ])->save();

        return $this->show($quotation->refresh());
    }

    public function reject(Quotation $quotation): JsonResponse
    {
        $quotation->forceFill(['status' => 'REJECTED'])->save();

        return $this->show($quotation->refresh());
    }

    public function syncToDaftra(Request $request, Quotation $quotation, DaftraSyncService $sync): JsonResponse
    {
        $sync->syncQuotation($quotation->load('lines', 'client'), $request->user());

        return $this->show($quotation->refresh());
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'survey_id' => ['nullable', 'integer', 'exists:site_surveys,id'],
            'issue_date' => ['sometimes', 'date'],
            'valid_until' => ['nullable', 'date'],
            'discount_amount' => ['sometimes', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.description' => ['required', 'string'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit' => ['sometimes', 'string', 'max:30'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
            'lines.*.studio_asset_id' => ['nullable', 'integer', 'exists:studio_assets,id'],
        ]);
    }

    private function writeLines(Quotation $quotation, array $lines): void
    {
        foreach (array_values($lines) as $i => $line) {
            $quotation->lines()->create($line + ['line_no' => $i + 1]);
        }
    }
}
