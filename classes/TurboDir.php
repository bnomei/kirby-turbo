<?php

namespace Bnomei;

use Kirby\Filesystem\Dir;
use Kirby\Toolkit\A;

class TurboDir extends Dir
{
    /*
     * @copyright Bastian Allgeier
     */
    public static function read(string $dir, ?array $ignore = null, bool $absolute = false): array
    {
        // NOTE: changed to use TURBO by @bnomei
        if (static::is_dir($dir) === false) {
            return [];
        }

        // create the ignore pattern
        $ignore ??= static::$ignore;
        $ignore = [...$ignore, '.', '..'];

        // scan for all files and dirs
        // NOTE: changed to use TURBO by @bnomei
        $result = array_values((array) array_diff(static::scandir($dir), $ignore));

        // add absolute paths
        if ($absolute === true) {
            $result = array_map(fn ($item) => $dir.'/'.$item, $result);
        }

        return $result;
    }

    public static function is_dir(string $dir): bool
    {
        return array_key_exists($dir, Turbo::singleton()->dirs());
    }

    public static function scandir(string $dir): array
    {
        // SLOW: using the file system in PHP
        // $scandir = scandir($dir);

        // using TURBO from batch-loaded via command
        return A::get(Turbo::singleton()->dirs(), $dir, []);
    }
}
