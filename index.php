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
        'compression' => true,
        'fetch' => 'find', // find|turbo
    ],
    'hooks' => [
        'page.*:after' => function ($event, $page) {
            if ($event->action() !== 'render') {
                \Bnomei\Turbo::flush();
            }
        },
        'file.*:after' => function ($event, $file) {
            if ($event->action() !== 'render') {
                \Bnomei\Turbo::flush();
            }
        },
        'user.*:after' => function ($event, $user) {
            if ($event->action() !== 'render') {
                \Bnomei\Turbo::flush();
            }
        },
    ],
]);
