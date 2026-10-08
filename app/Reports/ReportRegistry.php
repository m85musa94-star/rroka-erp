<?php

namespace App\Reports;

use App\Models\User;

class ReportRegistry
{
    /** @return array<string, Report> */
    public static function all(): array
    {
        $reports = [new QuotationsReport, new ProjectsReport, new ProfitabilityReport, new PurchasesReport, new ExpensesReport, new MaterialConsumptionReport,
            ...ModuleReports::all()];

        return array_combine(array_map(fn ($r) => $r->key(), $reports), $reports);
    }

    /** Report sections on the reports page, in reading order (financial statements open the first one). */
    public static function sections(): array
    {
        return [
            'finance' => __('المالية والمحاسبة'),
            'sales' => __('المبيعات والعملاء والمشاريع'),
            'production' => __('التصنيع والجودة'),
            'inventory' => __('المخزون والخامات'),
            'purchasing' => __('المشتريات والمصروفات والخزينة'),
            'hr' => __('الموارد البشرية'),
        ];
    }

    public static function sectionOf(string $key): string
    {
        return [
            'ledger' => 'finance',
            'quotations' => 'sales', 'clients' => 'sales', 'projects' => 'sales', 'profitability' => 'sales',
            'production' => 'production', 'labor' => 'production', 'machines' => 'production', 'quality' => 'production',
            'stock' => 'inventory', 'movements' => 'inventory', 'consumption' => 'inventory',
            'purchases' => 'purchasing', 'expenses' => 'purchasing', 'treasury' => 'purchasing',
            'employees' => 'hr', 'attendance' => 'hr', 'leaves' => 'hr',
        ][$key] ?? 'sales';
    }

    /** App icon shown on each report card. */
    public static function iconOf(string $key): string
    {
        return [
            'ledger' => 'accounting', 'quotations' => 'quotations', 'clients' => 'clients', 'projects' => 'projects', 'profitability' => 'costing',
            'production' => 'production', 'labor' => 'attendance', 'machines' => 'production', 'quality' => 'quality',
            'stock' => 'inventory', 'movements' => 'inventory', 'consumption' => 'inventory',
            'purchases' => 'purchasing', 'expenses' => 'expenses', 'treasury' => 'treasury',
            'employees' => 'employees', 'attendance' => 'attendance', 'leaves' => 'timeoff',
        ][$key] ?? 'reports';
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
