<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\DemoData;
use Illuminate\Console\Command;

/** Loads (or with --purge removes) the labelled sample records used for hands-on viewing. */
class DemoDataCommand extends Command
{
    protected $signature = 'rroka:demo {--purge : Remove every demo record} {--user= : Acting user id (default: first system administrator)}';

    protected $description = 'Load or remove demo records labelled "تجريبي"';

    public function handle(DemoData $demo): int
    {
        $userId = (int) ($this->option('user') ?: User::whereHas('roles', fn ($r) => $r->where('code', 'system_admin'))->orderBy('id')->value('id'));
        if (! $userId) {
            $this->error('No system administrator found; pass --user=ID.');

            return self::FAILURE;
        }

        try {
            if ($this->option('purge')) {
                $this->info('Removed demo records: '.$demo->purge($userId));
            } else {
                $this->info('Loaded demo records: '.$demo->seed($userId));
            }
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
