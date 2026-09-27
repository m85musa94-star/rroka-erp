<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\DaftraSyncLog;
use App\Services\Daftra\DaftraSyncService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ClientController extends Controller
{
    public function index(Request $request): View
    {
        $query = Client::query()->orderByDesc('id');
        if ($search = $request->query('q')) {
            $query->where(fn ($q) => $q->where('business_name', 'ilike', "%{$search}%")
                ->orWhere('phone', 'ilike', "%{$search}%")
                ->orWhere('client_no', 'ilike', "%{$search}%"));
        }

        return view('clients.index', ['clients' => $query->paginate(30)->withQueryString()]);
    }

    public function create(): View
    {
        return view('clients.form', ['client' => new Client]);
    }

    public function store(Request $request): RedirectResponse
    {
        $client = new Client($this->validated($request));
        $client->created_by = $request->user()->id;
        $client->save();

        return redirect()->route('clients.show', $client)->with('ok', 'تم حفظ العميل.');
    }

    public function show(Client $client): View
    {
        return view('clients.show', [
            'client' => $client,
            'quotations' => $client->quotations()->orderByDesc('id')->get(),
            'syncLog' => DaftraSyncLog::where(['entity_type' => 'CLIENT', 'entity_id' => $client->id])->orderByDesc('id')->get(),
        ]);
    }

    public function edit(Client $client): View
    {
        return view('clients.form', ['client' => $client]);
    }

    public function update(Request $request, Client $client): RedirectResponse
    {
        $client->update($this->validated($request));

        return redirect()->route('clients.show', $client)->with('ok', 'تم تحديث بيانات العميل.');
    }

    public function sync(Request $request, Client $client, DaftraSyncService $sync): RedirectResponse
    {
        $sync->syncClient($client, $request->user());

        return back()->with('ok', 'تم إنشاء العميل في دفترة.');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'business_name' => ['required', 'string', 'max:255'],
            'client_type' => ['required', Rule::in(['INDIVIDUAL', 'COMPANY'])],
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
