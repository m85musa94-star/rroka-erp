<?php

namespace App\Support;

/**
 * The release shown at the foot of every page, so the owner can tell which version is deployed.
 * A class constant (not config): a configuration cache built on the host cannot hide it.
 * Raised with every change pushed (date.sequence).
 */
class Release
{
    public const VERSION = '2026-10-08.15';
}
