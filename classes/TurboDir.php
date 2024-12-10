<?php

namespace Bnomei;

use Kirby\Filesystem\Dir;

class TurboDir extends Dir
{
    /*
     * @copyright Bastian Allgeier
     */
    public static function read(string $dir, ?array $ignore = null, bool $absolute = false): array
    {
        // NOTE: changed to use TURBO by @bnomei
        if (Turbo::singleton()->is_dir($dir) === false) {
            return [];
        }

        // create the ignore pattern
        $ignore ??= static::$ignore;
        $ignore = [...$ignore, '.', '..'];

        // scan for all files and dirs
        // NOTE: changed to use TURBO by @bnomei
        $result = array_values((array) array_diff(Turbo::singleton()->scandir($dir), $ignore));

        // add absolute paths
        if ($absolute === true) {
            $result = array_map(fn ($item) => $dir.'/'.$item, $result);
        }

        return $result;
    }
}
