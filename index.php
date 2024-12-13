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
        'cache' => [
            'anything' => true, // user facing cache
            'cmd' => true, // output of commands
            'storage' => true, // content storage mirror
        ],
        'expire' => 0, // 0 = forever, null to disable caching
        'compression' => false, // compress cached data? path strings compress very well. use beyond 2000 content pages and using file/apcu cache (not redis)

        // cmd to scan for files with timestamp and maybe content
        'cmd' => [
            'exec' => 'find', // null|find|turbo
            'modified' => true, // gather modified timestamp or default to PHP
            'content' => true, // if exec can do it fetch content
        ],

        'storage' => true, // add a IO mirror cache for the content storage

        'patch-files-class' => false, // files are not supported by default, this is experimental and your opcache might not pick up the change unless you clear it manually. updating kirby will also overwrite the change but the plugin will try to recover from that.
    ],
    'hooks' => [
        'system.loadPlugins:after' => function () {
            if (option('bnomei.turbo.patch-files-class')) {
                \Bnomei\Turbo::singleton()->patchFilesClass();
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
