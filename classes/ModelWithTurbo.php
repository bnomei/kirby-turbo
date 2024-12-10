<?php

declare(strict_types=1);

namespace Bnomei;

use Kirby\Cms\File;
use Kirby\Cms\ModelWithContent;
use Kirby\Toolkit\Str;

trait ModelWithTurbo
{
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
        if ($modified = Turbo::singleton()->modified($this->root(), $languageCode)) {
            return Str::date($modified, $format, $handler);
        }

        return parent::modified(...func_get_args());
    }

    public function inventory(): array
    {
        /** @var ModelWithContent $this */
        return Turbo::singleton()->inventory($this->root()) ?? parent::inventory();
    }

    public function readContent(?string $languageCode = null): array
    {
        /** @var ModelWithContent $this */
        return Turbo::singleton()->content($this->root(), $languageCode) ?? parent::readContent($languageCode);
    }
}
