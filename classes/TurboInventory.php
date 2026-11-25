<?php

namespace Bnomei;

use Kirby\Cms\Inventory;
use Kirby\Cms\Language;

class TurboInventory extends Inventory
{
    public function __construct(string $dir, string $extension = 'txt', ?array $ignore = null, bool $multilang = false)
    {
        $this->dir = realpath($dir) ?: $dir;
        $this->extension = $extension;
        $this->multilang = $multilang;

        $items = TurboDir::read($this->dir, $ignore);
        natsort($items);
        foreach ($items as $item) {
            if (str_starts_with($item, '.') || str_starts_with($item, '_')) {
                continue;
            }
            $ext = pathinfo($item, PATHINFO_EXTENSION);
            if ($ext === $extension) {
                $name = pathinfo($item, PATHINFO_FILENAME);
                $this->content[] = $multilang ? pathinfo($name, PATHINFO_FILENAME) : $name;
            } else {
                $this->items[$item] = $ext;
            }
        }
        $this->content = array_unique($this->content);
    }

    protected function isDir(string $item): bool
    {
        return $this->dirs[$item] ??= TurboDir::is_dir($this->dir.'/'.$item);
    }

    /** @param array<string, class-string> $models */
    protected function child(string $item, array $models): array
    {
        // Let Kirby parse the directory name, but resolve models from the index.
        $child = parent::child($item, []);
        $extension = $this->multilang && $models !== [] ? Language::ensure('default')->code().'.'.$this->extension : $this->extension;
        foreach (array_keys($models) as $name) {
            if (TurboDir::is_file($child['root'].'/'.$name.'.'.$extension)) {
                $child['model'] = $name;
                break;
            }
        }

        return $child;
    }
}
