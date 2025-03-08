<?php

namespace Bnomei;

use DateTime;
use Kirby\Cache\RedisCache;
use Kirby\Cache\Value;
use Kirby\Toolkit\A;

class TurboRedisCache extends RedisCache
{
    protected static bool $preload = false;

    protected array $options = [];

    private array $data = [];

    public function __construct(array $options = [])
    {
        parent::__construct($options);

        $this->options = array_merge([
            // 'debug' => boolval(option('debug')),
            'validate-value-as-json' => option('bnomei.turbo.preload-redis.validate-value-as-json'),
            'json-encode-flags' => option('bnomei.turbo.preload-redis.json-encode-flags'),
        ], $options);

        if (static::$preload) {
            $this->data = $this->preload();
        }
    }

    /**
     * {@inheritDoc}
     */
    public function key(array|string $key): string
    {
        return is_array($key) ?
            // flatten models as well
            '#'.hash('xxh3', json_encode(Turbo::serialize($key, true))) : // @phpstan-ignore-line
            $key; // do not alter if it's a string
    }

    /**
     * {@inheritDoc}
     */
    public function set(string|array $key, mixed $value, int|string $minutes = 0): bool
    {
        // flatten kirby fields but not models
        $value = Turbo::serialize($value, false);

        // make sure the value can be stored as json
        // if not fail here so a trace is more helpful
        if (is_string($value) && $this->options['validate-value-as-json']) {
            $json_encode = json_encode($value, $this->options['json-encode-flags']);
            $value = $json_encode ? json_decode($json_encode, true) : null;
        }

        $key = $this->key($key);
        if (is_string($minutes)) {
            $minutes = (int) round(((new DateTime($minutes))->getTimestamp() - time()) / 60);
        }
        $value = new TurboValue($value, $minutes);

        // store a copy in memory
        $this->data[$key] = $value;

        // package for storing
        $value = $value->toJson();

        if ($minutes > 0) {
            return $this->connection->setex($key, $minutes * 60, $value);
        }

        return $this->connection->set($key, $value);
    }

    /**
     * {@inheritDoc}
     */
    public function retrieve(array|string $key): ?Value
    {
        $key = $this->key($key);

        // load from memory if possible
        if ($value = A::get($this->data, $key)) {
            return $value;
        }

        // else load and store copy
        $value = strval($this->connection->get($this->key($key))); // @phpstan-ignore-line
        $value = TurboValue::fromJson($value);
        $this->data[$key] = $value;

        return $value;
    }

    /**
     * {@inheritDoc}
     */
    public function get(array|string $key, mixed $default = null): mixed
    {
        // overwrite to allow for array as keys, resolved now
        return parent::get($this->key($key), $default);
    }

    public function getOrSet(string $key, \Closure $result, int|string $minutes = 0) // @phpstan-ignore-line
    {
        $value = $this->get($key);

        // allow for abort via exception
        try {
            $result = $value ?? $result();
        } catch (AbortCachingException $e) {
            return null;
        }

        if ($value === null) {
            $this->set($key, $result, $minutes);
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function remove(string|array $key): bool
    {
        $key = $this->key($key);
        if (array_key_exists($key, $this->data)) {
            unset($this->data[$key]);
        }

        return parent::remove($key);
    }

    /**
     * {@inheritDoc}
     */
    public function flush(): bool
    {
        // clear internal memory as well
        $this->data = [];

        return parent::flush();
    }

    public function preload(?array $keys = null): array
    {
        $data = [];

        if (! $keys) {
            // scan is more performant than keys('*')
            while ($keys = $this->connection->scan($iterator)) {
                foreach ($keys as $key) {
                    $data[$key] = $this->get($key); // will auto-clean
                }
            }
        } else {
            foreach ($keys as $key) {
                $data[$key] = $this->get($key);
            }
        }

        return $data;
    }
}
