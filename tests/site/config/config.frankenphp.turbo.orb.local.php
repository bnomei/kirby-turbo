<?php

return [
    'url' => 'https://frankenphp.turbo.orb.local',

    'bnomei.turbo.cache.storage' => [
        'type' => 'redis',
        'host' => 'redis',
        'port' => 6379,
        'database' => 5,
    ],
    'bnomei.turbo.cache.tub' => [
        'type' => 'turbo-redis',
        'host' => 'redis',
        'port' => 6379,
        'database' => 6,
    ],
];
