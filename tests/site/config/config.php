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

    'bnomei.turbo.cmd.exec' => 'find',
    'bnomei.turbo.cache' => ['type' => 'redis'],

    'cache' => [
        'uuid' => ['type' => 'redis'],
    ],
];
