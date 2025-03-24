<?php

/**
 * Copyright (c) 2025 Bruno Meilick
 * All rights reserved.
 *
 * This file is part of Kirby Turbo and is proprietary software.
 * Unauthorized copying, modification, or distribution is prohibited.
 */

namespace Bnomei;

use Kirby\Cache\FileCache;
use Kirby\Cache\Value;
use Kirby\Filesystem\F;
use Kirby\Toolkit\A;

class TurboFileCache extends FileCache
{
    private array $data = [];

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

        $key = $this->key($key);
        if (is_string($minutes)) {
            $minutes = (int) round(((new DateTime($minutes))->getTimestamp() - time()) / 60);
        }
        $value = new TurboValue($value, $minutes);

        // store a copy in memory
        $this->data[$key] = $value;

        // package for storing
        $value = $value->toJson();

        $file = $this->file($key);

        return F::write($file, $value);
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
        $file = $this->file($key);
        $value = F::read($file);
        $value = is_string($value) ? TurboValue::fromJson($value) : null;
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
}
