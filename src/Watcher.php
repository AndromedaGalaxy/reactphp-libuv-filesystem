<?php
/**
 * Andromeda
 * Copyright 2020 Andromeda, All Rights Reserved
 *
 * Website: https://github.com/AndromedaGalaxy/reactphp-libuv-filesystem
 * License: https://github.com/AndromedaGalaxy/reactphp-libuv-filesystem/blob/master/LICENSE
 */

namespace Andromeda\LibuvFS;

use Evenement\EventEmitterInterface;
use Evenement\EventEmitterTrait;
use React\EventLoop\ExtUvLoop;

/**
 * Implements a filesystem watcher through libuv.
 * This class won't keep the event loop running by itself.
 *
 * The following event gets emitted:
 *  - `change`
 *    Each time a change is detected in the watched directory or on the watched file,
 *    this event will be emitted. The sole argument received is the filename, which can be null
 *    depending on the platform and libuv version.
 *    The filename is relative to the watched path and may be an empty string, if changes on the
 *    watched path directly is detected by the underlying backend.
 *
 *    What has been exactly changed, must be detected by the user.
 */
class Watcher implements EventEmitterInterface {
    use EventEmitterTrait;
    
    /**
     * @var string
     */
    protected $path;
    
    /**
     * @var mixed
     */
    protected $event;
    
    /**
     * Constructor.
     * @param string     $path
     * @param ExtUvLoop  $loop
     * @noinspection PhpUnusedParameterInspection
     */
    function __construct(string $path, ExtUvLoop $loop) {
        $this->path = \rtrim($path, \DIRECTORY_SEPARATOR);
        
        $dedup = null;
        $dedupTime = null;
        
        /** @noinspection PhpInternalEntityUsedInspection */
        $this->event = \uv_fs_event_init($loop->getUvLoop(), $path, function ($rsc, $name, $event, $stat) use (&$dedup, &$dedupTime) {
            if($name === $dedup && \uv_hrtime() <= ((int) ($dedupTime + 2e6))) {
                // ignore subsequent event that follows an event within 2ms for the same target (i.e. rename -> change)
                return; // @codeCoverageIgnore
            }
            
            $dedup = $name;
            $dedupTime = \uv_hrtime();
            
            $name = ($name !== null ? \trim($name, \DIRECTORY_SEPARATOR) : null);
            $this->emit('change', array($name));
        }, 0);
    }
    
    /**
     * @codeCoverageIgnore
     */
    function __destruct() {
        $this->close();
    }
    
    /**
     * Get the path.
     * @return string
     */
    function getPath(): string {
        return $this->path;
    }
    
    /**
     * Closes the watcher.
     */
    function close(): void {
        if($this->event === null) {
            return;
        }
        
        \uv_close($this->event, static function () {});
        $this->event = null;
        
        $this->removeAllListeners();
    }
}
