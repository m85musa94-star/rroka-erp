<?php

namespace App\Support;

use Throwable;

/**
 * Colour theme: the user's saved choice, else the session's (sign-in page), else
 * "system" (follow the device). Light and dark are stamped on <html data-theme>
 * so there is no flash; "system" leaves it off and CSS follows prefers-color-scheme.
 */
class Theme
{
    public const MODES = ['system', 'light', 'dark'];

    public static function current(): string
    {
        try {
            $mode = auth()->user()?->theme ?? session('theme');
        } catch (Throwable) {
            $mode = null;   // error pages may render without a session
        }

        return in_array($mode, self::MODES, true) ? $mode : 'system';
    }

    /** Attributes for <html>: data-theme only when forced. */
    public static function attr(): string
    {
        $mode = self::current();

        return $mode === 'system' ? '' : ' data-theme="'.$mode.'"';
    }
}
