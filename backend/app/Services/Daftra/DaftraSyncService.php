<?php

namespace App\Services\Daftra;

use App\Models\Client;
use App\Models\DaftraSyncLog;
use App\Models\Quotation;
use App\Models\User;
use App\Support\AuditContext;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Pushes operational records to Daftra. Every attempt is written to
 * daftra_sync_log BEFORE the call (PENDING) and closed afterwards, so a crash
 * mid-call leaves a visible PENDING row instead of a silent double-post risk.
 */
class DaftraSyncService
{
    public function __construct(private readonly DaftraClient $daftra) {}

    public function syncClient(Client $client, User $user): Client
    {
        if ($client->daftra_client_id) {
            throw new HttpException(409, 'DAFTRA_ALREADY_SYNCED');
        }

        // Only business_name is a verified field of the Daftra Client object.
        $payload = ['business_name' => $client->business_name];

        $result = $this->run('CLIENT', $client->id, 'POST /clients', $payload, $user,
            fn () => $this->daftra->createClient($payload));

        DB::transaction(function () use ($client, $result, $user) {
            AuditContext::apply($user->id);
            $client->forceFill([
                'daftra_client_id' => $result['id'],
                'daftra_client_number' => $result['client_number'] ?? null,
            ])->save();
        });

        return $client;
    }

    public function syncQuotation(Quotation $quotation, User $user): Quotation
    {
        if (! config('daftra.estimate_mapping_verified')) {
            throw new HttpException(409, 'DAFTRA_ESTIMATE_MAPPING_NOT_VERIFIED');
        }
        if ($quotation->daftra_estimate_id) {
            throw new HttpException(409, 'DAFTRA_ALREADY_SYNCED');
        }
        if (! in_array($quotation->status, ['SENT', 'APPROVED'], true)) {
            throw new HttpException(409, 'DAFTRA_QUOTATION_NOT_ISSUED');
        }
        $clientId = $quotation->client->daftra_client_id;
        if (! $clientId) {
            throw new HttpException(409, 'DAFTRA_CLIENT_NOT_SYNCED');
        }

        [$estimate, $items] = $this->estimatePayload($quotation, $clientId);

        $result = $this->run('QUOTATION', $quotation->id, 'POST /estimates',
            ['Estimate' => $estimate, 'InvoiceItem' => $items], $user,
            fn () => $this->daftra->createEstimate($estimate, $items));

        DB::transaction(function () use ($quotation, $result, $user) {
            AuditContext::apply($user->id);
            $quotation->forceFill(['daftra_estimate_id' => $result['id']])->save();
        });

        return $quotation;
    }

    /**
     * UNVERIFIED field names — confirm against docs.daftara.dev before enabling
     * DAFTRA_ESTIMATE_MAPPING_VERIFIED. Prices are sent before VAT; Daftra applies tax.
     */
    public function estimatePayload(Quotation $quotation, int $daftraClientId): array
    {
        $estimate = [
            'client_id' => $daftraClientId,
            'date' => $quotation->issue_date->toDateString(),
            'discount_amount' => (float) $quotation->discount_amount,
            'notes' => $quotation->quotation_no,
        ];
        $items = $quotation->lines->map(fn ($line) => [
            'item' => $line->description,
            'unit_price' => (float) $line->unit_price,
            'quantity' => (float) $line->quantity,
        ])->all();

        return [$estimate, $items];
    }

    private function run(string $type, int $entityId, string $operation, array $payload, User $user, callable $call): array
    {
        $log = DB::transaction(function () use ($type, $entityId, $operation, $payload, $user) {
            // Serialize attempts per entity; refuse while another attempt is unresolved.
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ["daftra:$type:$entityId"]);
            if (DaftraSyncLog::where(['entity_type' => $type, 'entity_id' => $entityId, 'status' => 'PENDING'])->exists()) {
                throw new HttpException(409, 'DAFTRA_SYNC_IN_PROGRESS');
            }

            return DaftraSyncLog::create([
                'entity_type' => $type,
                'entity_id' => $entityId,
                'operation' => $operation,
                'attempt' => DaftraSyncLog::where(['entity_type' => $type, 'entity_id' => $entityId])->count() + 1,
                'request_payload' => $payload,
                'requested_by' => $user->id,
            ]);
        });

        try {
            $result = $call();
        } catch (DaftraException $e) {
            $log->update([
                'status' => 'FAILED',
                'http_status' => $e->httpStatus,
                'response_body' => $e->responseBody,
                'error_message' => $e->getMessage(),
                'finished_at' => now(),
            ]);
            throw new HttpException(502, 'DAFTRA_SYNC_FAILED: '.$e->getMessage());
        }

        $log->update([
            'status' => 'SUCCESS',
            'http_status' => $result['status'],
            'response_body' => $result,
            'daftra_id' => $result['id'],
            'finished_at' => now(),
        ]);

        return $result;
    }
}
