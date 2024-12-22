<?php

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

        // preload all
        foreach (array_filter(explode(PHP_EOL, F::read($file) ?: ''), fn ($v) => ! empty($v)) as $line) {
            [$created, $k, $value] = explode("\t", $line);
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
        $this->lines[$key] = new Value($value, $minutes, $created);

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
            return shell_exec("sed -i '' '/".preg_quote($key, '/')."/d' $file") === null;
        }

        return false;
    }
}
