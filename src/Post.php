<?php

namespace EduLazaro\Larablog;

use Carbon\CarbonImmutable;

/**
 * One post in one language.
 *
 * `id` is the folder, shared by every translation and never part of a URL; `slug` is this
 * language's own. The body is rendered on first use and cached, so a listing of fifty
 * posts never renders fifty bodies.
 */
final class Post
{
    /** @var array{html: string, headings: array<int, array{level: int, id: string, text: string}>}|null */
    private ?array $rendered = null;

    /**
     * @param string $collection
     * @param string $id
     * @param string $locale
     * @param string $slug
     * @param string $title
     * @param string|null $description
     * @param CarbonImmutable $date
     * @param CarbonImmutable|null $updated
     * @param string|null $category
     * @param array<int, string> $tags
     * @param string|null $author
     * @param string|null $image
     * @param string|null $imageAlt
     * @param bool $featured
     * @param bool $draft
     * @param int $readingTime
     * @param array<string, mixed> $meta Every other frontmatter field, as written.
     * @param string $file
     */
    public function __construct(
        public readonly string $collection,
        public readonly string $id,
        public readonly string $locale,
        public readonly string $slug,
        public readonly string $title,
        public readonly ?string $description,
        public readonly CarbonImmutable $date,
        public readonly ?CarbonImmutable $updated,
        public readonly ?string $category,
        public readonly array $tags,
        public readonly ?string $author,
        public readonly ?string $image,
        public readonly ?string $imageAlt,
        public readonly bool $featured,
        public readonly bool $draft,
        public readonly int $readingTime,
        public readonly array $meta,
        public readonly string $file,
    ) {
    }

    /**
     * @return Blog
     */
    public function blog(): Blog
    {
        return Larablog::collection($this->collection);
    }

    /**
     * Whether the post can be shown: not a draft and not dated in the future, unless the
     * configuration says to preview those.
     *
     * @return bool
     */
    public function isPublished(): bool
    {
        if ($this->draft && ! config('larablog.show_drafts')) {
            return false;
        }

        return ! $this->date->isFuture() || config('larablog.show_scheduled');
    }

    /**
     * @return string
     */
    public function html(): string
    {
        return $this->blog()->resolveLinks($this->render()['html'], $this->locale);
    }

    /**
     * The h2 and h3 of the body, each with the id its anchor carries: an "On this page" index.
     *
     * @return array<int, array{level: int, id: string, text: string}>
     */
    public function headings(): array
    {
        return $this->render()['headings'];
    }

    /**
     * @param bool $absolute
     * @return string
     */
    public function url(bool $absolute = true): string
    {
        return $this->blog()->url('show', $this->locale, ['slug' => $this->slug], $absolute);
    }

    /**
     * The same post in every language it is written in, language => URL, this one included.
     *
     * @return array<string, string>
     */
    public function alternates(): array
    {
        return array_map(fn (Post $post) => $post->url(), $this->translations(true));
    }

    /**
     * @param bool $includeSelf
     * @return array<string, Post>
     */
    public function translations(bool $includeSelf = false): array
    {
        return $this->blog()->translationsOf($this, $includeSelf);
    }

    /**
     * @return Author|null
     */
    public function author(): ?Author
    {
        return $this->author ? $this->blog()->author($this->author, $this->locale) : null;
    }

    /**
     * @return Category|null
     */
    public function category(): ?Category
    {
        return $this->category ? $this->blog()->category($this->category, $this->locale) : null;
    }

    /**
     * @param int $limit
     * @return array<int, Post>
     */
    public function related(int $limit = 3): array
    {
        return $this->blog()->related($this, $limit);
    }

    /**
     * The picture for the post and its social preview: the one the frontmatter names, or
     * the card Laracards drew for it, or null.
     *
     * @return string|null
     */
    public function imageUrl(): ?string
    {
        if ($this->image) {
            return str_starts_with($this->image, 'http') ? $this->image : asset(ltrim($this->image, '/'));
        }

        $card = $this->cardPath();

        return $card && is_file(public_path($card)) ? asset($card) : null;
    }

    /**
     * Where this post's card lives, relative to public/, or null when cards are off.
     *
     * @return string|null
     */
    public function cardPath(): ?string
    {
        $cards = $this->blog()->config('cards', []);

        if (! ($cards['enabled'] ?? false)) {
            return null;
        }

        return trim((string) ($cards['path'] ?? 'img/blog'), '/') . "/{$this->locale}/{$this->slug}.png";
    }

    /**
     * A frontmatter field that has no property of its own.
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function meta(string $key, mixed $default = null): mixed
    {
        return data_get($this->meta, $key, $default);
    }

    /**
     * @return array{html: string, headings: array<int, array{level: int, id: string, text: string}>}
     */
    private function render(): array
    {
        return $this->rendered ??= app(Markdown::class)->render($this->file);
    }
}
