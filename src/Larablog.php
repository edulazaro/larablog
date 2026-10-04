<?php

namespace EduLazaro\Larablog;

use EduLazaro\Larablog\Http\BlogController;
use EduLazaro\Larablog\Http\UseLocale;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;

/**
 * The entry point: `Larablog::collection('blog')` and `Larablog::routes('blog')`.
 */
final class Larablog
{
    /** @var array<string, Blog> */
    private array $collections = [];

    /**
     * @param string $name
     * @return Blog
     */
    public static function collection(string $name = 'blog'): Blog
    {
        return app(self::class)->get($name);
    }

    /**
     * @return array<string, Blog>
     */
    public static function collections(): array
    {
        $manager = app(self::class);

        return array_map(fn (string $name) => $manager->get($name), array_combine(
            array_keys((array) config('larablog.collections', [])),
            array_keys((array) config('larablog.collections', [])),
        ));
    }

    /**
     * Registers the collection's pages in every language it is written in.
     *
     * Each language gets its own addresses and its own words: /blog/{slug} and
     * /es/blog/{slug}, /blog/category/x and /es/blog/categoria/x. With Laralang installed the
     * language prefix is Laralang's, so the blog sits under the same prefixes as the rest of
     * the site; without it, the default language is at the root and the rest under their code.
     * Names are `{locale}.{collection}.{index|show|category|tag|feed}`.
     *
     * @param string $name
     * @param array<int, string|class-string> $middleware Added to `web`.
     * @return void
     */
    public static function routes(string $name = 'blog', array $middleware = []): void
    {
        $blog = self::collection($name);

        foreach ($blog->locales() as $locale) {
            $path = trim((string) ($blog->config("paths.{$locale}") ?? $blog->config('paths.' . $blog->locales()[0]) ?? $name), '/');
            $prefix = trim(self::prefix($locale, $blog->locales()[0]) . '/' . $path, '/');
            $category = $blog->config("segments.category.{$locale}", 'category');
            $tag = $blog->config("segments.tag.{$locale}", 'tag');

            Route::middleware(['web', UseLocale::class, ...$middleware])
                ->prefix($prefix)
                ->group(function () use ($name, $locale, $category, $tag) {
                    $defaults = fn ($route) => $route->defaults('larablog', $name)->defaults('larablog_locale', $locale);

                    $defaults(Route::get('/', [BlogController::class, 'index'])->name(Blog::routeName($name, 'index', $locale)));
                    $defaults(Route::get('feed', [BlogController::class, 'feed'])->name(Blog::routeName($name, 'feed', $locale)));
                    $defaults(Route::get("{$category}/{category}", [BlogController::class, 'category'])->where('category', '[a-z0-9-]+')->name(Blog::routeName($name, 'category', $locale)));
                    $defaults(Route::get("{$tag}/{tag}", [BlogController::class, 'tag'])->where('tag', '[a-z0-9-]+')->name(Blog::routeName($name, 'tag', $locale)));
                    $defaults(Route::get('{slug}', [BlogController::class, 'show'])->where('slug', '[a-z0-9-]+')->name(Blog::routeName($name, 'show', $locale)));
                });
        }
    }

    /**
     * @param string $name
     * @return Blog
     */
    public function get(string $name): Blog
    {
        $config = config("larablog.collections.{$name}");

        if (! is_array($config)) {
            throw new InvalidArgumentException("There is no Larablog collection called `{$name}` in config/larablog.php.");
        }

        return $this->collections[$name] ??= new Blog($name);
    }

    /**
     * The language's URL prefix: Laralang's when it is installed, so the blog shares the
     * site's prefixes; otherwise none for the default language and the code for the rest.
     *
     * @param string $locale
     * @param string $default
     * @return string
     */
    private static function prefix(string $locale, string $default): string
    {
        if (class_exists(\EduLazaro\Laralang\Routing\LocalePrefixes::class)) {
            return (string) \EduLazaro\Laralang\Routing\LocalePrefixes::get($locale);
        }

        return $locale === config('app.locale', $default) ? '' : $locale;
    }
}
