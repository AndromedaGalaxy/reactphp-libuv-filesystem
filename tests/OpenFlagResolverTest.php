<?php
/**
 * Andromeda
 * Copyright 2020 Andromeda, All Rights Reserved
 *
 * Website: https://github.com/AndromedaGalaxy/reactphp-libuv-filesystem
 * License: https://github.com/AndromedaGalaxy/reactphp-libuv-filesystem/blob/master/LICENSE
 * @noinspection PhpUnhandledExceptionInspection
 */

namespace Andromeda\LibuvFS\Tests;

use Andromeda\LibuvFS\OpenFlagResolver;

class OpenFlagResolverTest extends TestCase {
    /**
     * @var OpenFlagResolver
     */
    protected $resolver;
    
    function setUp() {
        parent::setUp();
        $this->resolver = new OpenFlagResolver();
    }
    
    function tearDown() {
        unset($this->resolver);
        parent::tearDown();
    }
    
    function testDefaultFlags() {
        $this->assertSame(OpenFlagResolver::DEFAULT_FLAG, $this->resolver->defaultFlags());
    }
    
    function testInheritance() {
        $this->assertInstanceOf('React\Filesystem\FlagResolver', $this->resolver);
        $this->assertInstanceOf('React\Filesystem\FlagResolverInterface', $this->resolver);
    }
    
    function testFlagMappingType() {
        $this->assertIsArray($this->resolver->flagMapping());
    }
}
