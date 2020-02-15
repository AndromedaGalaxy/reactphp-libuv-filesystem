<?php
/**
 * Andromeda
 * Copyright 2020 Andromeda, All Rights Reserved
 *
 * Website: https://github.com/AndromedaGalaxy/reactphp-libuv-filesystem
 * License: https://github.com/AndromedaGalaxy/reactphp-libuv-filesystem/blob/master/LICENSE
 */

namespace Andromeda\LibuvFS;

use React\Filesystem\FlagResolver;
use React\Filesystem\FlagResolverInterface;

class OpenFlagResolver extends FlagResolver implements FlagResolverInterface {
    const DEFAULT_FLAG = null;
    
    protected $flagMapping = [
        '+' => \UV::O_RDWR,
        'a' => \UV::O_APPEND,
        'c' => \UV::O_CREAT,
        'e' => \UV::O_EXCL,
        'r' => \UV::O_RDONLY,
        't' => \UV::O_TRUNC,
        'w' => \UV::O_WRONLY,
    ];
    
    /**
     * {@inheritDoc}
     */
    function defaultFlags() {
        return static::DEFAULT_FLAG;
    }
    
    /**
     * {@inheritDoc}
     */
    function flagMapping() {
        return $this->flagMapping;
    }
}
