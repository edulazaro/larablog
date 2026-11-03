<?php

namespace EduLazaro\Larablog\Conditionals;

/**
 * Resolve cached conditional templates on each visit, including scheduled publication.
 */
final class PublishedContent
{
    private const MARKERS = '/(<!--larablog:(?:if-published:[A-Za-z0-9_.-]+:[01]|endif)-->)/';

    public static function resolve(string $html, callable $isPublished): string
    {
        if (! str_contains($html, '<!--larablog:if-published:')) {
            return $html;
        }

        $stack = [];
        $visibility = [];
        $result = '';
        foreach (preg_split(self::MARKERS, $html, -1, PREG_SPLIT_DELIM_CAPTURE) as $part) {
            if (preg_match('/^<!--larablog:if-published:([A-Za-z0-9_.-]+):([01])-->$/', $part, $match)) {
                $stack[] = ($stack === [] || end($stack)) && $match[2] === '1' && ($visibility[$match[1]] ??= $isPublished($match[1]));
            } elseif ($part === '<!--larablog:endif-->') {
                array_pop($stack);
            } elseif ($stack === [] || end($stack)) {
                $result .= $part;
            }
        }

        // Conditional list items may have removed every child of their list.
        return (string) preg_replace('/<(ul|ol)\b[^>]*>\s*<\/\1>/i', '', $result);
    }

    /** @return array<int, array{post: string, closed: bool}> */
    public static function references(string $html): array
    {
        preg_match_all('/<!--larablog:if-published:([A-Za-z0-9_.-]+):([01])-->/', $html, $matches, PREG_SET_ORDER);

        return array_map(fn (array $match) => ['post' => $match[1], 'closed' => $match[2] === '1'], $matches);
    }
}
