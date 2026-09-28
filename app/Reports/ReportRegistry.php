<?php

namespace App\Reports;

use App\Models\User;

class ReportRegistry
{
    /** @return array<string, Report> */
    public static function all(): array
    {
        $reports = [new QuotationsReport, new ProjectsReport, new ProfitabilityReport];

        return array_combine(array_map(fn ($r) => $r->key(), $reports), $reports);
    }

    public static function allowed(Report $report, User $user): bool
    {
        foreach ($report->permissions() as $p) {
            if ($user->hasPermission($p)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, Report> */
    public static function forUser(User $user): array
    {
        return array_filter(self::all(), fn ($r) => self::allowed($r, $user));
    }
}
