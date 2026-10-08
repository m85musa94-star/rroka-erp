<?php

namespace App\Reports;

/**
 * A pivot report declared as data (see ModuleReports): same engine and screen as the
 * hand-written reports, for the modules whose analysis is a plain grouping of one table.
 */
class DefinedReport extends Report
{
    public function __construct(private array $d) {}

    public function key(): string
    {
        return $this->d['key'];
    }

    public function title(): string
    {
        return $this->d['title'];
    }

    public function description(): string
    {
        return $this->d['description'];
    }

    public function permissions(): array
    {
        return $this->d['permissions'];
    }

    protected function base(): string
    {
        return $this->d['base'];
    }

    public function dimensions(): array
    {
        return $this->d['dimensions'];
    }

    public function measures(): array
    {
        return $this->d['measures'];
    }

    public function dateColumn(): string
    {
        return $this->d['date'][0];
    }

    public function dateLabel(): string
    {
        return $this->d['date'][1];
    }

    public function filters(): array
    {
        return $this->d['filters'] ?? [];
    }

    public function note(array $activeFilters, ?string $from = null, ?string $to = null): ?string
    {
        return isset($this->d['note']) ? ($this->d['note'])() : null;
    }
}
