<?php

declare(strict_types=1);

namespace Bnomei;

use Kirby\Cms\App;
use Kirby\Cms\Files;
use Kirby\Filesystem\F;
use Kirby\Toolkit\Str;
use ReflectionClass;

class TurboFile extends \Kirby\Cms\File
{
    use ModelWithTurbo;

    public static function patchFilesClass(): bool
    {
        $key = 'files.'.App::versionHash().'.patch';
        $patch = Turbo::singleton()->cache()?->get($key);
        if (! $patch) {
            return false;
        }

        $filesClass = (new ReflectionClass(Files::class))->getFileName();
        if ($filesClass && F::exists($filesClass) && F::isWritable($filesClass)) {
            $code = F::read($filesClass);
            if ($code && Str::contains($code, '\Bnomei\TurboFile::factory') === false) {
                $code = str_replace('File::factory(', '\Bnomei\TurboFile::factory(', $code);
                F::write($filesClass, $code);

                if (function_exists('opcache_invalidate')) {
                    opcache_invalidate($filesClass); // @codeCoverageIgnore
                }
            }

            // cache forever since tied to app:version
            return Turbo::singleton()->cache()?->set($key, date('c'), 0) ?? false;
        }

        return false;
    }
}
