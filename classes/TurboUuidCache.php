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

class TurboUuidCache extends FileCache
{
    private array $lines = [];

    public function __construct(array $options)
    {
        parent::__construct($options);

        $file = $this->file(''); // does not matter which key

        // preload all (duplicates are intentional; later lines overwrite earlier ones)
        foreach (array_filter(explode(PHP_EOL, F::read($file) ?: ''), fn ($v) => ! empty($v)) as $line) {
            [$created, $k, $value] = explode("\t", $line);
            $value = str_contains($value, ':') ? unserialize($value) : $value;
            $this->lines[$k] = new Value($value, 0, intval($created));
        }
    }

    protected function file(string $key): string
    {
        $file = $this->root.'/uuids';

        if (isset($this->options['extension'])) {
            return $file.'.'.$this->options['extension'];
        }

        return $file;
    }

    public function set(string $key, $value, int $minutes = 0): bool // @phpstan-ignore-line
    {
        $file = $this->file($key);
        $created = time();
        // Deliberate: we append instead of rewriting the file.
        // On load, later lines overwrite earlier ones, so last write wins.
        $this->lines[$key] = new Value($value, $minutes, $created);

        $value = ! is_string($value) ? serialize($value) : $value;

        return F::write($file, $created."\t$key\t$value".PHP_EOL, true) === true;
    }

    public function retrieve(string $key): ?Value
    {
        if (array_key_exists($key, $this->lines)) {
            return $this->lines[$key];
        }

        return $this->lines[$key] ?? null;
    }

    public function remove(string $key): bool
    {
        if (array_key_exists($key, $this->lines)) {
            unset($this->lines[$key]);
        }

        $file = $this->file($key);

        if (is_file($file) === true) {
            $useSed = kirby()->option('bnomei.turbo.uuid.remove-with-sed', true) !== false;
            if ($useSed) {
                // return shell_exec("sed -i '' '/".preg_quote($key, '/')."/d' $file") === null;
                $command = "sed -i '' '/".preg_quote($key, '/')."/d' ".escapeshellarg($file);
                exec($command, $out, $exitCode);

                return $exitCode === 0;
            }

            $tmp = tempnam(dirname($file), 'uuids-');
            if ($tmp === false) {
                return false;
            }

            $in = fopen($file, 'rb');
            $out = fopen($tmp, 'wb');
            if ($in === false || $out === false) {
                if (is_resource($in)) {
                    fclose($in);
                }
                if (is_resource($out)) {
                    fclose($out);
                }
                F::remove($tmp);

                return false;
            }

            while (($line = fgets($in)) !== false) {
                $parts = explode("\t", $line, 3);
                if (isset($parts[1]) && $parts[1] === $key) {
                    continue;
                }
                fwrite($out, $line);
            }

            fclose($in);
            fclose($out);

            if (F::move($tmp, $file, true) !== true) {
                F::remove($tmp);

                return false;
            }

            return true;
        }

        return false;
    }
}
