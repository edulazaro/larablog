<?php

namespace EduLazaro\Larablog;

/**
 * A category in one language: the same `key` everywhere, a name and a slug of its own.
 */
final class Category
{
    /**
     * @param string $collection
     * @param string $key As written in the posts' frontmatter.
     * @param string $locale
     * @param string $name
     * @param string $slug
     * @param string|null $description
     * @param string|null $color
     * @param array<string, mixed> $meta Any other field of its entry in `_categories.yml`.
     */
    public function __construct(
        public readonly string $collection,
        public readonly string $key,
        public readonly string $locale,
        public readonly string $name,
        public readonly string $slug,
        public readonly ?string $description = null,
        public readonly ?string $color = null,
        public readonly array $meta = [],
    ) {
    }

    /**
     * A field of the category's entry that has no property of its own, in this language
     * when it is written per language.
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function meta(string $key, mixed $default = null): mixed
    {
        $value = data_get($this->meta, $key, $default);

        return is_array($value) && array_key_exists($this->locale, $value) ? $value[$this->locale] : $value;
    }

    /**
     * @param bool $absolute
     * @return string
     */
    public function url(bool $absolute = true): string
    {
        return Larablog::collection($this->collection)->url('category', $this->locale, ['category' => $this->slug], $absolute);
    }
}
