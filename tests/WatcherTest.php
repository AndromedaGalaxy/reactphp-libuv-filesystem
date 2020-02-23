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

use Andromeda\LibuvFS\Watcher;
use React\EventLoop\ExtUvLoop;
use React\Promise\Deferred;
use function Clue\React\Block\await;

class WatcherTest extends TestCase {
    /**
     * @var string
     */
    protected $path;
    
    /**
     * @var ExtUvLoop
     */
    protected $loop;
    
    /**
     * @var Watcher
     */
    protected $watcher;
    
    function setUp() {
        parent::setUp();
        
        $this->path = \sys_get_temp_dir().\DIRECTORY_SEPARATOR.\uniqid('', true).\DIRECTORY_SEPARATOR;
        if(!\file_exists($this->path)) {
            \mkdir($this->path);
        }
        
        \touch($this->path.'existing');
        
        $this->loop = new ExtUvLoop();
        $this->watcher = new Watcher($this->path, $this->loop);
    }
    
    function tearDown() {
        parent::tearDown();
        $this->watcher->close();
    }
    
    function testGetPath() {
        $this->assertSame(\rtrim($this->path, \DIRECTORY_SEPARATOR), $this->watcher->getPath());
    }
    
    function testWatchingCreateNewFile() {
        $cbCalled = 0;
        $deferred = new Deferred();
        
        $this->watcher->on('change', static function () use ($deferred, &$cbCalled) {
            $cbCalled++;
            $deferred->resolve(\func_get_args());
        });
        
        \file_put_contents($this->path.__FUNCTION__, 'test');
        
        $result = await($deferred->promise(), $this->loop, 1.0);
        $this->assertSame(array(__FUNCTION__), $result);
        $this->assertSame(1, $cbCalled);
    }
    
    function testWatchingRenameFile() {
        $cbCalled = 0;
        $deferred = new Deferred();
        
        $this->watcher->on('change', static function () use ($deferred, &$cbCalled) {
            $cbCalled++;
            $deferred->resolve(\func_get_args());
        });
        
        \rename($this->path.'existing', $this->path.'existing2');
        
        $result = await($deferred->promise(), $this->loop, 1.0);
        if(\PHP_OS_FAMILY !== 'Windows' || $result !== array(null) {
            $this->assertSame(array('existing'), $result);
        }
        $this->assertSame(2, $cbCalled);
    }
    
    function testWatchingDeleteFile() {
        $cbCalled = 0;
        $deferred = new Deferred();
        
        $this->watcher->on('change', static function () use ($deferred, &$cbCalled) {
            $cbCalled++;
            $deferred->resolve(\func_get_args());
        });
        
        \unlink($this->path.'existing');
        
        $result = await($deferred->promise(), $this->loop, 1.0);
        if(\PHP_OS_FAMILY !== 'Windows' || $result !== array(null) {
            $this->assertSame(array('existing'), $result);
        }
        $this->assertSame(1, $cbCalled);
    }
    
    function testWatchingRenameTarget() {
        $cbCalled = 0;
        $deferred = new Deferred();
        
        $this->watcher->on('change', static function () use ($deferred, &$cbCalled) {
            $cbCalled++;
            $deferred->resolve(\func_get_args());
        });
        
        \rename($this->path, \sys_get_temp_dir().\DIRECTORY_SEPARATOR.\uniqid('', true));
        
        $result = await($deferred->promise(), $this->loop, 5.0);
        $this->assertSame(array(''), $result);
        $this->assertSame(1, $cbCalled);
    }
    
    function testClose() {
        $this->watcher->on('change', $this->getCallableMock(0));
        
        $this->watcher->close();
        $this->watcher->close(); // idempotent
        
        \unlink($this->path.'existing');
    }
}
