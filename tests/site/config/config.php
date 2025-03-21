<?php

return [
    'editor' => 'phpstorm',
    'debug' => true,
    'languages' => false,
    'content' => [
        'locking' => false,
    ],

    'bnomei.turbo.license' => '',
    // 'bnomei.turbo.cache.cmd' => ['type' => 'preload-redis', 'database' => 6], // preload this data with turbo-redis
    // 'bnomei.turbo.cache.storage' => ['type' => 'redis', 'database' => 5], // load this data on demand
    // 'bnomei.turbo.cache.tub' => ['type' => 'turbo-redis', 'database' => 5], // load this data on demand

    'cache' => ['uuid' => ['type' => 'turbo-uuid']],

    'routes' => [
        [
            'pattern' => 'all', 'action' => function () {
                return site()->visit(new \Kirby\Cms\Page([
                    'slug' => 'all',
                    'template' => 'all',
                    'content' => [
                        'title' => 'All',
                    ],
                ]));
            }
        ],
    ],
];
