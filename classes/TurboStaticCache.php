<?php

namespace Bnomei;

use Closure;
use Kirby\Toolkit\A;

class TurboStaticCache
{
    public static array $cache = [];

    public static function getOrSet(array|string $key, Closure $closure): mixed
    {
        $key = static::key($key);

        if ($value = A::get(static::$cache, $key)) {
            // load lazy and resolve if needed
            if ($value instanceof Closure) {
                $value = $value();
                static::$cache[$key] = $value;
            }

            return $value;
        }

        static::$cache[$key] = $closure; // store lazy

        return static::$cache[$key];
    }

    public static function key(array|string $key): string
    {
        return is_array($key) ?
            '#'.hash('xxh3', json_encode(Turbo::serialize($key))) : // @phpstan-ignore-line
            $key; // do not alter if it's a string
    }
}
