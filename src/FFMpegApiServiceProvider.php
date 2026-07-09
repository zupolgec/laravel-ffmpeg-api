<?php

declare(strict_types=1);

namespace Zupolgec\FFMpegApi;

use FFMpeg\Driver\FFMpegDriver;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;
use Zupolgec\FFMpegApi\Http\ApiClient;

class FFMpegApiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/ffmpeg-api.php', 'ffmpeg-api');

        $this->app->singleton(ApiClient::class, function (Container $app): ApiClient {
            $config = $app['config']->get('ffmpeg-api');

            return new ApiClient(
                $app->make(HttpFactory::class),
                (string) ($config['endpoint'] ?? ''),
                (string) ($config['key'] ?? ''),
                (int) ($config['wait_timeout'] ?? 1800),
                (int) ($config['connect_timeout'] ?? 10),
                (int) ($config['poll_interval_ms'] ?? 1000),
            );
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/ffmpeg-api.php' => config_path('ffmpeg-api.php'),
            ], 'ffmpeg-api-config');
        }

        // Rebind the driver AFTER laravel-ffmpeg's provider has registered its
        // own binding (register order across packages is not guaranteed, but by
        // boot() every register() has run). FFMpeg::class resolves the driver
        // lazily on first use, so it will receive ours.
        $this->app->singleton(FFMpegDriver::class, function (Container $app): FFMpegDriver {
            /** @var FFMpegApiDriver $driver */
            $driver = FFMpegApiDriver::create(
                $app->make('laravel-ffmpeg-logger'),
                $app->make('laravel-ffmpeg-configuration'),
            );

            $driver->configureRemote(
                $app->make(ApiClient::class),
                $app['config']->get('ffmpeg-api'),
                $app->make('laravel-ffmpeg-logger'),
            );

            return $driver;
        });
    }
}
