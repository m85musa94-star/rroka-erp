<?php

namespace App\Imports;

/**
 * One column of an import template: the form field it fills, its label (Arabic,
 * translated in the template), its type, and what it accepts. Field names starting
 * with "_" are helpers (looked up, then used by the definition) and never sent on.
 */
final class Col
{
    public static function text(string $field, string $label, bool $required = false, ?string $help = null, ?string $example = null): array
    {
        return self::make($field, $label, 'text', $required, $help, $example);
    }

    public static function number(string $field, string $label, bool $required = false, ?string $help = null, ?string $example = null): array
    {
        return self::make($field, $label, 'number', $required, $help, $example ?? '1500');
    }

    public static function date(string $field, string $label, bool $required = false, ?string $help = null): array
    {
        return self::make($field, $label, 'date', $required, $help, '2026-03-15');
    }

    public static function datetime(string $field, string $label, bool $required = false, ?string $help = null): array
    {
        return self::make($field, $label, 'datetime', $required, $help, '2026-03-15 08:00');
    }

    public static function bool(string $field, string $label, ?string $help = null): array
    {
        return self::make($field, $label, 'bool', false, $help, __('نعم'));
    }

    /** @param array<string, string> $options value => label (the label is a translation key or Arabic text) */
    public static function enum(string $field, string $label, array $options, bool $required = false, ?string $help = null): array
    {
        return ['options' => $options] + self::make($field, $label, 'enum', $required, $help, null);
    }

    /** A value matched against a list in the system (by number, code or name — see Lookups). */
    public static function lookup(string $field, string $label, string $lookup, bool $required = false, ?string $help = null): array
    {
        return ['lookup' => $lookup] + self::make($field, $label, 'lookup', $required, $help, null);
    }

    private static function make(string $field, string $label, string $type, bool $required, ?string $help, ?string $example): array
    {
        return compact('field', 'label', 'type', 'required', 'help', 'example');
    }
}
