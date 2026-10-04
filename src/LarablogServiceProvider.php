<?php

namespace EduLazaro\Larablog;

use EduLazaro\Larablog\Cards\PostCards;
use EduLazaro\Larablog\Console\CheckCommand;
use Illuminate\Support\ServiceProvider;

class LarablogServiceProvider extends ServiceProvider
{
    /**
     * @return void
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/larablog.php', 'larablog');

        // Scoped: the collections memoise their posts, and a queue worker would otherwise
        // keep yesterday's list for as long as it runs.
        $this->app->scoped(Larablog::class);
        $this->app->singleton(Markdown::class, fn ($app) => new Markdown($app['cache']->store(config('larablog.cache.store'))));
    }

    /**
     * @return void
     */
    public function boot(): void
    {
        $this->publishes([__DIR__ . '/../config/larablog.php' => config_path('larablog.php')], 'larablog-config');

        // The posts' cards are drawn by Laracards' own command, with the rest of the site's.
        config(['laracards.sources' => ['larablog' => PostCards::class] + (array) config('laracards.sources', [])]);

        if ($this->app->runningInConsole()) {
            $this->commands([CheckCommand::class]);
        }
    }
}
