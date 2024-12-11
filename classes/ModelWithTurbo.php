<?php

declare(strict_types=1);

namespace Bnomei;

use Kirby\Cms\File;
use Kirby\Cms\ModelWithContent;
use Kirby\Toolkit\Str;

trait ModelWithTurbo
{
    private bool $turboCacheWillBeDeleted = false;

    public function hasTurbo(): bool
    {
        /** @var ModelWithContent $this */

        // files have turbo if their parents do
        if ($this instanceof File && method_exists($this->parent(), 'hasTurbo')) {
            return $this->parent()?->hasTurbo() === true;
        }

        return true;
    }

    public function modified(?string $format = null, ?string $handler = null, ?string $languageCode = null
    ): false|int|null|string {
        /** @var ModelWithContent $this */
        if ($modified = Turbo::singleton()->modified($this->version()->contentFile($languageCode ?? 'default'))) {
            return Str::date($modified, $format, $handler);
        }

        return parent::modified(...func_get_args());
    }

    public function inventory(): array
    {
        /** @var ModelWithContent $this */
        return Turbo::singleton()->inventory($this->root()) ?? parent::inventory();
    }

    public function setTurboCacheWillBeDeleted(bool $value): void
    {
        $this->turboCacheWillBeDeleted = $value;
    }

    public function keyTurbo(?string $languageCode = null): string
    {
        $key = $this->id(); // can not use UUID since content not loaded yet
        if (! $languageCode) {
            $languageCode = kirby()->languages()->count() ? kirby()->language()?->code() : null;
        }
        if ($languageCode) {
            $key = $key.'-'.$languageCode;
        }

        return hash('xxh3', $key);
    }

    public function readContentCache(?string $languageCode = null): ?array
    {
        $key = $this->keyTurbo($languageCode);
        $data = Turbo::singleton()->cache('model')->get($key);
        if (is_array($data) || is_null($data)) {
            return $data;
        }

        return null;
    }

    public function readContent(?string $languageCode = null): array
    {
        // read from boostedCache if exists
        $data = option('bnomei.turbo.model.read') === false ? null : $this->readContentCache($languageCode);

        // read from file and update
        if (! $data) {

            /** @var ModelWithContent $this */
            $data = Turbo::singleton()->content($this->root(), $languageCode) ?? parent::readContent($languageCode);

            if ($data && $this->turboCacheWillBeDeleted !== true) {
                $this->writeTurbo($data, $languageCode);
            }
        }

        return $data;
    }

    public function writeTurbo(?array $data = null, ?string $languageCode = null): bool
    {
        if (option('bnomei.turbo.model.write') === false) {
            return true;
        }

        return Turbo::singleton()->cache('model')->set($this->keyTurbo($languageCode), $data);
    }

    public function writeContent(array $data, ?string $languageCode = null): bool
    {
        // write to file and cache
        return parent::writeContent($data, $languageCode) &&
            $this->writeTurbo($data, $languageCode);
    }

    public function deleteTurbo(): bool
    {
        $this->setTurboCacheWillBeDeleted(true);

        if (kirby()->multilang()) {
            foreach (kirby()->languages() as $language) {
                Turbo::singleton()->cache('model')->remove($this->keyTurbo($language->code())); // @phpstan-ignore-line
            }
        } else {
            Turbo::singleton()->cache('model')->remove($this->keyTurbo());
        }

        return true;
    }

    public function delete(bool $force = false): bool
    {
        $success = parent::delete($force); // @phpstan-ignore-line

        $this->deleteTurbo();

        return $success;
    }
}
