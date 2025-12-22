<?php

/**
 * Copyright (c) 2025 Bruno Meilick
 * All rights reserved.
 *
 * This file is part of Kirby Turbo and is proprietary software.
 * Unauthorized copying, modification, or distribution is prohibited.
 */

declare(strict_types=1);

namespace Bnomei;

use Kirby\Cms\File;
use Kirby\Content\Storage;

trait ModelWithTurbo
{
    /*
     * Helper to flag if trait was applied to a class.
     * Use with `method_exits($obj, 'hasTurbo')` or
     * in calling `$obj->hasTurbo() === true`
     */
    public function hasTurbo(): bool
    {
        // files have turbo if their parents do
        if ($this instanceof File && method_exists($this->parent(), 'hasTurbo')) {
            return $this->parent()?->hasTurbo() === true;
        }

        return true;
    }

    public function inventory(): array
    {
        return Turbo::singleton()->inventory($this->root()) ?? parent::inventory();
    }

    public function storage(): Storage
    {
        return $this->storage ??= new TurboStorage(model: $this);
    }
}
