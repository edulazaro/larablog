<?php

namespace EduLazaro\Larablog;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * Everything a collection holds on disk, read once and cached until a file changes.
 *
 * A stable cache entry holds the metadata and a locale/slug lookup. File signatures are
 * checked periodically in production; a missing entry is built by the requesting visitor.
 * Problems (a post with no title, a slug used twice in one language, a category nobody
 * defined) are collected rather
 * than thrown, so one bad file never takes the blog down; `larablog:check` reports them.
 */
final class Index
{
    /** Frontmatter keys with a property of their own; everything else stays in `meta`. */
    private const KNOWN = [
        'title', 'slug', 'description', 'excerpt', 'date', 'updated', 'updated_at', 'category',
        'tags', 'author', 'image', 'cover', 'image_alt', 'cover_alt', 'featured', 'draft', 'reading_time',
    ];

    /**
     * @param string $name
     * @param string $path
     * @param array<int, string> $locales
     * @param Repository $cache
     */
    public function __construct(
        private string $name,
        private string $path,
        private array $locales,
        private Repository $cache,
    ) {
    }

    /**
     * @return array{posts: array<int, array<string, mixed>>, lookup: array<string, array<string, int>>, authors: array<string, mixed>, categories: array<string, mixed>, errors: array<int, array{file: string, message: string}>}
     */
    public function load(bool $force = false): array
    {
        if (! config('larablog.cache.enabled')) {
            return $this->build();
        }

        $key = 'larablog:index:v2:' . sha1(serialize([$this->name, $this->path, $this->locales, config('larablog.words_per_minute', 220)]));
        $entry = $this->cache->get($key);

        if (! $force && $this->fresh($entry)) {
            return $entry['index'];
        }

        $refresh = function () use ($key, $force): array {
            $current = $this->cache->get($key);
            if (! $force && $this->fresh($current)) {
                return $current['index'];
            }

            $signature = $this->signature();
            $index = ! $force && $this->valid($current) && $current['signature'] === $signature
                ? $current['index']
                : $this->build();

            $this->cache->forever($key, ['signature' => $signature, 'checked_at' => now()->getTimestamp(), 'index' => $index]);

            return $index;
        };

        $store = $this->cache->getStore();
        if (! $store instanceof LockProvider) {
            return $refresh();
        }

        $lock = $store->lock($key . ':lock', 60);
        if (! $force && $this->valid($entry)) {
            // An existing snapshot can serve visitors while another worker refreshes it.
            return $lock->get($refresh) ?: $entry['index'];
        }

        try {
            return $lock->block(10, $refresh);
        } catch (LockTimeoutException $exception) {
            if ($force) {
                throw $exception;
            }

            $current = $this->cache->get($key);

            return $this->valid($current) ? $current['index'] : $this->build();
        }
    }

    /**
     * @param mixed $entry
     * @return bool
     */
    private function valid(mixed $entry): bool
    {
        return is_array($entry) && isset($entry['signature'], $entry['checked_at'], $entry['index']['lookup']);
    }

    /**
     * @param mixed $entry
     * @return bool
     */
    private function fresh(mixed $entry): bool
    {
        $interval = max(0, (int) (config('larablog.cache.check_interval') ?? (app()->isProduction() ? 60 : 0)));

        return $this->valid($entry) && now()->getTimestamp() - $entry['checked_at'] < $interval;
    }

    /**
     * @return string
     */
    private function signature(): string
    {
        clearstatcache();
        $parts = [implode(',', $this->locales)];

        foreach ($this->files() as $file) {
            $parts[] = $file . ':' . (string) @filemtime($file) . ':' . (string) @filesize($file);
        }

        foreach (['_authors.yml', '_categories.yml'] as $shared) {
            $parts[] = $shared . ':' . (string) @filemtime($this->path . '/' . $shared) . ':' . (string) @filesize($this->path . '/' . $shared);
        }

        return sha1(implode('|', $parts));
    }

    /**
     * @return array<int, string>
     */
    private function files(): array
    {
        $files = [];

        foreach ($this->locales as $locale) {
            foreach (glob($this->path . "/*/{$locale}.md") ?: [] as $file) {
                if (! str_starts_with(basename(dirname($file)), '_')) {
                    $files[] = $file;
                }
            }
        }

        sort($files);

        return $files;
    }

    /**
     * @return array{posts: array<int, array<string, mixed>>, lookup: array<string, array<string, int>>, authors: array<string, mixed>, categories: array<string, mixed>, errors: array<int, array{file: string, message: string}>}
     */
    private function build(): array
    {
        $errors = [];
        $authors = $this->yaml('_authors.yml', $errors);
        $categories = $this->yaml('_categories.yml', $errors);
        $posts = [];
        $slugs = [];

        foreach ($this->files() as $file) {
            $relative = Str::after($file, rtrim($this->path, '/') . '/');

            try {
                [$meta, $body] = Markdown::split((string) file_get_contents($file));
            } catch (Throwable $e) {
                $errors[] = ['file' => $relative, 'message' => $e->getMessage()];

                continue;
            }

            $title = trim((string) ($meta['title'] ?? ''));

            if ($title === '') {
                $errors[] = ['file' => $relative, 'message' => 'It has no title.'];

                continue;
            }

            $locale = basename($file, '.md');
            $slug = (string) ($meta['slug'] ?? Str::slug($title));

            if (! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
                $errors[] = ['file' => $relative, 'message' => "The slug `{$slug}` is not lowercase letters, digits and hyphens."];

                continue;
            }

            if (isset($slugs[$locale][$slug])) {
                $errors[] = ['file' => $relative, 'message' => "The slug `{$slug}` is already used in {$locale} by {$slugs[$locale][$slug]}."];

                continue;
            }

            $slugs[$locale][$slug] = $relative;
            $category = isset($meta['category']) ? (string) $meta['category'] : null;
            $author = isset($meta['author']) ? (string) $meta['author'] : null;

            if ($category !== null && ! isset($categories[$category])) {
                $errors[] = ['file' => $relative, 'message' => "The category `{$category}` is not in _categories.yml."];
            }

            if ($author !== null && $authors !== [] && ! isset($authors[$author])) {
                $errors[] = ['file' => $relative, 'message' => "The author `{$author}` is not in _authors.yml."];
            }

            $words = str_word_count(strip_tags($body));

            $posts[] = [
                'id' => basename(dirname($file)),
                'locale' => $locale,
                'slug' => $slug,
                'title' => $title,
                'description' => self::string($meta['description'] ?? $meta['excerpt'] ?? null),
                'date' => $this->date($meta['date'] ?? null) ?? CarbonImmutable::createFromTimestamp((int) filemtime($file))->toIso8601String(),
                'updated' => $this->date($meta['updated'] ?? $meta['updated_at'] ?? null),
                'category' => $category,
                'tags' => array_values(array_filter(array_map(fn ($tag) => trim((string) $tag), (array) ($meta['tags'] ?? [])))),
                'author' => $author,
                'image' => self::string($meta['image'] ?? $meta['cover'] ?? null),
                'image_alt' => self::string($meta['image_alt'] ?? $meta['cover_alt'] ?? null),
                'featured' => (bool) ($meta['featured'] ?? false),
                'draft' => (bool) ($meta['draft'] ?? false),
                'reading_time' => (int) ($meta['reading_time'] ?? max(1, (int) ceil($words / max(1, (int) config('larablog.words_per_minute', 220))))),
                'meta' => array_diff_key($meta, array_flip(self::KNOWN)),
                'file' => $file,
            ];
        }

        usort($posts, fn (array $a, array $b) => strcmp($b['date'], $a['date']) ?: strcmp($a['id'], $b['id']));

        $lookup = [];
        foreach ($posts as $position => $post) {
            $lookup[$post['locale']][$post['slug']] = $position;
        }

        return ['posts' => $posts, 'lookup' => $lookup, 'authors' => $authors, 'categories' => $categories, 'errors' => $errors];
    }

    /**
     * @param string $file
     * @param array<int, array{file: string, message: string}> $errors
     * @return array<string, mixed>
     */
    private function yaml(string $file, array &$errors): array
    {
        $path = $this->path . '/' . $file;

        if (! is_file($path)) {
            return [];
        }

        try {
            $data = Yaml::parseFile($path);
        } catch (Throwable $e) {
            $errors[] = ['file' => $file, 'message' => 'Not valid YAML: ' . $e->getMessage()];

            return [];
        }

        return is_array($data) ? $data : [];
    }

    /**
     * @param mixed $value
     * @return string|null An ISO 8601 date, or null.
     */
    private function date(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value)->toIso8601String();
        }

        if (is_int($value)) {
            return CarbonImmutable::createFromTimestamp($value)->toIso8601String();
        }

        if (is_string($value) && trim($value) !== '') {
            try {
                return CarbonImmutable::parse($value)->toIso8601String();
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }

    /**
     * @param mixed $value
     * @return string|null
     */
    private static function string(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }
}
