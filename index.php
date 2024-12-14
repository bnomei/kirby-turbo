<?php

@include_once __DIR__.'/vendor/autoload.php';

if (! function_exists('turbo')) {
    function turbo(): ?\Bnomei\Turbo
    {
        return \Bnomei\Turbo::singleton();
    }
}

Kirby::plugin('bnomei/turbo', [
    'options' => [
        'cache' => true,
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
                    $cmd .= '-osx';
                } elseif (stripos($os, 'Linux') !== false) { // musl compiled
                    $cmd .= '-linux';
                }

                return $cmd;
            },
            'modified' => true, // gather modified timestamp or default to PHP
            'content' => true, // if exec can do it fetch content
        ],

        'cache-driver' => [
            'preload-all' => true,
            'validate-value-as-json' => true, // check causes just a minor performance impact on write
            'json-encode-flags' => JSON_THROW_ON_ERROR, // | JSON_INVALID_UTF8_IGNORE,
        ],

        'patch-files-class' => false, // files are not supported by default, this is experimental and your opcache might not pick up the change unless you clear it manually. updating kirby will also overwrite the change but the plugin will try to recover from that.
    ],
    'cacheTypes' => [
        'turbo-redis' => \Bnomei\TurboRedisCache::class,
    ],
    'commands' => [
        'turbo:flush' => [
            'description' => 'Flush Turbo Cache',
            'args' => [
                'name' => [
                    'prefix' => 'n',
                    'longPrefix' => 'name',
                    'description' => 'Name of the cache to flush.',
                    'defaultValue' => 'cmd', // defaults to the cache of the cmd to get paths and timestamps, most useful after deployments that altered content files
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
        'system.loadPlugins:after' => function () {
            if (option('bnomei.turbo.patch-files-class')) {
                \Bnomei\TurboFile::patchFilesClass();
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
