<?php

/**
 * Copyright (c) 2025 Bruno Meilick
 * All rights reserved.
 *
 * This file is part of Kirby Turbo and is proprietary software.
 * Unauthorized copying, modification, or distribution is prohibited.
 */

namespace Bnomei;

use Closure;
use Kirby\Toolkit\A;

class TurboStaticCache
{
    public static array $cache = [];

    public static function getOrSet(array|string $key, Closure $closure): Closure
    {
        $key = static::key($key);

        // static caching will return the resolved simplified closure
        if ($value = A::get(static::$cache, $key)) {
            return $value;
        }

        // resolve now but store as a simplified closure again
        $closure = $closure();
        static::$cache[$key] = fn () => $closure;

        return static::$cache[$key];
    }

    public static function key(array|string $key): string
    {
        return is_array($key) ?
            '#'.hash('xxh3', json_encode(Turbo::serialize($key))) : // @phpstan-ignore-line
            $key; // do not alter if it's a string
    }
}
