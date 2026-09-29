<?php

namespace App\Services\Daftra;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Thin transport for the Daftra API. Write endpoints are limited to those
 * verified against docs.daftara.dev; get() is read-only and serves DaftraProbe.
 */
class DaftraClient
{
    public function __construct(
        private readonly ?string $subdomain,
        private readonly ?string $apiKey,
        private readonly ?string $token,
        private readonly int $timeout = 20,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            config('daftra.subdomain'),
            config('daftra.api_key'),
            config('daftra.token'),
            config('daftra.timeout'),
        );
    }

    public function isConfigured(): bool
    {
        return filled($this->subdomain) && filled($this->apiKey) && filled($this->token);
    }

    /** POST /clients — body {"Client": {...}}, business_name required. Returns the Daftra response body. */
    public function createClient(array $client): array
    {
        return $this->post('/clients', ['Client' => $client]);
    }

    /** POST /estimates — body {"Estimate": {...}, "InvoiceItem": [...]}. */
    public function createEstimate(array $estimate, array $items): array
    {
        return $this->post('/estimates', ['Estimate' => $estimate, 'InvoiceItem' => $items]);
    }

    /**
     * Read-only GET used by DaftraProbe. Returns the raw response so the caller
     * can report the status; paths are unverified until the probe confirms them.
     */
    public function get(string $path, array $query = []): Response
    {
        if (! $this->isConfigured()) {
            throw new DaftraException('Daftra credentials are not configured (DAFTRA_SUBDOMAIN / DAFTRA_API_KEY / DAFTRA_TOKEN).');
        }

        return $this->request()->get($path, $query);
    }

    public function subdomain(): ?string
    {
        return $this->subdomain;
    }

    private function post(string $path, array $body): array
    {
        if (! $this->isConfigured()) {
            throw new DaftraException('Daftra credentials are not configured (DAFTRA_SUBDOMAIN / DAFTRA_API_KEY / DAFTRA_TOKEN).');
        }

        try {
            $response = $this->request()->post($path, $body);
        } catch (ConnectionException $e) {
            throw new DaftraException('Could not reach Daftra: '.$e->getMessage());
        }

        $json = $response->json();
        $json = is_array($json) ? $json : ['raw' => $response->body()];

        if (! $response->successful()) {
            throw new DaftraException("Daftra returned HTTP {$response->status()}", $response->status(), $json);
        }
        if (! isset($json['id'])) {
            throw new DaftraException('Daftra response has no id', $response->status(), $json);
        }

        return ['status' => $response->status()] + $json;
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl("https://{$this->subdomain}.daftra.com/api2")
            ->withHeaders([
                'apikey' => $this->apiKey,
                'Authorization' => 'Bearer '.$this->token,
            ])
            ->acceptJson()
            ->asJson()
            ->timeout($this->timeout);
    }
}
