<?php

/**
 * Copyright (c) 2025 Bruno Meilick
 * All rights reserved.
 *
 * This file is part of Kirby Turbo and is proprietary software.
 * Unauthorized copying, modification, or distribution is prohibited.
 */

namespace Bnomei;

use Kirby\Cms\Language;
use Kirby\Content\PlainTextStorage;
use Kirby\Content\VersionId;

class TurboStorage extends PlainTextStorage
{
    public function delete(VersionId $versionId, Language $language): void
    {
        parent::delete($versionId, $language);

        Turbo::singleton()->storage()?->remove('#'.hash('xxh3',
            $this->contentFile($versionId, $language)
        ));
    }

    public function modified(VersionId $versionId, Language $language): ?int
    {
        $turbo = Turbo::singleton();
        if ($turbo->options['inventory.read'] && $modified = $turbo->modified(
            $this->contentFile($versionId, $language)
        )) {
            return $modified;
        }

        return parent::modified($versionId, $language);
    }

    public function read(VersionId $versionId, Language $language): array
    {
        // TODO: check again if kirby is still calling this method more often than needed
        $turbo = Turbo::singleton();

        // try reading batch-loaded data from command
        if ($turbo->options['inventory.read'] && $data = $turbo->content(
            $this->contentFile($versionId, $language)
        )) {
            return $data;
        }

        // or from the content storage mirror cache
        if ($data = $this->turboRead($versionId, $language)) {
            return $data;
        }

        // fallback to reading the file raw
        $data = parent::read($versionId, $language);

        // did not have cache yet so write a copy now
        $this->turboWrite($versionId, $language, $data);

        return $data;
    }

    protected function write(VersionId $versionId, Language $language, array $fields): void
    {
        parent::write($versionId, $language, $fields);
        $this->turboWrite($versionId, $language, $fields);
    }

    private function turboRead(VersionId $versionId, Language $language): ?array
    {
        $t = Turbo::singleton();
        $storage = $t->storage();
        if (! $storage || ! $t->options['storage.read']) {
            return null;
        }

        if ($data = $storage->get('#'.hash('xxh3',
            $this->contentFile($versionId, $language)
        ))
        ) {
            if ($t->options['storage.compression']) {
                $data = json_decode(base64_decode($data), true);
            }

            return $data;
        }

        return null;
    }

    private function turboWrite(VersionId $versionId, Language $language, array $data): void
    {
        $t = Turbo::singleton();
        $storage = $t->storage();
        if (! $storage || ! $t->options['storage.write']) {
            return;
        }

        if ($t->options['storage.compression']) {
            $data = base64_encode(gzcompress(json_encode($t->serialize($data)))); // @phpstan-ignore-line
        }
        $storage->set('#'.hash('xxh3',
            $this->contentFile($versionId, $language)
        ), $data, $t->options['expire']);
    }
}
