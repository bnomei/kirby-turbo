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

        'patch-files-class' => false, // files are not supported by default, this is experimental and your opcache might not pick up the change unless you clear it manually. updating kirby will also overwrite the change but the plugin will try to recover from that.
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
