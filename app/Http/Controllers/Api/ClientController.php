<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Services\Daftra\DaftraSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ClientController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Client::query()->orderByDesc('id');
        if ($search = $request->query('search')) {
            $query->where(fn ($q) => $q->where('business_name', 'ilike', "%{$search}%")
                ->orWhere('phone', 'ilike', "%{$search}%")
                ->orWhere('client_no', 'ilike', "%{$search}%"));
        }

        return response()->json($query->paginate(50));
    }

    public function store(Request $request): JsonResponse
    {
        $client = new Client($this->validated($request));
        $client->created_by = $request->user()->id;
        $client->save();

        return response()->json($client->refresh(), 201);
    }

    public function show(Client $client): JsonResponse
    {
        return response()->json($client);
    }

    public function update(Request $request, Client $client): JsonResponse
    {
        $client->update($this->validated($request, partial: true));

        return response()->json($client->refresh());
    }

    public function syncToDaftra(Request $request, Client $client, DaftraSyncService $sync): JsonResponse
    {
        return response()->json($sync->syncClient($client, $request->user())->refresh());
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'business_name' => [$required, 'string', 'max:255'],
            'client_type' => ['sometimes', Rule::in(['INDIVIDUAL', 'COMPANY'])],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email'],
            'vat_number' => ['nullable', 'regex:/^[0-9]{15}$/'],
            'commercial_reg_no' => ['nullable', 'string', 'max:30'],
            'city' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
        ]);
    }
}
