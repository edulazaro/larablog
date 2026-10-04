<?php

namespace EduLazaro\Larablog\Cards;

use EduLazaro\Laracards\Card;
use EduLazaro\Laracards\Contracts\CardSource;
use EduLazaro\Larablog\Larablog;
use EduLazaro\Larablog\Post;

/**
 * One social card per post and language, for every collection with cards on.
 *
 * Registered with Laracards on its own, so `php artisan cards:generate` draws them with the
 * rest. The card is keyed by collection, language and post, and named after the slug of
 * its language, because that is the address a preview is fetched for. Scheduled posts get
 * theirs too, so the card exists the morning the post goes out.
 */
class PostCards implements CardSource
{
    /**
     * @return iterable<Card>
     */
    public function cards(): iterable
    {
        foreach (Larablog::collections() as $blog) {
            $cards = $blog->config('cards', []);

            if (! ($cards['enabled'] ?? false)) {
                continue;
            }

            foreach ($blog->all() as $post) {
                if ($post->draft || ! $post->cardPath()) {
                    continue;
                }

                yield $this->card($post, $cards);
            }
        }
    }

    /**
     * @param Post $post
     * @param array<string, mixed> $cards
     * @return Card
     */
    protected function card(Post $post, array $cards): Card
    {
        $category = $post->category();

        return Card::make("larablog:{$post->collection}:{$post->locale}:{$post->id}")
            ->template((string) ($cards['template'] ?? 'post'))
            ->data([
                'title' => $post->title,
                'description' => (string) $post->description,
                'category_label' => $category ? mb_strtoupper($category->name) : '',
                'accent' => (string) ($category?->meta('accent') ?? $category?->color ?? ''),
                'author_name' => $post->author()?->name ?? '',
                'date_formatted' => $post->date->locale($post->locale)->isoFormat('LL'),
                'reading_time' => (string) $post->readingTime,
                'brand_label' => (string) ($cards['brand'] ?? parse_url((string) config('app.url'), PHP_URL_HOST)),
                'locale' => $post->locale,
            ])
            ->output(public_path($post->cardPath()));
    }
}
