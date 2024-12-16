<?php

use Kirby\Filesystem\F;

@include_once __DIR__.'/vendor/autoload.php';

if (! function_exists('turbo')) {
    function turbo(): ?\Bnomei\Turbo
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
            'cmd' => true,

            // one to store the content mirror
            'storage' => true,

            // and one for anything else, but it can make use of the improved `turbo-redis`
            // with features like serialization and abort from closures
            'tub' => true,
        ],
        'expire' => 0, // 0 = forever, null to disable caching
        'compression' => false, // compress cached data? path strings compress very well. use beyond 2000 content pages and using file/apcu cache (not redis)
        'storage' => true, // add a IO mirror cache for the content storage

        // cmd to scan for files with timestamp and maybe content
        'cmd' => [
            // null|find|turbo or closure
            'exec' => function (\Kirby\Cms\App $kirby) {
                $os = PHP_OS_FAMILY;
                $cmd = $kirby->root('index').'/vendor/bin/turbo';

                if (stripos($os, 'Darwin') !== false) { // macOS
                    $cmd .= '-darwin';
                } elseif (stripos($os, 'Linux') !== false) { // musl compiled
                    $cmd .= '-linux';
                }

                return $cmd;
            },
            'modified' => true, // gather modified timestamp or default to PHP
            'content' => true, // if exec can do it fetch content
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
                        $content = file_get_contents($modelFile);
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
                        F::read(__DIR__.'/stubs/model.php'
                        )));
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
            'description' => 'Populate the Turbo Cache with "env KIRBY_HOST=example.com kirby turbo:populate"',
            'args' => [],
            'command' => static function ($cli): void {
                $name = $cli->arg('name');
                $cli->out('🏎️ Populating the Turbo Cache...');
                $time = microtime(true);
                \Bnomei\Turbo::flush('cmd');
                \Bnomei\Turbo::singleton()->files(); // access data to populate
                $duration = round((microtime(true) - $time) * 1000);
                $cli->success("✅ Done. {$duration}ms ");

                if (function_exists('janitor')) {
                    janitor()->data($cli->arg('command'), [
                        'status' => 200,
                        'message' => "Turbo Cache [$name] flushed.",
                    ]);
                }
            },
        ],
        'turbo:flush' => [
            'description' => 'Flush a Turbo Cache [cmd/storage/tub]',
            'args' => [
                'name' => [
                    'prefix' => 'n',
                    'longPrefix' => 'name',
                    'description' => 'Name of the cache to flush [cmd/storage/tub].',
                    'required' => true,
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
        'site.*:after' => function ($event, $site) {
            if ($event->action() !== 'render') {
                \Bnomei\Turbo::flush('cmd');
            }
        },
        'page.*:after' => function ($event, $page) {
            if ($event->action() !== 'render') {
                \Bnomei\Turbo::flush('cmd');
            }
        },
        'file.*:after' => function ($event, $file) {
            if ($event->action() !== 'render') {
                \Bnomei\Turbo::flush('cmd');
            }
        },
        'user.*:after' => function ($event, $user) {
            if ($event->action() !== 'render') {
                \Bnomei\Turbo::flush('cmd');
            }
        },
    ],
]);
