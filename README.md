# ReactPHP Libuv Filesystem [![CircleCI](https://circleci.com/gh/AndromedaGalaxy/reactphp-libuv-filesystem.svg?style=svg)](https://circleci.com/gh/AndromedaGalaxy/reactphp-libuv-filesystem)

This library provides libuv filesystem for ReactPHP. `react/filesystem` v0.2 (currently not released, as such `dev-master as v0.2.0`) is required.

# Example Filesystem Adapter

```php
use Andromeda\LibuvFS\Adapter;
use React\EventLoop\ExtUvLoop;
use React\Filesystem\Filesystem;

$loop = new ExtUvLoop();

$adapter = new Adapter($loop);
$filesystem = Filesystem::createFromAdapter($adapter);

// filesystem code...

$loop->run();
```

# Example Watcher

Each time a change is detected in the watched directory or on the watched file, a `change` event will be emitted.
The sole argument received is the filename, which can be null depending on the platform and libuv version.

The filename is relative to the watched path and may be an empty string, if changes on the watched path
directly is detected by the underlying backend.

What has been exactly changed, must be detected by the user.

This library won't keep the event loop running by itself, as filesystem watching is not of active interest by default.

```php
use Andromeda\LibuvFS\Watcher;
use React\EventLoop\ExtUvLoop;

$loop = new ExtUvLoop();
$watcher = new Watcher(__DIR__, $loop);

$watcher->on('change', static function (?string $name) {
    echo 'Change detected: '.($name !== null ? $name : '-null-').PHP_EOL;
});

// keep the event loop running
$loop->addTimer(PHP_INT_MAX, static function () {});

$loop->run();
```

# Install

Install this library through composer using
```
composer require andromeda/react-libuv-filesystem
```
