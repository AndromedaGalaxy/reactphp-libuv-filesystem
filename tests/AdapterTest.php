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

use Andromeda\LibuvFS\Adapter;
use React\EventLoop\Factory;
use React\EventLoop\LoopInterface;
use React\Filesystem\AdapterInterface;
use React\Filesystem\Filesystem;
use React\Filesystem\FilesystemInterface;
use React\Filesystem\Node\FileInterface;
use React\Filesystem\Node\LinkInterface;
use React\Filesystem\ObjectStream;
use React\Filesystem\ObjectStreamSink;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use function Clue\React\Block\await;

/**
 * @requires extension uv
 */
class AdapterTest extends TestCase {
    /** @var LoopInterface */
    protected $loop;
    
    /** @var AdapterInterface */
    protected $adapter;
    
    /** @var FilesystemInterface */
    protected $filesystem;
    
    /** @var string */
    protected $tmpdir;
    
    function setUp() {
        parent::setUp();
        
        $this->loop = Factory::create();
        $this->adapter = new Adapter($this->loop);
        $this->filesystem = Filesystem::createFromAdapter($this->adapter);
        
        $this->tmpdir = \sys_get_temp_dir().\DIRECTORY_SEPARATOR.\uniqid('', true).\DIRECTORY_SEPARATOR;
        \mkdir($this->tmpdir, 0777, true);
        $this->assertDirectoryExists($this->tmpdir);
        
        \clearstatcache($this->tmpdir.'testdir');
        \clearstatcache($this->tmpdir.'testdir2');
        
        \clearstatcache($this->tmpdir.'testfile');
        \clearstatcache($this->tmpdir.'testfile2');
    }
    
    function tearDown() {
        parent::tearDown();
        
        // assert that everything is cleaned up
        $ref = new \ReflectionProperty($this->adapter, 'workCounter');
        $ref->setAccessible(true);
        $counter = $ref->getValue($this->adapter);
        
        $this->assertSame(0, $counter);
    }
    
    function await(PromiseInterface $promise, LoopInterface $loop, ?float $timeout = 10.0) {
        return await($promise, $loop, $timeout);
    }
    
    function testIsSupported() {
        $this->assertTrue(Adapter::isSupported());
    }
    
    function testGetLoop() {
        $this->assertSame($this->loop, $this->adapter->getLoop());
    }
    
    function testGetFilesystem() {
        $this->assertSame($this->filesystem, $this->adapter->getFilesystem());
    }
    
    function testSetFilesystem() {
        $fs = Filesystem::createFromAdapter((new Adapter($this->loop)));
        $this->adapter->setFilesystem($fs);
        
        $this->assertSame($fs, $this->adapter->getFilesystem());
        $this->adapter->setFilesystem($this->filesystem);
    }
    
    function testMkdirAndRmdir() {
        $path = $this->tmpdir.'testdir-'.\uniqid('', true);
        $this->assertDirectoryNotExists($path);
        
        $this->await($this->adapter->mkdir($path), $this->adapter->getLoop());
        $this->assertDirectoryExists($path);
        
        $this->await($this->adapter->rmdir($path), $this->adapter->getLoop());
        $this->assertFalse(\file_exists($path)); // is_dir returns true while file_exists and realpath return false
    }
    
    function testMkdirError() {
        $path = $this->tmpdir.\uniqid('', true).\DIRECTORY_SEPARATOR.\uniqid('', true);
        
        $this->expectException(\RuntimeException::class);
        $this->await($this->adapter->mkdir($path), $this->adapter->getLoop());
    }
    
    function testRmdirError() {
        $path = $this->tmpdir.\uniqid('', true);
        
        $this->expectException(\RuntimeException::class);
        $this->await($this->adapter->rmdir($path), $this->adapter->getLoop());
    }
    
    function testUnlink() {
        $path = $this->tmpdir.\uniqid('', true);
        $this->assertTrue(\touch($path, \time(), \time()));
        
        $this->await($this->adapter->unlink($path), $this->adapter->getLoop());
        $this->assertFileNotExists($path);
    }
    
    function testUnlinkError() {
        $path = $this->tmpdir.\uniqid('', true);
        
        $this->expectException(\RuntimeException::class);
        $this->await($this->adapter->unlink($path), $this->adapter->getLoop());
    }
    
    /**
     * @group permissions
     */
    function testChmod() {
        $path = $this->tmpdir.\uniqid('', true);
        $this->assertTrue(\touch($path, \time(), \time()));
        
        $this->await($this->adapter->chmod($path, 0660), $this->adapter->getLoop());
        $this->assertSame(0660, (\fileperms($path) & 0660));
        \unlink($path);
    }
    
    function testChmodError() {
        $path = $this->tmpdir.\uniqid('', true);
        
        $this->expectException(\RuntimeException::class);
        $this->await($this->adapter->chmod($path, 0660), $this->adapter->getLoop());
    }
    
    /**
     * @group permissions
     */
    function testChown() {
        if(\PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Unsupported on Windows');
        } elseif(\getmygid() !== 0) {
            $this->markTestSkipped('Test requires to be run as root');
        }
        
        $path = $this->tmpdir.\uniqid('', true);
        $this->assertTrue(\touch($path, \time(), \time()));
        
        $this->await($this->adapter->chown($path, 0, 2), $this->adapter->getLoop());
        $stat = \stat($path);
        
        $this->assertSame(0, $stat['uid']);
        $this->assertSame(2, $stat['gid']);
        @\unlink($path);
    }
    
    function testChownError() {
        if(\PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Unsupported on Windows');
        }
        
        $path = $this->tmpdir.\uniqid('', true);
        
        $this->expectException(\RuntimeException::class);
        $this->await($this->adapter->chown($path, 0, 0), $this->adapter->getLoop());
    }
    
    function testStat() {
        if(\PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Unsupported on Windows (different dev, rdev and ino)');
        }
        
        $path = $this->tmpdir.\uniqid('', true);
        $this->assertTrue(\touch($path, \time(), \time()));
        
        $stat = $this->await($this->adapter->stat($path), $this->adapter->getLoop());
        
        $realStat = \stat($path);
        $realStat = array(
            'dev' => $realStat['dev'],
            'ino' => $realStat['ino'],
            'mode' => $realStat['mode'],
            'nlink' => $realStat['nlink'],
            'uid' => $realStat['uid'],
            'size' => $realStat['size'],
            'gid' => $realStat['gid'],
            'rdev' => $realStat['rdev'],
            'blksize' => $realStat['blksize'],
            'blocks' => $realStat['blocks'],
            'atime' => (new \DateTime('@'.$realStat['atime'])),
            'mtime' => (new \DateTime('@'.$realStat['mtime'])),
            'ctime' => (new \DateTime('@'.$realStat['ctime'])),
        );
        
        $this->assertEquals($realStat, $stat);
        @\unlink($path);
    }
    
    function testStatError() {
        $path = $this->tmpdir.\uniqid('', true);
        
        $this->expectException(\RuntimeException::class);
        $this->await($this->adapter->stat($path), $this->adapter->getLoop());
    }
    
    function testLs() {
        $path = $this->tmpdir.\uniqid('', true);
        $this->assertTrue(\touch($path, \time(), \time()));
        
        $ls = $this->await($this->adapter->ls($this->tmpdir), $this->adapter->getLoop());
        @\unlink($path);
        
        $this->assertCount(1, $ls);
        $this->assertInstanceOf(FileInterface::class, $ls[0]);
    }
    
    function testLsError() {
        $path = $this->tmpdir.\uniqid('', true);
        
        $this->expectException(\RuntimeException::class);
        $this->await($this->adapter->ls($path), $this->adapter->getLoop(), 60.0);
    }
    
    function testLsEmptyDir() {
        $path = $this->tmpdir.\uniqid('', true);
        \mkdir($path);
        
        $ls = $this->await($this->adapter->ls($path), $this->adapter->getLoop());
        
        $this->assertCount(0, $ls);
    }
    
    function testLsStream() {
        $path = $this->tmpdir.\uniqid('', true);
        $this->assertTrue(\touch($path, \time(), \time()));
        
        $stream = $this->adapter->lsStream($this->tmpdir);
        $this->assertInstanceOf(ObjectStream::class, $stream);
        
        $ls = $this->await(ObjectStreamSink::promise($stream), $this->adapter->getLoop());
        @\unlink($path);
        
        $this->assertCount(1, $ls);
        $this->assertInstanceOf(FileInterface::class, $ls[0]);
    }
    
    function testLsStreamError() {
        $path = $this->tmpdir.\uniqid('', true);
        
        $result = $this->adapter->lsStream($path);
        $this->assertInstanceOf(ObjectStream::class, $result);
        
        $deferred = new Deferred();
        $result->once('error', array($deferred, 'reject'));
        
        $this->expectException(\RuntimeException::class);
        $this->await($deferred->promise(), $this->adapter->getLoop());
    }
    
    function testLsStreamEmptyDir() {
        $path = $this->tmpdir.\uniqid('', true);
        $this->assertTrue(\mkdir($path));
        
        $stream = $this->adapter->lsStream($path);
        $this->assertInstanceOf(ObjectStream::class, $stream);
        
        $ls = $this->await(ObjectStreamSink::promise($stream), $this->adapter->getLoop());
        
        $this->assertCount(0, $ls);
    }
    
    function testTouch() {
        $path = $this->tmpdir.\uniqid('', true);
        $this->await($this->adapter->touch($path), $this->adapter->getLoop());
        
        $this->assertFileExists($path);
        @\unlink($path);
    }
    
    function testTouchExisting() {
        $path = $this->tmpdir.\uniqid('', true);
        touch($path, \time() - 50, \time() - 50);
        $this->await($this->adapter->touch($path), $this->adapter->getLoop());
        
        $this->assertFileExists($path);
        @\unlink($path);
    }
    
    function testTouchError() {
        $path = $this->tmpdir.\uniqid('', true).\DIRECTORY_SEPARATOR.\uniqid('', true);
        
        $this->expectException(\RuntimeException::class);
        $this->await($this->adapter->touch($path), $this->adapter->getLoop());
    }
    
    function testOpenReadWriteClose() {
        $path = $this->tmpdir.\uniqid('', true);
        
        $file = $this->await($this->adapter->open($path, 'cw'), $this->adapter->getLoop());
        
        $length = $this->await($this->adapter->write($file, 'hello world', 11, 0), $this->adapter->getLoop());
        $this->assertSame(11, $length);
        
        $this->await($this->adapter->close($file), $this->adapter->getLoop());
        
        $file2 = $this->await($this->adapter->open($path, 'r'), $this->adapter->getLoop());
        
        $contents = $this->await($this->adapter->read($file2, 11, 0), $this->adapter->getLoop());
        $this->assertSame('hello world', $contents);
        
        $this->await($this->adapter->close($file2), $this->adapter->getLoop());
        @unlink($path);
    }
    
    function testOpenUnkownFDError() {
        $path = $this->tmpdir.\uniqid('', true);
        
        $this->expectException(\RuntimeException::class);
        $this->await($this->adapter->open($path, 'r'), $this->adapter->getLoop());
    }
    
    function testReadUnkownFDError() {
        $path = $this->tmpdir.\uniqid('', true);
        
        $this->expectException(\RuntimeException::class);
        $this->await($this->adapter->read($path, 1, 0), $this->adapter->getLoop());
    }
    
    function testWriteUnkownFDError() {
        $path = $this->tmpdir.\uniqid('', true);
        
        $this->expectException(\RuntimeException::class);
        $this->await($this->adapter->write($path, 'hello', 5, 0), $this->adapter->getLoop());
    }
    
    function testCloseUnkownFDError() {
        $path = $this->tmpdir.\uniqid('', true);
        
        $this->expectException(\RuntimeException::class);
        $this->await($this->adapter->close($path), $this->adapter->getLoop());
    }
    
    function testRename() {
        $path = $this->tmpdir.\uniqid('', true);
        $path2 = $this->tmpdir.\uniqid('', true);
        $this->assertTrue(\touch($path, \time(), \time()));
        
        $this->await($this->adapter->rename($path, $path2), $this->adapter->getLoop());
        $this->assertFileNotExists($path);
        $this->assertFileExists($path2);
        
        @\unlink($path);
        @\unlink($path2);
    }
    
    function testRenameError() {
        $path = $this->tmpdir.\uniqid('', true);
        
        $this->expectException(\RuntimeException::class);
        $this->await($this->adapter->rename($path, $path), $this->adapter->getLoop());
    }
    
    function testConstructLink() {
        if(\PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Unsupported on Windows (permissions issue)');
        }
        
        $path = $this->tmpdir.'testdir-cl';
        $path2 = $this->tmpdir.'testdir-cl2';
        
        $this->assertTrue(\mkdir($path));
        $this->assertDirectoryExists($path);
        
        $this->assertTrue(\symlink($path, $path2));
        $this->assertFileExists($path2);
        
        $link = $this->await($this->filesystem->constructLink($path2), $this->adapter->getLoop());
        $this->assertInstanceOf(LinkInterface::class, $link);
        
        @\rmdir($path);
        @\unlink($path2);
    }
    
    function testSymlinkAndReadlink() {
        if(\PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Unsupported on Windows (permissions issue)');
        }
        
        $path = $this->tmpdir.'testdir-rl';
        $path2 = $this->tmpdir.'testdir-rl2';
        
        $this->assertTrue(\mkdir($path));
        $this->assertFalse(\realpath($path2));
        
        $this->await($this->adapter->symlink($path, $path2), $this->adapter->getLoop());
        
        $this->assertFileExists($path2);
        $this->assertSame(\realpath($path), \realpath(\readlink($path2))); // realpath is for Windows
        
        $link = $this->await($this->adapter->readlink($path2), $this->adapter->getLoop());
        $this->assertSame($path, $link);
        
        @\rmdir($path);
        @\unlink($path2);
    }
    
    function testReadlinkError() {
        $path = $this->tmpdir.\uniqid('', true);
        
        $this->expectException(\RuntimeException::class);
        $this->await($this->adapter->readlink($path), $this->adapter->getLoop());
    }
    
    function testSymlinkError() {
        $path = $this->tmpdir.\uniqid('', true).\DIRECTORY_SEPARATOR.\uniqid('', true);
        $path2 = $path.\DIRECTORY_SEPARATOR.\uniqid('', true);
        
        $this->expectException(\RuntimeException::class);
        $this->await($this->adapter->symlink($path, $path2), $this->adapter->getLoop());
    }
    
    function testDetectType() {
        $path = $this->tmpdir.\uniqid('', true);
        $this->assertTrue(\touch($path, \time(), \time()));
        
        $type = $this->await($this->adapter->detectType($path), $this->adapter->getLoop());
        $this->assertInstanceOf(FileInterface::class, $type);
        \unlink($path);
    }
    
    function testDetectTypeLink() {
        if(\PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Unsupported on Windows (permissions issue)');
        }
        
        $path = $this->tmpdir.\uniqid('', true);
        $this->assertTrue(\symlink(__FILE__, $path));
        
        $type = $this->await($this->adapter->detectType($path), $this->adapter->getLoop());
        $this->assertInstanceOf(LinkInterface::class, $type);
        \unlink($path);
    }
    
    function testGetContents() {
        $contents = $this->await($this->adapter->getContents(__FILE__), $this->loop);
        $this->assertStringEqualsFile(__FILE__, $contents);
    }
    
    function testGetContentsMinMax() {
        $contents = $this->await($this->adapter->getContents(__FILE__, 5, 10), $this->loop);
        $this->assertSame(\file_get_contents(__FILE__, false, null, 5, 10), $contents);
    }
    
    function testPutContents() {
        $tempFile = $this->tmpdir.\uniqid('', true);
        $contents = \sha1_file(__FILE__);
        
        $this->await($this->adapter->putContents($tempFile, $contents), $this->loop);
        $this->assertSame($contents, \file_get_contents($tempFile));
    }
    
    function testPutContentsOverwrite() {
        $tempFile = $this->tmpdir.\uniqid('', true);
        $contents = \sha1_file(__FILE__);
        
        \file_put_contents($tempFile, \md5($contents));
        
        $this->await($this->adapter->putContents($tempFile, $contents), $this->loop);
        $this->assertSame($contents, \file_get_contents($tempFile));
    }
    
    function testAppendContents() {
        $tempFile = $this->tmpdir.\uniqid('', true);
        $contents = \sha1_file(__FILE__);
        
        \file_put_contents($tempFile, $contents);
        $time = \sha1(\time());
        $contents .= $time;
        
        $this->await($this->adapter->appendContents($tempFile, $time), $this->loop);
        $this->assertSame($contents, \file_get_contents($tempFile));
    }
    
    function testCallFilesystemNotCallable() {
        $result = $this->adapter->callFilesystem('uv_fs_open', array(), \time());
        $this->assertInstanceOf(PromiseInterface::class, $result);
        
        $this->expectException(\InvalidArgumentException::class);
        await($result, $this->loop, 0.1);
    }
    
    /** @noinspection PhpUnusedParameterInspection */
    function testCallFilesystemFunctionThrows() {
        $test = static function (\UVLoop $loop) {
            throw new \LogicException('test');
        };
        
        $result = $this->adapter->callFilesystem($test, array(), $test);
        $this->assertInstanceOf(PromiseInterface::class, $result);
        
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('test');
        
        await($result, $this->loop, 0.1);
    }
    
    /** @noinspection PhpUnusedParameterInspection */
    function testCallFilesystemCallableThrows() {
        $cb = static function () {
            throw new \LogicException('test');
        };
        
        $test = static function (\UVLoop $uv, callable $cb) {
            $cb();
        };
        
        $result = $this->adapter->callFilesystem($test, array(), $cb);
        $this->assertInstanceOf(PromiseInterface::class, $result);
        
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('test');
        
        await($result, $this->loop, 1.0);
    }
}
