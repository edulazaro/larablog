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
     */
    public function __construct(
        public readonly string $collection,
        public readonly string $key,
        public readonly string $locale,
        public readonly string $name,
        public readonly string $slug,
        public readonly ?string $description = null,
        public readonly ?string $color = null,
    ) {
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
