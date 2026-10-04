<?php

namespace EduLazaro\Larablog;

use DOMDocument;

/**
 * The RSS 2.0 feed of one collection in one language.
 */
final class Feed
{
    /**
     * @param Blog $blog
     * @param string $locale
     * @return string
     */
    public static function for(Blog $blog, string $locale): string
    {
        $xml = new DOMDocument('1.0', 'UTF-8');
        $xml->formatOutput = true;

        $rss = $xml->createElement('rss');
        $rss->setAttribute('version', '2.0');
        $rss->setAttribute('xmlns:atom', 'http://www.w3.org/2005/Atom');
        $xml->appendChild($rss);

        $channel = $xml->createElement('channel');
        $rss->appendChild($channel);

        $title = self::localized($blog->config('feed.title'), $locale) ?? config('app.name');
        $self = $blog->url('feed', $locale);

        $channel->appendChild($xml->createElement('title'))->appendChild($xml->createTextNode((string) $title));
        $channel->appendChild($xml->createElement('link'))->appendChild($xml->createTextNode($blog->url('index', $locale)));
        $channel->appendChild($xml->createElement('description'))->appendChild($xml->createTextNode((string) (self::localized($blog->config('feed.description'), $locale) ?? $title)));
        $channel->appendChild($xml->createElement('language'))->appendChild($xml->createTextNode($locale));

        $atom = $xml->createElement('atom:link');
        $atom->setAttribute('href', $self);
        $atom->setAttribute('rel', 'self');
        $atom->setAttribute('type', 'application/rss+xml');
        $channel->appendChild($atom);

        foreach (array_slice($blog->posts($locale), 0, (int) $blog->config('feed.limit', 20)) as $post) {
            $item = $xml->createElement('item');
            $item->appendChild($xml->createElement('title'))->appendChild($xml->createTextNode($post->title));
            $item->appendChild($xml->createElement('link'))->appendChild($xml->createTextNode($post->url()));
            $guid = $xml->createElement('guid');
            $guid->setAttribute('isPermaLink', 'true');
            $guid->appendChild($xml->createTextNode($post->url()));
            $item->appendChild($guid);
            $item->appendChild($xml->createElement('pubDate'))->appendChild($xml->createTextNode($post->date->toRssString()));

            if ($post->description) {
                $item->appendChild($xml->createElement('description'))->appendChild($xml->createTextNode($post->description));
            }

            if ($category = $post->category()) {
                $item->appendChild($xml->createElement('category'))->appendChild($xml->createTextNode($category->name));
            }

            $channel->appendChild($item);
        }

        return (string) $xml->saveXML();
    }

    /**
     * @param mixed $value
     * @param string $locale
     * @return string|null
     */
    private static function localized(mixed $value, string $locale): ?string
    {
        if (is_array($value)) {
            $value = $value[$locale] ?? reset($value);
        }

        return is_string($value) && $value !== '' ? $value : null;
    }
}
