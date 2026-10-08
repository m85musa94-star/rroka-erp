<?php

namespace App\Support;

/**
 * "Back to the report" when drilling down from a report to an account or entry: the report's
 * own URL (with its dates and options) travels as ?back=… through every drill-down link.
 * Only a local path is accepted, so the parameter cannot send anyone to another site.
 */
class BackLink
{
    public static function current(): ?string
    {
        $b = request()->query('back');

        return is_string($b) && str_starts_with($b, '/') && ! str_starts_with($b, '//') && ! str_contains($b, '\\') && strlen($b) < 2000 ? $b : null;
    }

    /** Parameters to add to a drill-down link: keep the original report as the way back. */
    public static function carry(?string $fallback = null): array
    {
        $b = self::current() ?? $fallback;

        return $b ? ['back' => $b] : [];
    }
}
