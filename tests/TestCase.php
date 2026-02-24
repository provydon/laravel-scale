<?php

namespace LaravelScale\LaravelScale\Tests;

use LaravelScale\LaravelScale\LaravelScaleServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            LaravelScaleServiceProvider::class,
        ];
    }
}
