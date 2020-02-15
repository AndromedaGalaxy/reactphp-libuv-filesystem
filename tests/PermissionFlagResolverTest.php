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

use Andromeda\LibuvFS\PermissionFlagResolver;

/**
 * @requires ext uv
 */
class PermissionFlagResolverTest extends TestCase {
    function providerResolveProvider() {
        return array(
            array(
                'r--------',
                \UV::S_IRUSR,
            ),
            array(
                '-w-------',
                \UV::S_IWUSR,
            ),
            array(
                '--x------',
                \UV::S_IXUSR,
            ),
            array(
                '---r-----',
                \UV::S_IRGRP,
            ),
            array(
                '----w----',
                \UV::S_IWGRP,
            ),
            array(
                '-----x---',
                \UV::S_IXGRP,
            ),
            array(
                '------r--',
                \UV::S_IROTH,
            ),
            array(
                '-------w-',
                \UV::S_IWOTH,
            ),
            array(
                '--------x',
                \UV::S_IXOTH,
            ),
            array(
                'rwxrwxrwx',
                (\UV::S_IRWXU | \UV::S_IRWXG | \UV::S_IRWXO),
            ),
            array(
                '-wxrwxrwx',
                (\UV::S_IRWXO | \UV::S_IRWXG | \UV::S_IWUSR | \UV::S_IXUSR),
            ),
            array(
                'r-xrwxrwx',
                (\UV::S_IRWXO | \UV::S_IRWXG | \UV::S_IRUSR | \UV::S_IXUSR),
            ),
            array(
                'rw-rwxrwx',
                (\UV::S_IRWXO | \UV::S_IRWXG | \UV::S_IRUSR | \UV::S_IWUSR),
            ),
            array(
                'rwx-wxrwx',
                (\UV::S_IRWXU | \UV::S_IWGRP | \UV::S_IXGRP | \UV::S_IRWXO),
            ),
            array(
                'rwxr-xrwx',
                (\UV::S_IRWXU | \UV::S_IRGRP | \UV::S_IXGRP | \UV::S_IRWXO),
            ),
            array(
                'rwxrw-rwx',
                (\UV::S_IRWXU | \UV::S_IRGRP | \UV::S_IWGRP | \UV::S_IRWXO),
            ),
            array(
                'rwxrwx-wx',
                (\UV::S_IWOTH | \UV::S_IXOTH | \UV::S_IRWXG | \UV::S_IRWXU),
            ),
            array(
                'rwxrwxr-x',
                (\UV::S_IROTH | \UV::S_IXOTH | \UV::S_IRWXG | \UV::S_IRWXU),
            ),
            array(
                'rwxrwxrw-',
                (\UV::S_IROTH | \UV::S_IWOTH | \UV::S_IRWXG | \UV::S_IRWXU),
            ),
            array(
                'rw-rw-rw-',
                (\UV::S_IRUSR | \UV::S_IWUSR | \UV::S_IRGRP | \UV::S_IWGRP | \UV::S_IROTH | \UV::S_IWOTH),
            ),
            array(
                'rwxrwx---',
                (\UV::S_IRWXU | \UV::S_IRWXG),
            ),
            array(
                'rw-rw-r--',
                (\UV::S_IRUSR | \UV::S_IWUSR | \UV::S_IRGRP | \UV::S_IWGRP | \UV::S_IROTH),
            ),
        );
    }
    
    function testDefaultFlags() {
        $resolver = new PermissionFlagResolver();
        $this->assertNull($resolver->defaultFlags());
    }
    
    function testFlagMapping() {
        $resolver = new PermissionFlagResolver();
        $flags = $resolver->flagMapping();
        
        $this->assertIsArray($flags);
        $this->assertCount(3, $flags);
        $this->assertArrayHasKey('user', $flags);
        $this->assertArrayHasKey('group', $flags);
        $this->assertArrayHasKey('universe', $flags);
    }
    
    /**
     * @dataProvider providerResolveProvider
     * @param mixed  $flags
     * @param mixed  $result
     */
    function testResolve($flags, $result) {
        $resolver = new PermissionFlagResolver();
        $this->assertSame($result, $resolver->resolve($flags));
    }
    
    function testResolveInt() {
        $resolver = new PermissionFlagResolver();
        $this->assertSame(5, $resolver->resolve(5));
    }
}
