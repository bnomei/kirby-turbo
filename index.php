<?php

use Kirby\Filesystem\F;

@include_once __DIR__.'/vendor/autoload.php';

if (! function_exists('turbo')) {
    function turbo(): \Bnomei\Turbo
    {
        return \Bnomei\Turbo::singleton();
    }
}

if (! function_exists('tub')) {
    function tub(): \Bnomei\TurboRedisCache
    {
        return \Bnomei\Turbo::singleton()->tub(); // @phpstan-ignore-line
    }
}

if (! function_exists('tubs')) {
    function tubs(array|string $key, Closure $closure): mixed
    {
        return \Bnomei\TurboStaticCache::getOrSet($key, $closure);
    }
}

Kirby::plugin('bnomei/turbo', [
    'options' => [
        'cache' => [
            // most stuff can use the default cache but the cmd output needs its own so it can be configured
            // to load from that with turbo-redis (preload ALL) without the rest of the caches to be on it
            'inventory' => ['active' => true, 'type' => 'file'],

            // one to store the content mirror
            'storage' => ['active' => true, 'type' => 'turbo-redis', 'database' => 0],

            // and one for anything else, but it can make use of the improved `turbo-redis`
            // with features like serialization and abort from closures
            'tub' => ['active' => true, 'type' => 'redis', 'database' => 0],
        ],
        'expire' => 0, // 0 = forever, null to disable caching

        'storage' => [
            // add a IO mirror cache for the content storage
            'read' => true,
            'write' => true,
            'compression' => false,
        ],

        // cmd to scan for files with timestamp and maybe content
        'inventory' => [
            // null|find|turbo or closure
            'indexer' => function (\Kirby\Cms\App $kirby) {
                $cmd = __DIR__.'/bin/turbo'; // musl compiled
                if (stripos(PHP_OS_FAMILY, 'Darwin') !== false) { // macOS
                    $cmd .= '-darwin';
                }

                return $cmd;
            },
            'enabled' => function (?string $url = null) {
                return \Bnomei\Turbo::isUrlKirbyInternal($url) === false;
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
        'preload-redis' => \Bnomei\PreloadRedisCache::class,
        'turbo-redis' => \Bnomei\TurboRedisCache::class,
        'turbo-uuid' => \Bnomei\TurboUuidCache::class,
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
                /** @var \Kirby\Cms\App $kirby */
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
                \Bnomei\Turbo::flush('inventory');
                \Bnomei\Turbo::singleton()->files(); // access files/dirs to populate
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
                \Bnomei\Turbo::flush($name);
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
            \Bnomei\TurboStopwatch::tick('route:before');
        },
        'route:after' => function (\Kirby\Http\Route $route, string $path, string $method, $result, bool $final) {
            if ($final) {
                \Bnomei\TurboStopwatch::tick('route:after');
            }
        },
        'site.*:after' => function ($event, $site) {
            if ($event->action() !== 'render') {
                \Bnomei\Turbo::flush('inventory');
            }
        },
        'page.*:after' => function ($event, $page) {
            if ($event->action() !== 'render') {
                \Bnomei\Turbo::flush('inventory');
            }
        },
        'file.*:after' => function ($event, $file) {
            if ($event->action() !== 'render') {
                \Bnomei\Turbo::flush('inventory');
            }
        },
        'user.*:after' => function ($event, $user) {
            if ($event->action() !== 'render') {
                \Bnomei\Turbo::flush('inventory');
            }
        },
        'page.render:before' => function (string $contentType, array $data, \Kirby\Cms\Page $page) {
            \Bnomei\TurboStopwatch::tick('page.render:before');

            return $data;
        },
        'page.render:after' => function (string $contentType, array $data, string $html, \Kirby\Cms\Page $page) {
            \Bnomei\TurboStopwatch::tick('page.render:after');

            return $html;
        },
    ],
]);
