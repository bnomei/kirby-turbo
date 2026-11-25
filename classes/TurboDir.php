<?php

/**
 * Copyright (c) 2025 Bruno Meilick
 * All rights reserved.
 *
 * This file is part of Kirby Turbo and is proprietary software.
 * Unauthorized copying, modification, or distribution is prohibited.
 */

namespace Bnomei;

use Kirby\Filesystem\Dir;
use Kirby\Toolkit\A;
use Kirby\Toolkit\Str;

class TurboDir extends Dir
{
    protected static function resolveDirKey(string $dir): ?string
    {
        $dirs = Turbo::singleton()->dirs();

        if (array_key_exists($dir, $dirs)) {
            return $dir;
        }

        $realpath = realpath($dir);

        return $realpath !== false && array_key_exists($realpath, $dirs) ? $realpath : null;
    }

    protected static function resolveFilePath(string $path): ?string
    {
        $files = Turbo::singleton()->files();
        $key = '#'.hash('xxh3', $path);

        if (array_key_exists($key, $files)) {
            return $path;
        }

        $realpath = realpath($path);

        return $realpath !== false && array_key_exists('#'.hash('xxh3', $realpath), $files) ? $realpath : null;
    }

    public static function is_dir(string $dir): bool
    {
        return static::resolveDirKey($dir) !== null;
    }

    public static function is_file(string $path): bool
    {
        return static::resolveFilePath($path) !== null;
    }

    public static function scandir(string $dir): array
    {
        // SLOW: using the file system in PHP
        // $scandir = scandir($dir);

        // using TURBO from batch-loaded via command
        $dir = static::resolveDirKey($dir) ?? $dir;

        return A::get(Turbo::singleton()->dirs(), $dir, []);
    }

    /*
     * @copyright Bastian Allgeier
     */
    public static function read(string $dir, ?array $ignore = null, bool $absolute = false): array
    {
        $dir = static::resolveDirKey($dir) ?? $dir;

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

    /*
     * @copyright Bastian Allgeier
     */
    public static function modified(
        string $dir,
        ?string $format = null,
        ?string $handler = null
    ): int|string|false {
        $dir = static::resolveDirKey($dir) ?? $dir;

        if (Turbo::singleton()->options['inventory.modified'] !== true) {
            return Dir::modified($dir, $format, $handler);
        }

        $modified = filemtime($dir);
        $items = static::read($dir);

        foreach ($items as $item) {
            // NOTE: changed to use TURBO by @bnomei
            $newModified = Turbo::singleton()->modified($dir.'/'.$item);

            if ($newModified && $newModified > $modified) {
                $modified = $newModified;
            }
        }

        // NOTE: changed to use TURBO by @bnomei
        $date = $modified ? Str::date($modified, $format, $handler) : false;

        return $date !== false ? $date : 0;
    }
}
