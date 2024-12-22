<?php

namespace Bnomei;

use Kirby\Toolkit\A;
use Kirby\Toolkit\Str;

class TurboStopwatch
{
    public static array $timestamps = [];

    public static function before(string $event): float
    {
        return static::$timestamps[$event.':before'] = microtime(true);
    }

    public static function after(string $event): float
    {
        return static::$timestamps[$event.':after'] = microtime(true);
    }

    public static function tick(string $event): float
    {
        return static::$timestamps[$event] = microtime(true);
    }

    public static function events(): array
    {
        return array_unique(array_map(
            fn ($item) => explode(':', $item)[0],
            array_keys(static::$timestamps)
        ));
    }

    public static function duration(string $event): ?int
    {
        $before = A::get(self::$timestamps, $event.':before', null);
        $after = A::get(self::$timestamps, $event.':after', null);

        if (! $before || ! $after) {
            return null;
        }

        return (int) round(($after - $before) * 1000);
    }

    public static function header(string $event, bool $return = false): ?string
    {
        // fail-safe in case someone feeds in a hook like format
        $event = explode(':', $event)[0];

        $duration = static::duration($event);
        if ($duration === null) {
            return null;
        }

        $header = 'X-Stopwatch-'.implode('-', array_map('ucfirst', explode('-', str_replace('.', '-', $event)))).': '.$duration.'ms';
        if ($return === false) {
            header($header);

            return null;
        }

        return $header;
    }

    /*
 * miss|static
 */
    public static function serverTimingHeader(string $cache = 'miss'): void
    {
        $msg = '';
        $data = [
            'cache' => $cache,
        ];
        foreach (static::events() as $event) {
            $data[$event] = static::duration($event).'ms';
        }
        foreach ($data as $key => $value) {
            $msg .= Str::snake(str_replace('.', '_', $key)).';desc="'.$value.'",';
        }

        header('server-timing: '.rtrim($msg, ','));
    }
}
