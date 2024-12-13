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

    'bnomei.turbo.cache.anything' => ['type' => 'redis'],
    'bnomei.turbo.cache.cmd' => ['type' => 'redis'],
    'bnomei.turbo.cache.storage' => ['type' => 'redis'],

    'cache' => [
        'uuid' => ['type' => 'redis'],
    ],
];
