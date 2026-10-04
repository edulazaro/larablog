<?php

namespace EduLazaro\Larablog;

/**
 * Who wrote a post, from the collection's `_authors.yml`, in the post's language.
 */
final class Author
{
    /**
     * @param string $key
     * @param string $name
     * @param string|null $role
     * @param string|null $bio
     * @param string|null $avatar
     * @param array<string, string> $links Name => URL (website, github, linkedin…).
     */
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly ?string $role = null,
        public readonly ?string $bio = null,
        public readonly ?string $avatar = null,
        public readonly array $links = [],
    ) {
    }
}
