<?php

declare(strict_types=1);

namespace Zupolgec\FFMpegApi\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Zupolgec\FFMpegApi\FFMpegApiServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            \ProtoneMedia\LaravelFFMpeg\Support\ServiceProvider::class,
            FFMpegApiServiceProvider::class,
        ];
    }
}
