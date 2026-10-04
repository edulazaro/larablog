<?php

namespace EduLazaro\Larablog;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * Everything a collection holds on disk, read once and cached until a file changes.
 *
 * The cache key is a signature of every file's path and modification time, so publishing
 * is saving a file: no command to run and nothing stale to clear. Problems (a post with no
 * title, a slug used twice in one language, a category nobody defined) are collected rather
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
     * @return array{posts: array<int, array<string, mixed>>, authors: array<string, mixed>, categories: array<string, mixed>, errors: array<int, array{file: string, message: string}>}
     */
    public function load(): array
    {
        if (! config('larablog.cache.enabled')) {
            return $this->build();
        }

        return $this->cache->rememberForever("larablog:index:{$this->name}:" . $this->signature(), fn () => $this->build());
    }

    /**
     * @return string
     */
    private function signature(): string
    {
        $parts = [implode(',', $this->locales)];

        foreach ($this->files() as $file) {
            $parts[] = $file . ':' . (string) @filemtime($file);
        }

        foreach (['_authors.yml', '_categories.yml'] as $shared) {
            $parts[] = $shared . ':' . (string) @filemtime($this->path . '/' . $shared);
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
     * @return array{posts: array<int, array<string, mixed>>, authors: array<string, mixed>, categories: array<string, mixed>, errors: array<int, array{file: string, message: string}>}
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

        return ['posts' => $posts, 'authors' => $authors, 'categories' => $categories, 'errors' => $errors];
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
