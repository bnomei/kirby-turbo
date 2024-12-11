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
            'cmd' => true,
            'model' => true,
        ],
        'expire' => 0, // 0 = forever, null to disable caching
        'compression' => true, // compress cached data

        'cmd' => [
            'exec' => 'find', // find|turbo
            'modified' => true, // gather modified timestamp
            'content' => true, // fetch content as well?
        ],

        'model' => [
            'read' => true,
            'write' => true,
        ],
    ],
    'hooks' => [
        'page.*:after' => function ($event, $page) {
            if ($event->action() !== 'render') {
                \Bnomei\Turbo::flush('dir');
            }
        },
        'file.*:after' => function ($event, $file) {
            if ($event->action() !== 'render') {
                \Bnomei\Turbo::flush('dir');
            }
        },
        'user.*:after' => function ($event, $user) {
            if ($event->action() !== 'render') {
                \Bnomei\Turbo::flush('dir');
            }
        },
    ],
]);
