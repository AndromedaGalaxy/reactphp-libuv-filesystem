<?php
/**
 * Andromeda
 * Copyright 2020 Andromeda, All Rights Reserved
 *
 * Website: https://github.com/AndromedaGalaxy/reactphp-libuv-filesystem
 * License: https://github.com/AndromedaGalaxy/reactphp-libuv-filesystem/blob/master/LICENSE
 */

namespace Andromeda\LibuvFS;

use React\EventLoop\ExtUvLoop;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\Filesystem;
use React\Filesystem\AdapterInterface;
use React\Filesystem\FilesystemInterface;
use React\Filesystem\MappedTypeDetector;
use React\Filesystem\ModeTypeDetector;
use React\Filesystem\Node\NodeInterface;
use React\Filesystem\ObjectStream;
use React\Filesystem\ObjectStreamSink;
use React\Filesystem\TypeDetectorInterface;
use React\Promise;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use function WyriHaximus\React\futurePromise;

class Adapter implements AdapterInterface {
    /**
     * @var ExtUvLoop
     */
    protected $loop;
    
    /**
     * @var FilesystemInterface
     */
    protected $filesystem;
    
    /**
     * @var OpenFlagResolver
     */
    protected $openFlagResolver;
    
    /**
     * @var PermissionFlagResolver
     */
    protected $permissionFlagResolver;
    
    /**
     * @var TypeDetectorInterface[]
     */
    protected $typeDetectors;
    
    /**
     * @var array
     */
    protected $fileDescriptors = array();
    
    /**
     * @var array
     */
    protected $options = array(
        'lsFlags' => 0,
        'symlinkFlags' => 0,
    );
    
    /**
     * @var int
     */
    protected $workCounter = 0;
    
    /**
     * @var TimerInterface|null
     */
    protected $workTimer;
    
    /**
     * @var int
     */
    protected $workInterval;
    
    /**
     * @inheritDoc
     */
    function __construct(ExtUvLoop $loop, array $options = []) {
        $this->loop = $loop;
        $this->options = \array_merge($this->options, $options);
        $this->workInterval = ((int) (\PHP_INT_MAX / 1000)) - 1;
        
        $this->openFlagResolver = new OpenFlagResolver();
        $this->permissionFlagResolver = new PermissionFlagResolver();
    }
    
    /**
     * @return bool
     */
    static function isSupported() {
        return \extension_loaded('uv');
    }
    
    /**
     * @return LoopInterface
     */
    function getLoop() {
        return $this->loop;
    }
    
    /**
     * {@inheritDoc}
     */
    function getFilesystem() {
        return $this->filesystem;
    }
    
    /**
     * {@inheritDoc}
     */
    function setFilesystem(FilesystemInterface $filesystem) {
        $this->filesystem = $filesystem;
        
        $this->typeDetectors = array(
            MappedTypeDetector::createDefault($this->filesystem),
            new ModeTypeDetector($this->filesystem),
        );
    }
    
    /**
     * Call the underlying filesystem.
     *
     * @param callable  $function
     * @param array     $args
     * @param callable  $callable
     * @return PromiseInterface
     * @internal
     */
    function callFilesystem($function, $args, $callable = null) {
        if(!\is_callable($callable)) {
            return Promise\reject((new \InvalidArgumentException('Invalid callable')));
        }
        
        $this->register();
        $deferred = new Deferred();
        
        $args[] = function (...$args) use ($deferred, $callable, $function) {
            $this->unregister();
            
            // Without a future tick it's very likely that the underlying
            // uv loop handle we try to use got garbage collected somehow
            // (destructor called)
            $this->loop->futureTick(static function () use ($args, $deferred, $callable) {
                try {
                    $deferred->resolve(\call_user_func_array($callable, $args));
                } catch (\Throwable $e) {
                    $deferred->reject($e);
                }
            });
        };
        
        try {
            /** @noinspection PhpInternalEntityUsedInspection */
            $function($this->loop->getUvLoop(), ...$args);
        } catch (\Throwable $e) {
            $this->unregister();
            $deferred->reject($e);
        }
        
        return $deferred->promise();
    }
    
    /**
     * @param string  $path
     * @param int     $mode
     * @return PromiseInterface
     */
    function chmod($path, $mode) {
        return $this->callFilesystem(
            'uv_fs_chmod',
            array(
                $path,
                $mode
            ),
            static function ($result) {
                if($result === false) {
                    throw new \RuntimeException('Unable to set chmod on target');
                }
            }
        );
    }
    
    /**
     * @param string  $path
     * @param mixed   $mode
     * @return PromiseInterface
     */
    function mkdir($path, $mode = self::CREATION_MODE) {
        return $this->callFilesystem(
            'uv_fs_mkdir',
            array(
                $path,
                $this->permissionFlagResolver->resolve($mode)
            ),
            static function ($result) {
                if($result === false) {
                    throw new \RuntimeException('Unable to create directory at path');
                }
            }
        );
    }
    
    /**
     * @param string  $path
     * @return PromiseInterface
     */
    function rmdir($path) {
        return $this->callFilesystem(
            'uv_fs_rmdir',
            array(
                $path
            ),
            static function ($result) {
                if($result === false) {
                    throw new \RuntimeException('Unable to delete directory');
                }
            }
        );
    }
    
    /**
     * @param string  $path
     * @return PromiseInterface
     */
    function unlink($path) {
        return $this->callFilesystem(
            'uv_fs_unlink',
            array(
                $path
            ),
            static function ($result) {
                if($result === false) {
                    throw new \RuntimeException('Unable to delete the target');
                }
            }
        );
    }
    
    /**
     * @param string  $path
     * @param int     $uid
     * @param int     $gid
     * @return PromiseInterface
     */
    function chown($path, $uid, $gid) {
        return $this->callFilesystem(
            'uv_fs_chown',
            array(
                $path,
                $uid,
                $gid
            ),
            static function ($result) {
                if($result === false) {
                    throw new \RuntimeException('Unable to chown the target');
                }
            }
        );
    }
    
    /**
     * @param string  $filename
     * @return PromiseInterface
     */
    function stat($filename) {
        return $this->callFilesystem(
            'uv_fs_lstat',
            array(
                $filename
            ),
            static function ($bool, $stat = null) {
                if($bool !== true) {
                    throw new \RuntimeException('Unable to stat the target');
                }
                
                $stat['blksize'] = $stat['blksize'] ?? -1;
                $stat['blocks'] = $stat['blocks'] ?? -1;
                $stat['atime'] = new \DateTime('@'.$stat['atime']);
                $stat['mtime'] = new \DateTime('@'.$stat['mtime']);
                $stat['ctime'] = new \DateTime('@'.$stat['ctime']);
                
                return $stat;
            }
        );
    }
    
    /**
     * @param string  $path
     * @return PromiseInterface
     */
    function ls($path) {
        return $this->callFilesystem(
            'uv_fs_scandir',
            array(
                $path,
                $this->options['lsFlags']
            ),
            function ($bool, $result = null) use ($path) {
                if($bool !== true) {
                    throw new \RuntimeException('Unable to list the directory');
                } elseif(empty($result)) {
                    return array();
                }
                
                $stream = new ObjectStream();
                $this->processLsContents($path, $result, $stream);
                
                return ObjectStreamSink::promise($stream);
            }
        );
    }
    
    /**
     * @param string  $path
     * @return ObjectStream
     */
    function lsStream($path) {
        $stream = new ObjectStream();
        
        $this->callFilesystem(
            'uv_fs_scandir',
            array(
                $path,
                $this->options['lsFlags']
            ),
            function ($bool, $result = null) use ($path, $stream) {
                if($bool !== true) {
                    $e = new \RuntimeException('Unable to list the directory');
                    
                    $stream->emit('error', array($e));
                    $stream->close();
                    
                    return;
                } elseif(empty($result)) {
                    $stream->close();
                    return;
                }
                
                $this->processLsContents($path, $result, $stream);
            }
        );
        
        return $stream;
    }
    
    protected function processLsContents($basePath, $result, ObjectStream $stream) {
        $promises = array();
        
        foreach($result as $entry) {
            $path = $basePath.\DIRECTORY_SEPARATOR.$entry;
            
            $promises[] = $this->stat($path)->then(function ($stat) use ($path, $stream) {
                $node = array(
                    'path' => $path,
                    'mode' => $stat['mode'],
                    'type' => null
                );
                
                return Filesystem\detectType($this->typeDetectors, $node)
                    ->then(static function (NodeInterface $node) use ($stream) {
                        $stream->write($node);
                    });
            });
        }
        
        Promise\all($promises)->always(static function () use ($stream) {
            $stream->close();
        });
    }
    
    /**
     * @param string  $path
     * @param mixed   $mode  Unused.
     * @return PromiseInterface
     */
    function touch($path, $mode = self::CREATION_MODE) {
        return $this->appendContents($path, '')->then(function () use ($path) {
            return $this->callFilesystem(
                'uv_fs_utime',
                array(
                    $path,
                    \time(),
                    \time()
                ),
                static function ($result) {
                    if($result === false) {
                        throw new \RuntimeException('Unable to touch target');
                    }
                }
            );
        });
    }
    
    /**
     * @param string  $path
     * @param string  $flags
     * @param mixed   $mode
     * @return PromiseInterface
     */
    function open($path, $flags, $mode = self::CREATION_MODE) {
        return $this->callFilesystem(
            'uv_fs_open',
            array(
                $path,
                $this->openFlagResolver->resolve($flags),
                $this->permissionFlagResolver->resolve($mode)
            ),
            function ($fd) {
                if($fd === false) {
                    throw new \RuntimeException('Unable to open file, make sure the file exists and is readable');
                }
                
                $fdint = (int) $fd;
                $this->fileDescriptors[$fdint] = $fd;
                
                return $fdint;
            }
        );
    }
    
    /**
     * @param string  $fileDescriptor
     * @param int     $length
     * @param int     $offset
     * @return PromiseInterface
     * @noinspection PhpUnusedParameterInspection
     */
    function read($fileDescriptor, $length, $offset) {
        $fileDescriptor = (int) $fileDescriptor;
        
        if(empty($this->fileDescriptors[$fileDescriptor])) {
            return Promise\reject((new \RuntimeException('Unknown file descriptor')));
        }
        
        $fd = $this->fileDescriptors[$fileDescriptor];
        
        return $this->callFilesystem(
            'uv_fs_read',
            array(
                $fd,
                $offset,
                $length
            ),
            static function ($fd, $nread, $buffer) {
                return $buffer;
            }
        );
    }
    
    /**
     * @param string  $fileDescriptor
     * @param string  $data
     * @param int     $length  Unused.
     * @param int     $offset
     * @return PromiseInterface
     * @noinspection PhpUnusedParameterInspection
     */
    function write($fileDescriptor, $data, $length, $offset) {
        $fileDescriptor = (int) $fileDescriptor;
        
        if(empty($this->fileDescriptors[$fileDescriptor])) {
            return Promise\reject((new \RuntimeException('Unknown file descriptor')));
        }
        
        $fd = $this->fileDescriptors[$fileDescriptor];
        
        return $this->callFilesystem(
            'uv_fs_write',
            array(
                $fd,
                $data,
                $offset
            ),
            static function ($fd, $result) {
                return $result;
            }
        );
    }
    
    /**
     * @param string  $fileDescriptor
     * @return PromiseInterface
     */
    function close($fileDescriptor) {
        $fileDescriptor = (int) $fileDescriptor;
        
        if(empty($this->fileDescriptors[$fileDescriptor])) {
            return Promise\reject((new \RuntimeException('Unknown file descriptor')));
        }
        
        $fd = $this->fileDescriptors[$fileDescriptor];
        unset($this->fileDescriptors[$fileDescriptor]);
        
        return $this->callFilesystem(
            'uv_fs_close',
            array(
                $fd
            ),
            static function () {
                // NO-OP
            }
        );
    }
    
    /**
     * Reads the entire file.
     *
     * This is an optimization for adapters which can optimize
     * the open -> (seek ->) read -> close sequence into one call.
     *
     * @param string    $path
     * @param int       $offset
     * @param int|null  $length
     * @return PromiseInterface
     */
    function getContents($path, $offset = 0, $length = null) {
        if($length === null) {
            return $this->stat($path)->then(function ($stat) use ($path, $offset) {
                return $this->getContents($path, $offset, $stat['size']);
            });
        }
        
        return $this->open($path, 'r')->then(function ($fd) use ($offset, $length) {
            return $this->read($fd, $length, $offset)->always(function () use ($fd) {
                return $this->close($fd);
            });
        });
    }
    
    /**
     * Writes the given content to the specified file.
     * If the file exists, the file is truncated.
     * If the file does not exist, the file will be created.
     *
     * This is an optimization for adapters which can optimize
     * the open -> write -> close sequence into one call.
     *
     * @param string  $path
     * @param string  $content
     * @return PromiseInterface
     * @see AdapterInterface::appendContents()
     */
    function putContents($path, $content) {
        return $this->open($path, 'ctwb')->then(function ($fd) use ($content) {
            return $this->write($fd, $content, strlen($content), 0)->always(function () use ($fd) {
                return $this->close($fd)->always(function () {
                    return futurePromise($this->loop);
                });
            });
        });
    }
    
    /**
     * Appends the given content to the specified file.
     * If the file does not exist, the file will be created.
     *
     * This is an optimization for adapters which can optimize
     * the open -> write -> close sequence into one call.
     *
     * @param string  $path
     * @param string  $content
     * @return PromiseInterface
     * @see AdapterInterface::putContents()
     */
    function appendContents($path, $content) {
        return $this->open($path, 'cwab')->then(function ($fd) use ($content) {
            return $this->write($fd, $content, \strlen($content), 0)->always(function () use ($fd) {
                return $this->close($fd);
            });
        });
    }
    
    /**
     * @param string  $fromPath
     * @param string  $toPath
     * @return PromiseInterface
     */
    function rename($fromPath, $toPath) {
        return $this->callFilesystem(
            'uv_fs_rename',
            array(
                $fromPath,
                $toPath
            ),
            static function ($result) {
                if($result === false) {
                    throw new \RuntimeException('Unable to rename target');
                }
            }
        );
    }
    
    /**
     * @param string  $path
     * @return PromiseInterface
     */
    function readlink($path) {
        return $this->callFilesystem(
            'uv_fs_readlink',
            array(
                $path
            ),
            static function ($bool, $result) {
                if($bool === false) {
                    throw new \RuntimeException('Unable to read link of target');
                }
                
                return $result;
            }
        );
    }
    
    /**
     * @param string  $fromPath
     * @param string  $toPath
     * @return PromiseInterface
     */
    function symlink($fromPath, $toPath) {
        return $this->callFilesystem(
            'uv_fs_symlink',
            array(
                $fromPath,
                $toPath,
                $this->options['symlinkFlags']
            ),
            static function ($result) {
                if($result === false) {
                    throw new \RuntimeException('Unable to create a symlink for the target');
                }
            }
        );
    }
    
    /**
     * @inheritDoc
     */
    function detectType($path) {
        return Filesystem\detectType(
            $this->typeDetectors,
            array(
                'path' => $path,
            )
        );
    }
    
    /**
     * Registers work and possibly the timer.
     * @return void
     */
    protected function register() {
        if($this->workCounter++ === 0) {
            $this->workTimer = $this->loop->addTimer($this->workInterval, static function () {});
        }
    }
    
    /**
     * Unregisters work and possibly the timer.
     * @return void
     */
    protected function unregister() {
        if(--$this->workCounter <= 0) {
            $this->loop->cancelTimer($this->workTimer);
        }
    }
}