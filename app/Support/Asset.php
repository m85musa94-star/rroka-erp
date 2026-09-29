<?php

namespace App\Support;

/**
 * Versioned URLs for static files. The version is the file's content hash, so
 * every deploy that changes a file changes its URL and no browser or edge cache
 * can keep serving the old copy (a stale app.css silently disables new styles).
 */
class Asset
{
    /** @var array<string, string> */
    private static array $versions = [];

    public static function url(string $path): string
    {
        $v = self::$versions[$path] ??= (($file = public_path($path)) && is_file($file) ? substr(md5_file($file), 0, 12) : '0');

        return asset($path).'?v='.$v;
    }
}
