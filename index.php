<?php

/**
 * Copyright (c) 2025 Bruno Meilick
 * All rights reserved.
 *
 * This file is part of Kirby Turbo and is proprietary software.
 * Unauthorized copying, modification, or distribution is prohibited.
 */

use Bnomei\PreloadRedisCache;
use Bnomei\Turbo;
use Bnomei\TurboFileCache;
use Bnomei\TurboLicense;
use Bnomei\TurboRedisCache;
use Bnomei\TurboStaticCache;
use Bnomei\TurboStopwatch;
use Bnomei\TurboUuidCache;
use Kirby\Cms\App;
use Kirby\Cms\File;
use Kirby\Cms\Files;
use Kirby\Cms\Page;
use Kirby\Cms\Pages;
use Kirby\Content\Field;
use Kirby\Filesystem\F;
use Kirby\Http\Route;
use Kirby\Toolkit\A;

@include_once __DIR__.'/vendor/autoload.php';

if (! function_exists('turbo')) {
    function turbo(): Turbo
    {
        return Turbo::singleton();
    }
}

if (! function_exists('tub')) {
    function tub(): TurboRedisCache
    {
        return Turbo::singleton()->tub(); // @phpstan-ignore-line
    }
}

if (! function_exists('tubs')) {
    function tubs(array|string $key, Closure $closure): mixed
    {
        return TurboStaticCache::getOrSet($key, $closure);
    }
}

Kirby::plugin(
    name: 'bnomei/turbo',
    license: fn ($plugin) => new TurboLicense($plugin, TurboLicense::NAME),
    extends: [
        'options' => [
            'license' => '', // set your license from https://buy-turbo.bnomei.com code in the config `bnomei.turbo.license`
            'cache' => [
                // most stuff can use the default cache.
                // if you use preload-redis for the  cmd output it needs its own without the rest of the caches to be on it
                'inventory' => ['active' => true, 'type' => 'turbo-file'],
                // 'inventory' => ['active' => true, 'type' => 'file'],
                // 'inventory' => ['active' => true, 'type' => 'preload-redis', 'database' => 15],

                // one to store the content mirror
                'storage' => ['active' => true, 'type' => 'redis', 'database' => 0],

                // and one for anything else, but it can make use of the improved `turbo-redis`
                // with features like serialization and abort from closures
                'tub' => ['active' => true, 'type' => 'turbo-redis', 'database' => 0],
            ],
            'expire' => 0, // 0 = forever, null to disable caching

            'storage' => [
                // add a IO mirror cache for the content storage
                'read' => true,
                'write' => true,
                'compression' => false, // do not use with msg_pack or igbinary
            ],

            // cmd to scan for files with timestamp and maybe content
            'inventory' => [
                // null|find|turbo or closure
                'indexer' => function (App $kirby) {
                    $cmd = __DIR__.'/bin/turbo'; // musl compiled
                    if (stripos(PHP_OS_FAMILY, 'Darwin') !== false) { // macOS
                        $cmd .= '-darwin';
                    }

                    return $cmd;
                },
                'enabled' => function (?string $url = null) {
                    return Turbo::isUrlKirbyInternal($url) === false;
                }, // used to disable on demand (kirby internal requests), can be force set to true as well
                'modified' => true, // gather modified timestamp or default to PHP
                'content' => true, // if exec can do it fetch content
                'read' => true, // read from the cache in storage and inventory
                'compression' => false, // compress cached data? path strings compress very well. use beyond 2000 content pages and using file/apcu cache (not redis)
            ],

            'preload-redis' => [
                'validate-value-as-json' => true, // check causes just a minor performance impact on write
                'json-encode-flags' => JSON_THROW_ON_ERROR, // | JSON_INVALID_UTF8_IGNORE,
            ],
        ],
        'cacheTypes' => [
            'preload-redis' => PreloadRedisCache::class,
            'turbo-redis' => TurboRedisCache::class,
            'turbo-file' => TurboFileCache::class,
            'turbo-uuid' => TurboUuidCache::class,
        ],
        'commands' => [
            'turbo:models' => [
                'description' => 'Creates missing page models with Turbo Trait',
                'args' => [
                    'ignore' => [
                        'prefix' => 'i',
                        'longPrefix' => 'ignore',
                        'description' => 'Comma separated list of page blueprints to ignore.',
                        'defaultValue' => '',
                        'castTo' => 'string',
                    ],
                ],
                'command' => static function ($cli): void {
                    $cli->out('🏗️ Creating missing page models with Turbo Trait...');
                    $ignore = array_filter(explode(',', $cli->arg('ignore')));
                    $count = 0;
                    /** @var App $kirby */
                    $kirby = $cli->kirby();
                    $modelRoot = $kirby->roots()->models();
                    foreach ($kirby->blueprints('pages') as $blueprint) {
                        if (in_array($blueprint, $ignore)) {
                            $cli->out('🎠️ '.$blueprint);

                            continue;
                        }
                        $modelFile = $modelRoot.'/'.$blueprint.'.php';
                        if (file_exists($modelFile)) {
                            // read file and see if it has the string 'ModelWithTurbo' on cli echo found or not found
                            $content = file_get_contents($modelFile) ?: '';
                            if (! str_contains($content, 'ModelWithTurbo')) {
                                $cli->out('⚠️ '.$blueprint);
                            } else {
                                $cli->out('🆗 '.$blueprint);
                            }

                            continue;
                        }

                        F::write($modelFile, str_replace(
                            '__MODEL__',
                            ucfirst($blueprint),
                            F::read(__DIR__.'/stubs/model.php') // @phpstan-ignore-line
                        ));
                        $cli->out('✨ '.$blueprint);
                        $count++;
                    }

                    $cli->success('✅ Done.'.($count ? " Created {$count} missing page models with Turbo Trait." : ''));

                    if (function_exists('janitor')) {
                        janitor()->data($cli->arg('command'), [
                            'status' => 200,
                            'message' => "Created {$count} missing page models with Turbo Trait.",
                        ]);
                    }
                },
            ],
            'turbo:populate' => [
                'description' => 'Populate the Turbo Cache',
                'args' => [],
                'command' => static function ($cli): void {
                    $name = $cli->arg('name');
                    $cli->out('🏎️ Populating the Turbo Cache...');
                    $time = microtime(true);
                    Turbo::flush('inventory');
                    Turbo::singleton()->files(); // access files/dirs to populate
                    $duration = round((microtime(true) - $time) * 1000);
                    $cli->success("✅ Done. {$duration}ms ");

                    if (function_exists('janitor')) {
                        janitor()->data($cli->arg('command'), [
                            'status' => 200,
                            'message' => "Turbo Cache populated in {$duration}ms.",
                        ]);
                    }
                },
            ],
            'turbo:flush' => [
                'description' => 'Flush Turbo Cache(s)',
                'args' => [
                    'name' => [
                        'prefix' => 'n',
                        'longPrefix' => 'name',
                        'description' => 'Name of the cache to flush [*/all/inventory/storage/tub].',
                        'defaultValue' => 'all', // flush all
                        'castTo' => 'string',
                    ],
                ],
                'command' => static function ($cli): void {
                    $name = $cli->arg('name');
                    $cli->out("🚽 Flushing Turbo Cache [$name]...");
                    Turbo::flush($name);
                    $cli->success('✅ Done.');

                    if (function_exists('janitor')) {
                        janitor()->data($cli->arg('command'), [
                            'status' => 200,
                            'message' => "Turbo Cache [$name] flushed.",
                        ]);
                    }
                },
            ],
        ],
        'hooks' => [
            'route:before' => function (Kirby\Http\Route $route, string $path, string $method) {
                TurboStopwatch::tick('route:before');
            },
            'route:after' => function (Route $route, string $path, string $method, $result, bool $final) {
                if ($final) {
                    TurboStopwatch::tick('route:after');
                }
            },
            'site.*:before' => function ($event, $site) {
                if ($event->action() !== 'render') {
                    Turbo::flush('inventory');
                }
            },
            'page.*:before' => function ($event, $page) {
                if ($event->action() !== 'render') {
                    Turbo::flush('inventory');
                }
            },
            'file.*:before' => function ($event, $file) {
                if ($event->action() !== 'render') {
                    Turbo::flush('inventory');
                }
            },
            'user.*:before' => function ($event, $user) {
                if ($event->action() !== 'render') {
                    Turbo::flush('inventory');
                }
            },
            'page.render:before' => function (string $contentType, array $data, Page $page) {
                TurboStopwatch::tick('page.render:before');

                return $data;
            },
            'page.render:after' => function (string $contentType, array $data, string $html, Page $page) {
                TurboStopwatch::tick('page.render:after');

                return $html;
            },
        ],
        'siteMethods' => [
            'modifiedTurbo' => function (): int {
                return Turbo::singleton()->modified() ?? time();
            },
        ],
        'pagesMethods' => [
            'modified' => function (): ?int {
                /* @var Pages $collection */
                $collection = $this; // @phpstan-ignore-line
                $modified = null;
                foreach ($collection as $page) {
                    $m = $page->modified();
                    if (! $modified || ($m && $m > $modified)) {
                        $modified = $m;
                    }
                }

                return $modified;
            },
        ],
        'filesMethods' => [
            'modified' => function (): ?int {
                /* @var Pages $collection */
                $collection = $this; // @phpstan-ignore-line
                $modified = null;
                foreach ($collection as $file) {
                    $m = $file->modified();
                    if (! $modified || ($m && $m > $modified)) {
                        $modified = $m;
                    }
                }

                return $modified;
            },
        ],
        'fieldMethods' => [
            'toFileTurbo' => function (Field $field): ?File {
                return $field->toFilesTurbo()->first(); // @phpstan-ignore-line
            },
            'toFilesTurbo' => function (Field $field): Files {
                if (kirby()->option('cache.uuid.type') === 'turbo-uuid') {
                    $files = [];
                    $pages = [];
                    $cache = kirby()->cache('uuid');
                    foreach ($field->yaml() as $fileKey) { // @phpstan-ignore-line
                        $fileKey = 'file/'.substr($fileKey, 7, 2).'/'.substr($fileKey, 9);
                        if ($data =$cache->get($fileKey)) {
                            $parentUuid = is_array($data) ? A::get($data, 'parent') : null;
                            if (! $parentUuid) {
                                continue;
                            }
                            $parentKey = 'page/'.substr($parentUuid, 7, 2).'/'.substr($parentUuid, 9);
                            if ($parentId =$cache->get($parentKey)) {
                                $pages[$parentId] = A::get($pages, $parentId, kirby()->page($parentId));
                                $files[] = $pages[$parentId]?->file(A::get($data, 'filename'));
                            }
                        }
                    }

                    return new Files(array_filter($files));
                }

                // default
                return $field->toFiles(); // @phpstan-ignore-line
            },
            'toPageTurbo' => function (Field $field): ?Page {
                return $field->toPagesTurbo()->first(); // @phpstan-ignore-line
            },
            'toPagesTurbo' => function (Field $field): Pages {
                if (kirby()->option('cache.uuid.type') === 'turbo-uuid') {
                    $ids = [];
                    $cache = kirby()->cache('uuid');
                    foreach ($field->yaml() as $uuid) {  // @phpstan-ignore-line
                        $key = 'page/'.substr($uuid, 7, 2).'/'.substr($uuid, 9);
                        $ids[] = $cache->get($key);
                    }

                    return new Pages(array_filter($ids));
                }

                // default
                return $field->toPages(); // @phpstan-ignore-line
            },
        ],
    ]);
