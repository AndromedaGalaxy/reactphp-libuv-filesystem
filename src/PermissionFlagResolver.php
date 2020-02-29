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

class PermissionFlagResolver extends FlagResolver implements FlagResolverInterface {
    const DEFAULT_FLAG = null;
    
    protected $flagMapping = array(
        'user' => array(
            'r' => \UV::S_IRUSR,
            'w' => \UV::S_IWUSR,
            'x' => \UV::S_IXUSR
        ),
        'group' => array(
            'r' => \UV::S_IRGRP,
            'w' => \UV::S_IWGRP,
            'x' => \UV::S_IXGRP
        ),
        'universe' => array(
            'r' => \UV::S_IROTH,
            'w' => \UV::S_IWOTH,
            'x' => \UV::S_IXOTH
        )
    );
    
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
    
    /**
     * {@inheritDoc}
     */
    function resolve($flag, $flags = null, $mapping = null) {
        if(\is_int($flag)) {
            return $flag;
        }
        
        $mapFlags = ($mapping === null);
        $scopes = array(
            'user',
            'group',
            'universe'
        );
        
        $resultFlags = 0;
        for($i = \strlen($flag) - 1; $i >= 0; $i--) {
            if($mapFlags) {
                $mapping = $this->flagMapping[$scopes[\intdiv($i, 3)]];
            }
            
            if(isset($mapping[$flag[$i]])) {
                $resultFlags |= $mapping[$flag[$i]];
            }
        }
        
        return $resultFlags;
    }
}
