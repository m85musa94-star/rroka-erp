<?php

namespace App\Console\Commands;

use App\Services\Daftra\DaftraClient;
use App\Services\Daftra\DaftraProbe;
use Illuminate\Console\Command;

class DaftraProbeCommand extends Command
{
    protected $signature = 'rroka:daftra-probe';

    protected $description = 'Read-only check of Daftra read endpoints; prints HTTP status and field structure (no values).';

    public function handle(): int
    {
        $client = DaftraClient::fromConfig();
        if (! $client->isConfigured()) {
            $this->error('Daftra is not configured: set DAFTRA_SUBDOMAIN, DAFTRA_API_KEY and DAFTRA_TOKEN.');

            return self::FAILURE;
        }

        $this->line(DaftraProbe::asText((new DaftraProbe($client))->run(), $client->subdomain()));

        return self::SUCCESS;
    }
}
