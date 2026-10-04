<?php

namespace EduLazaro\Larablog;

use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

/**
 * One collection: its posts in every language, and every question a site asks of them.
 */
final class Blog
{
    /** @var array{posts: array<int, array<string, mixed>>, authors: array<string, mixed>, categories: array<string, mixed>, errors: array<int, array{file: string, message: string}>}|null */
    private ?array $index = null;

    /** @var array<int, Post>|null */
    private ?array $posts = null;

    /**
     * @param string $name
     */
    public function __construct(public readonly string $name)
    {
    }

    /**
     * The collection's setting, read live: the collection is built at boot, when the
     * routes are registered, and a copy taken then would never see a later change.
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function config(string $key, mixed $default = null): mixed
    {
        return data_get(config("larablog.collections.{$this->name}", []), $key, $default);
    }

    /**
     * The languages the collection is written in, the default first.
     *
     * @return array<int, string>
     */
    public function locales(): array
    {
        $locales = $this->config('locales')
            ?? (class_exists(\EduLazaro\Laralang\LocalizedRoute::class) ? config('locales.locales') : null)
            ?? [config('app.locale')];

        $default = config('app.locale');
        $locales = array_values(array_unique(array_map('strval', (array) $locales)));

        return in_array($default, $locales, true) ? array_values(array_unique([$default, ...$locales])) : $locales;
    }

    /**
     * Published posts in one language, newest first.
     *
     * @param string|null $locale Null for the app's.
     * @return array<int, Post>
     */
    public function posts(?string $locale = null): array
    {
        $locale ??= app()->getLocale();

        return array_values(array_filter($this->all(), fn (Post $post) => $post->locale === $locale && $post->isPublished()));
    }

    /**
     * Every post in every language, drafts and scheduled ones included.
     *
     * @return array<int, Post>
     */
    public function all(): array
    {
        return $this->posts ??= array_map(fn (array $row) => new Post(
            collection: $this->name,
            id: $row['id'],
            locale: $row['locale'],
            slug: $row['slug'],
            title: $row['title'],
            description: $row['description'],
            date: CarbonImmutable::parse($row['date']),
            updated: $row['updated'] ? CarbonImmutable::parse($row['updated']) : null,
            category: $row['category'],
            tags: $row['tags'],
            author: $row['author'],
            image: $row['image'],
            imageAlt: $row['image_alt'],
            featured: $row['featured'],
            draft: $row['draft'],
            readingTime: $row['reading_time'],
            meta: $row['meta'],
            file: $row['file'],
        ), $this->index()['posts']);
    }

    /**
     * @param string $slug
     * @param string|null $locale
     * @return Post|null
     */
    public function find(string $slug, ?string $locale = null): ?Post
    {
        $locale ??= app()->getLocale();

        foreach ($this->posts($locale) as $post) {
            if ($post->slug === $slug) {
                return $post;
            }
        }

        return null;
    }

    /**
     * A published post whose slug this is in ANY language, for a link that arrived with
     * the wrong language's slug.
     *
     * @param string $slug
     * @return Post|null
     */
    public function findInAnyLocale(string $slug): ?Post
    {
        foreach ($this->all() as $post) {
            if ($post->slug === $slug && $post->isPublished()) {
                return $post;
            }
        }

        return null;
    }

    /**
     * @param Post $post
     * @param bool $includeSelf
     * @return array<string, Post> Language => post, in the collection's language order.
     */
    public function translationsOf(Post $post, bool $includeSelf = false): array
    {
        $found = [];

        foreach ($this->locales() as $locale) {
            if (! $includeSelf && $locale === $post->locale) {
                continue;
            }

            foreach ($this->all() as $candidate) {
                if ($candidate->id === $post->id && $candidate->locale === $locale && $candidate->isPublished()) {
                    $found[$locale] = $candidate;
                }
            }
        }

        return $found;
    }

    /**
     * The URL a `post:{id}` link should take from a page in this language: the post in this
     * language, or, when it is not translated, in the first language it is published in.
     *
     * A link written by folder rather than by slug follows the reader's language and
     * survives a slug being changed, which a hand-written `/es/blog/...` does not.
     *
     * @param string $id The post's folder name.
     * @param string $locale
     * @return string|null Null when no published post has that folder.
     */
    public function linkTo(string $id, string $locale): ?string
    {
        $published = array_filter($this->all(), fn (Post $post) => $post->id === $id && $post->isPublished());

        foreach ([$locale, ...$this->locales()] as $wanted) {
            foreach ($published as $post) {
                if ($post->locale === $wanted) {
                    return $post->url();
                }
            }
        }

        return null;
    }

    /**
     * Rewrites every `href="post:{id}"` (optionally with `#anchor` or `?query`) to the URL of
     * that post in the given language. A link to a post that does not exist, or is not
     * published, points at `#` rather than at a scheme no browser knows; `larablog:check`
     * reports it.
     *
     * Done on the rendered HTML, after the cache, because the target can change without this
     * file changing: a translation added, a slug renamed.
     *
     * @param string $html
     * @param string $locale
     * @return string
     */
    public function resolveLinks(string $html, string $locale): string
    {
        if (! str_contains($html, 'href="post:')) {
            return $html;
        }

        return (string) preg_replace_callback('/href="post:([A-Za-z0-9_.-]+)([#?][^"]*)?"/', function (array $m) use ($locale) {
            $url = $this->linkTo($m[1], $locale);

            return 'href="' . ($url === null ? '#' : e($url) . ($m[2] ?? '')) . '"';
        }, $html);
    }

    /**
     * Every `post:{id}` link in a post's body that leads nowhere published.
     *
     * @param Post $post
     * @return array<int, string> The ids.
     */
    public function brokenLinks(Post $post): array
    {
        preg_match_all('/\]\(post:([A-Za-z0-9_.-]+)|href="post:([A-Za-z0-9_.-]+)/', (string) @file_get_contents($post->file), $m);

        $ids = array_unique(array_filter([...$m[1], ...$m[2]]));

        return array_values(array_filter($ids, fn (string $id) => $this->linkTo($id, $post->locale) === null));
    }

    /**
     * @param string $key
     * @param string|null $locale
     * @return Category|null
     */
    public function category(string $key, ?string $locale = null): ?Category
    {
        $locale ??= app()->getLocale();
        $definition = $this->index()['categories'][$key] ?? null;

        if ($definition === null) {
            return null;
        }

        $name = is_array($definition)
            ? (string) (self::localized($definition['name'] ?? $definition, $locale) ?? Str::headline($key))
            : (string) $definition;

        $slug = is_array($definition) && isset($definition['slug'])
            ? (string) self::localized($definition['slug'], $locale)
            : Str::slug($name);

        return new Category(
            collection: $this->name,
            key: $key,
            locale: $locale,
            name: $name,
            slug: $slug ?: Str::slug($key),
            description: is_array($definition) ? self::localized($definition['description'] ?? null, $locale) : null,
            color: is_array($definition) ? ($definition['color'] ?? null) : null,
            meta: is_array($definition) ? array_diff_key($definition, array_flip(['name', 'slug', 'description', 'color'])) : [],
        );
    }

    /**
     * Every category with at least one published post in the language.
     *
     * @param string|null $locale
     * @return array<string, Category>
     */
    public function categories(?string $locale = null): array
    {
        $locale ??= app()->getLocale();
        $used = array_unique(array_filter(array_map(fn (Post $post) => $post->category, $this->posts($locale))));
        $categories = [];

        foreach (array_keys($this->index()['categories']) as $key) {
            if (in_array($key, $used, true) && ($category = $this->category((string) $key, $locale))) {
                $categories[$key] = $category;
            }
        }

        return $categories;
    }

    /**
     * @param string $slug
     * @param string|null $locale
     * @return Category|null
     */
    public function categoryBySlug(string $slug, ?string $locale = null): ?Category
    {
        $locale ??= app()->getLocale();

        foreach (array_keys($this->index()['categories']) as $key) {
            $category = $this->category((string) $key, $locale);

            if ($category && $category->slug === $slug) {
                return $category;
            }
        }

        return null;
    }

    /**
     * @param Category $category
     * @return array<int, Post>
     */
    public function inCategory(Category $category): array
    {
        return array_values(array_filter($this->posts($category->locale), fn (Post $post) => $post->category === $category->key));
    }

    /**
     * The tags of the language's published posts: slug => name, with how many use each.
     *
     * @param string|null $locale
     * @return array<string, array{name: string, count: int}>
     */
    public function tags(?string $locale = null): array
    {
        $tags = [];

        foreach ($this->posts($locale) as $post) {
            foreach ($post->tags as $tag) {
                $slug = Str::slug($tag);
                $tags[$slug] ??= ['name' => $tag, 'count' => 0];
                $tags[$slug]['count']++;
            }
        }

        uasort($tags, fn (array $a, array $b) => $b['count'] <=> $a['count'] ?: strcmp($a['name'], $b['name']));

        return $tags;
    }

    /**
     * @param string $slug
     * @param string|null $locale
     * @return array<int, Post>
     */
    public function withTag(string $slug, ?string $locale = null): array
    {
        return array_values(array_filter($this->posts($locale), fn (Post $post) => in_array($slug, array_map([Str::class, 'slug'], $post->tags), true)));
    }

    /**
     * @param string $key
     * @param string|null $locale
     * @return Author|null
     */
    public function author(string $key, ?string $locale = null): ?Author
    {
        $locale ??= app()->getLocale();
        $data = $this->index()['authors'][$key] ?? null;

        if (! is_array($data)) {
            return $data === null ? new Author($key, $key) : new Author($key, (string) $data);
        }

        return new Author(
            key: $key,
            name: (string) ($data['name'] ?? $key),
            role: self::localized($data['role'] ?? null, $locale),
            bio: self::localized($data['bio'] ?? null, $locale),
            avatar: $data['avatar'] ?? null,
            links: array_map('strval', (array) ($data['links'] ?? [])),
        );
    }

    /**
     * Posts in the same language that share the category or tags, the closest first.
     *
     * @param Post $post
     * @param int $limit
     * @return array<int, Post>
     */
    public function related(Post $post, int $limit = 3): array
    {
        $scored = [];

        foreach ($this->posts($post->locale) as $candidate) {
            if ($candidate->id === $post->id) {
                continue;
            }

            $score = ($post->category && $candidate->category === $post->category ? 3 : 0)
                + count(array_intersect($post->tags, $candidate->tags));

            if ($score > 0) {
                $scored[] = [$score, $candidate];
            }
        }

        usort($scored, fn (array $a, array $b) => $b[0] <=> $a[0] ?: $b[1]->date <=> $a[1]->date);

        return array_slice(array_column($scored, 1), 0, $limit);
    }

    /**
     * @param array<int, Post> $posts
     * @param int|null $page
     * @return LengthAwarePaginator<int, Post>
     */
    public function paginate(array $posts, ?int $page = null): LengthAwarePaginator
    {
        $perPage = max(1, (int) $this->config('per_page', 12));
        $page ??= max(1, (int) request()->query('page', 1));

        return new LengthAwarePaginator(
            array_slice($posts, ($page - 1) * $perPage, $perPage),
            count($posts),
            $perPage,
            $page,
            ['path' => request()->url(), 'pageName' => 'page'],
        );
    }

    /**
     * @param string $kind `index`, `show`, `category`, `tag` or `feed`.
     * @param string|null $locale
     * @param array<string, mixed> $parameters
     * @param bool $absolute
     * @return string
     */
    public function url(string $kind, ?string $locale = null, array $parameters = [], bool $absolute = true): string
    {
        return route(self::routeName($this->name, $kind, $locale ?? app()->getLocale()), $parameters, $absolute);
    }

    /**
     * The listing in every language: for the switcher and hreflang on the index.
     *
     * @return array<string, string>
     */
    public function indexAlternates(): array
    {
        $urls = [];

        foreach ($this->locales() as $locale) {
            $urls[$locale] = $this->url('index', $locale);
        }

        return $urls;
    }

    /**
     * A category in every language it has posts in.
     *
     * @param Category $category
     * @return array<string, string>
     */
    public function categoryAlternates(Category $category): array
    {
        $urls = [];

        foreach ($this->locales() as $locale) {
            $translated = $this->category($category->key, $locale);

            if ($translated && $this->inCategory($translated) !== []) {
                $urls[$locale] = $translated->url();
            }
        }

        return $urls;
    }

    /**
     * Every address of the collection for a sitemap, each with its alternates.
     *
     * @return array<int, array{loc: string, lastmod: string|null, alternates: array<string, string>}>
     */
    public function sitemap(): array
    {
        $entries = [];
        $index = $this->indexAlternates();

        foreach ($index as $url) {
            $entries[] = ['loc' => $url, 'lastmod' => null, 'alternates' => $index];
        }

        foreach ($this->locales() as $locale) {
            foreach ($this->posts($locale) as $post) {
                $entries[] = [
                    'loc' => $post->url(),
                    'lastmod' => ($post->updated ?? $post->date)->toAtomString(),
                    'alternates' => $post->alternates(),
                ];
            }
        }

        return $entries;
    }

    /**
     * Problems found reading the files.
     *
     * @return array<int, array{file: string, message: string}>
     */
    public function errors(): array
    {
        return $this->index()['errors'];
    }

    /**
     * @param string $collection
     * @param string $kind
     * @param string $locale
     * @return string
     */
    public static function routeName(string $collection, string $kind, string $locale): string
    {
        return "{$locale}.{$collection}.{$kind}";
    }

    /**
     * A value written once for every language, or per language.
     *
     * @param mixed $value
     * @param string $locale
     * @return string|null
     */
    private static function localized(mixed $value, string $locale): ?string
    {
        if (is_array($value)) {
            $value = $value[$locale] ?? $value[config('app.fallback_locale')] ?? reset($value);
        }

        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    /**
     * @return array{posts: array<int, array<string, mixed>>, authors: array<string, mixed>, categories: array<string, mixed>, errors: array<int, array{file: string, message: string}>}
     */
    private function index(): array
    {
        return $this->index ??= (new Index(
            $this->name,
            rtrim((string) $this->config('path'), '/'),
            $this->locales(),
            cache()->store(config('larablog.cache.store')),
        ))->load();
    }
}
