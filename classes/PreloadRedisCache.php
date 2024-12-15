<?php

namespace Bnomei;

class PreloadRedisCache extends TurboRedisCache
{
    protected static bool $preload = true;
}
