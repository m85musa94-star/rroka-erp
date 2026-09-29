<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\Daftra\DaftraClient;
use App\Services\Daftra\DaftraProbe;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DaftraSettingsController extends Controller
{
    public function index(): View
    {
        $client = DaftraClient::fromConfig();

        return view('settings.daftra', [
            'configured' => $client->isConfigured(),
            'subdomain' => $client->subdomain(),
            'estimateVerified' => (bool) config('daftra.estimate_mapping_verified'),
            'results' => null,
        ]);
    }

    /** Runs the read-only probe; nothing is stored. */
    public function probe(Request $request): View
    {
        $client = DaftraClient::fromConfig();
        abort_unless($client->isConfigured(), 422, __('بيانات الربط مع دفترة غير مُدخلة.'));
        $results = (new DaftraProbe($client))->run();

        return view('settings.daftra', [
            'configured' => true,
            'subdomain' => $client->subdomain(),
            'estimateVerified' => (bool) config('daftra.estimate_mapping_verified'),
            'results' => $results,
            'text' => DaftraProbe::asText($results, $client->subdomain()),
        ]);
    }
}
