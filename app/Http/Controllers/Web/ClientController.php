<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\DaftraSyncLog;
use App\Services\Daftra\DaftraSyncService;
use App\Support\ActivityLog;
use App\Support\ListView;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ClientController extends Controller
{
    public function index(Request $request): View
    {
        $lv = new ListView($request,
            filters: [
                'company' => ['label' => 'منشآت', 'group' => 'type', 'apply' => fn ($q) => $q->where('client_type', 'COMPANY')],
                'individual' => ['label' => 'أفراد', 'group' => 'type', 'apply' => fn ($q) => $q->where('client_type', 'INDIVIDUAL')],
                'daftra' => ['label' => 'مرتبط بدفترة', 'group' => 'daftra', 'apply' => fn ($q) => $q->whereNotNull('daftra_client_id')],
                'no_daftra' => ['label' => 'غير مرتبط بدفترة', 'group' => 'daftra', 'apply' => fn ($q) => $q->whereNull('daftra_client_id')],
            ],
            groups: [
                'type' => ['label' => 'النوع', 'key' => fn ($c) => $c->client_type, 'title' => fn ($c) => __("rroka.client_type.$c->client_type")],
                'city' => ['label' => 'المدينة', 'key' => fn ($c) => $c->city ?? '', 'title' => fn ($c) => $c->city ?: 'بلا مدينة'],
            ],
            views: ['list', 'kanban'],
        );

        $query = $lv->applyFilters(Client::query()->orderByDesc('id'));
        if ($lv->q !== '') {
            $search = $lv->q;
            $query->where(fn ($q) => $q->where('business_name', 'ilike', "%{$search}%")
                ->orWhere('phone', 'ilike', "%{$search}%")
                ->orWhere('city', 'ilike', "%{$search}%")
                ->orWhere('client_no', 'ilike', "%{$search}%"));
        }

        return view('clients.index', [
            'lv' => $lv,
            'clients' => $lv->group ? null : $query->paginate(30)->withQueryString(),
            'groups' => $lv->group ? $lv->grouped($query->limit(1000)->get()) : null,
        ]);
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
            'activity' => ActivityLog::for(['clients' => [$client->id]]),
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
