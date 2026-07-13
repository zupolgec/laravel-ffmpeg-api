<?php

declare(strict_types=1);

namespace Zupolgec\FFMpegApi;

use FFMpeg\Driver\FFMpegDriver;

/**
 * Per-call overrides of the ffmpeg-api settings. Every ffmpeg command issued
 * inside the callback (through the FFMpeg facade or php-ffmpeg directly) sees
 * the overridden settings; everything outside keeps the configured defaults.
 *
 * FFMpegApi::remote(fn () => FFMpeg::open(...)->export()->save(...));
 * FFMpegApi::local(fn () => ...);
 * FFMpegApi::on('nvidia', fn () => ...);
 * FFMpegApi::using(['wait_timeout' => 7200], fn () => ...);
 */
class FFMpegApi
{
    /**
     * Run $callback with the given settings applied (driver, fallback_to_local,
     * machine, wait_timeout). A no-op when the remote driver is not installed.
     *
     * @param  array<string, mixed>  $settings
     */
    public static function using(array $settings, callable $callback): mixed
    {
        $driver = app(FFMpegDriver::class);

        if (! $driver instanceof FFMpegApiDriver) {
            return $callback();
        }

        return $driver->withSettings($settings, $callback);
    }

    /**
     * Force every command in $callback to the API. By default a remote failure
     * throws RemoteExecutionException rather than silently re-running the
     * command on the local binary, which is what you want when the recipe is
     * remote-only (e.g. an NVENC command on a machine without an NVIDIA GPU).
     */
    public static function remote(callable $callback, ?string $machine = null, bool $fallbackToLocal = false): mixed
    {
        return static::using([
            'driver' => 'remote',
            'fallback_to_local' => $fallbackToLocal,
            'machine' => $machine,
        ], $callback);
    }

    /**
     * Force every command in $callback to the local binary.
     */
    public static function local(callable $callback): mixed
    {
        return static::using(['driver' => 'local'], $callback);
    }

    /**
     * Pin the worker pool ('cpu' or 'nvidia') without changing the driver mode.
     */
    public static function on(string $machine, callable $callback): mixed
    {
        return static::using(['machine' => $machine], $callback);
    }
}
