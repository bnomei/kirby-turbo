<?php

return [
    'editor' => 'phpstorm',
    'debug' => true,
    'languages' => false,
    'content' => [
        'locking' => false,
    ],

    // add custom headers for tracking load and render time
    'stopwatch' => true,

    // 'bnomei.turbo.cache.cmd' => ['type' => 'preload-redis', 'database' => 6], // preload this data with turbo-redis
    'bnomei.turbo.cache.storage' => ['type' => 'redis', 'database' => 5], // load this data on demand
    'bnomei.turbo.cache.tub' => ['type' => 'turbo-redis', 'database' => 5], // load this data on demand

    'cache' => [
        'uuid' => ['type' => 'turbo-uuid'],
    ],
];
