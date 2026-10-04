<?php

namespace EduLazaro\Larablog\Tests;

use EduLazaro\Laracards\LaracardsServiceProvider;
use EduLazaro\Larablog\Larablog;
use EduLazaro\Larablog\LarablogServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * @param \Illuminate\Foundation\Application $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [LaracardsServiceProvider::class, LarablogServiceProvider::class];
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     * @return void
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('k', 32)));
        $app['config']->set('app.url', 'http://localhost');
        $app['config']->set('app.locale', 'en');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('larablog.collections.blog.path', __DIR__ . '/fixtures/blog');
        $app['config']->set('larablog.collections.blog.locales', ['en', 'es']);
        $app['config']->set('view.paths', [__DIR__ . '/fixtures/views']);
    }

    /**
     * @param \Illuminate\Routing\Router $router
     * @return void
     */
    protected function defineRoutes($router): void
    {
        Larablog::routes('blog');
    }
}
