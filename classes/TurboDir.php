<?php

/**
 * Copyright (c) 2025 Bruno Meilick
 * All rights reserved.
 *
 * This file is part of Kirby Turbo and is proprietary software.
 * Unauthorized copying, modification, or distribution is prohibited.
 */

namespace Bnomei;

use Kirby\Cms\App;
use Kirby\Cms\Page;
use Kirby\Filesystem\Dir;
use Kirby\Toolkit\A;
use Kirby\Toolkit\Str;

class TurboDir extends Dir
{
    public static function is_dir(string $dir): bool
    {
        return array_key_exists($dir, Turbo::singleton()->dirs());
    }

    public static function is_file(string $path): bool
    {
        return array_key_exists('#'.hash('xxh3', $path), Turbo::singleton()->files());
    }

    public static function scandir(string $dir): array
    {
        // SLOW: using the file system in PHP
        // $scandir = scandir($dir);

        // using TURBO from batch-loaded via command
        return A::get(Turbo::singleton()->dirs(), $dir, []);
    }

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

    /*
     * @copyright Bastian Allgeier
     */
    public static function modified(
        string $dir,
        ?string $format = null,
        ?string $handler = null
    ): int|string {
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

    /*
     * @copyright Bastian Allgeier
     */
    public static function inventory(
        string $dir,
        string $contentExtension = 'txt',
        ?array $contentIgnore = null,
        bool $multilang = false
    ): array {
        $inventory = [
            'children' => [],
            'files' => [],
            'template' => 'default',
        ];

        // NOTE: changed to use TURBO by @bnomei
        /*
        $dir = realpath($dir);

        if ($dir === false) {
            return $inventory;
        }
        */

        // a temporary store for all content files
        $content = [];

        // read and sort all items naturally to avoid sorting issues later
        $items = static::read($dir, $contentIgnore);
        natsort($items);

        // loop through all directory items and collect all relevant information
        foreach ($items as $item) {
            // ignore all items with a leading dot or underscore
            if (
                str_starts_with($item, '.') ||
                str_starts_with($item, '_')
            ) {
                continue;
            }

            $root = $dir.'/'.$item;

            // collect all directories as children
            // NOTE: changed to use TURBO by @bnomei
            if (static::is_dir($root) === true) {
                $inventory['children'][] = static::inventoryChild(
                    $item,
                    $root,
                    $contentExtension,
                    $multilang
                );

                continue;
            }

            $extension = pathinfo($item, PATHINFO_EXTENSION);

            // don't track files with these extensions
            if (in_array($extension, ['htm', 'html', 'php'], true) === true) {
                continue;
            }

            // collect all content files separately,
            // not as inventory entries
            if ($extension === $contentExtension) {
                $filename = pathinfo($item, PATHINFO_FILENAME);

                // remove the language codes from all content filenames
                if ($multilang === true) {
                    $filename = pathinfo($filename, PATHINFO_FILENAME);
                }

                $content[] = $filename;

                continue;
            }

            // collect all other files
            $inventory['files'][$item] = [
                'filename' => $item,
                'extension' => $extension,
                'root' => $root,
            ];
        }

        $content = array_unique($content);

        $inventory['template'] = static::inventoryTemplate(
            $content,
            $inventory['files']
        );

        return $inventory;
    }

    /*
     * @copyright Bastian Allgeier
     */
    protected static function inventoryChild(
        string $item,
        string $root,
        string $contentExtension = 'txt',
        bool $multilang = false
    ): array {
        // extract the slug and num of the directory
        if ($separator = strpos($item, static::$numSeparator)) {
            $num = (int) substr($item, 0, $separator);
            $slug = substr($item, $separator + 1);
        }

        // determine the model
        if (Page::$models !== []) {
            if ($multilang === true) {
                $code = App::instance()->defaultLanguage()?->code();
                $contentExtension = $code.'.'.$contentExtension;
            }

            // look if a content file can be found
            // for any of the available models
            foreach (Page::$models as $modelName => $modelClass) {
                // NOTE: changed to use TURBO by @bnomei
                if (static::is_file($root.'/'.$modelName.'.'.$contentExtension) === true) {
                    $model = $modelName;
                    break;
                }
            }
        }

        return [
            'dirname' => $item,
            'model' => $model ?? null,
            'num' => $num ?? null,
            'root' => $root,
            'slug' => $slug ?? $item,
        ];
    }
}
