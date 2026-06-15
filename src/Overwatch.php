<?php

namespace ModernMcguire\Overwatch;

use Composer\InstalledVersions;

class Overwatch
{
    public const PACKAGE = 'modernmcguire/hq_overwatch';

    /**
     * Fallback reported when Composer cannot resolve an installed version
     * (e.g. running the package's own test suite as the root project).
     */
    public const VERSION = 'dev';

    /**
     * The installed package version, read from Composer's runtime metadata so
     * the status endpoint reports the build HQ actually has — not a hardcoded
     * constant that drifts out of date.
     */
    public static function version(): string
    {
        if (InstalledVersions::isInstalled(self::PACKAGE)) {
            return InstalledVersions::getPrettyVersion(self::PACKAGE) ?? self::VERSION;
        }

        return self::VERSION;
    }
}
