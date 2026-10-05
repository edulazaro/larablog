<?php

namespace EduLazaro\Larablog\Tests\Feature;

use EduLazaro\Larablog\Larablog;
use EduLazaro\Larablog\Tests\TestCase;

/**
 * Posts, languages and the questions a site asks of them.
 */
class BlogTest extends TestCase
{
    public function test_each_language_lists_its_own_published_posts_newest_first(): void
    {
        $blog = Larablog::collection('blog');

        $this->assertSame(['glossaries-that-are-obeyed', 'chat-moderation-games'], array_map(fn ($p) => $p->slug, $blog->posts('en')));
        $this->assertSame(['moderacion-chat-videojuegos'], array_map(fn ($p) => $p->slug, $blog->posts('es')));
    }

    public function test_drafts_and_future_posts_stay_hidden_unless_previewing(): void
    {
        $blog = Larablog::collection('blog');

        $this->assertNull($blog->find('half-written', 'en'));
        $this->assertNull($blog->find('from-the-future', 'en'));

        config(['larablog.show_scheduled' => true, 'larablog.show_drafts' => true]);

        $this->assertNotNull($blog->find('half-written', 'en'));
        $this->assertNotNull($blog->find('from-the-future', 'en'));
    }

    /**
     * The flaw this package exists to remove: one post, a different URL per language,
     * linked to each other.
     */
    public function test_every_language_has_its_own_url_and_they_point_at_each_other(): void
    {
        $post = Larablog::collection('blog')->find('chat-moderation-games', 'en');

        $this->assertSame('http://localhost/blog/chat-moderation-games', $post->url());
        $this->assertSame([
            'en' => 'http://localhost/blog/chat-moderation-games',
            'es' => 'http://localhost/es/blog/moderacion-chat-videojuegos',
        ], $post->alternates());

        $this->assertSame([], Larablog::collection('blog')->find('glossaries-that-are-obeyed', 'en')->translations());
    }

    public function test_a_post_carries_its_author_category_and_reading_time_in_its_language(): void
    {
        $es = Larablog::collection('blog')->find('moderacion-chat-videojuegos', 'es');

        $this->assertSame('Eduardo Lázaro', $es->author()->name);
        $this->assertSame('Fundador', $es->author()->role);
        $this->assertSame('Ingeniería', $es->category()->name);
        $this->assertSame('ingenieria', $es->category()->slug);
        $this->assertSame('#009e4d', $es->category()->color);
        $this->assertSame(1, $es->readingTime);
    }

    public function test_headings_get_unique_anchors_and_external_links_open_safely(): void
    {
        $post = Larablog::collection('blog')->find('chat-moderation-games', 'en');

        $this->assertSame(['why-it-matters', 'why-it-matters-2', 'written-by-hand', 'a-detail'], array_column($post->headings(), 'id'));
        $this->assertStringContainsString('<h2 id="why-it-matters-2">', $post->html());
        $this->assertMatchesRegularExpression('/<a rel="noopener noreferrer" target="_blank" href="https:\/\/example.org">/', $post->html());
        $this->assertStringContainsString('<a href="http://localhost/pricing">', $post->html());
    }

    public function test_related_posts_share_a_category_or_tags_in_the_same_language(): void
    {
        $blog = Larablog::collection('blog');
        $post = $blog->find('chat-moderation-games', 'en');

        $this->assertSame(['glossaries-that-are-obeyed'], array_map(fn ($p) => $p->slug, $post->related()));
        $this->assertSame([], $blog->find('moderacion-chat-videojuegos', 'es')->related());
    }

    public function test_tags_and_categories_are_listed_from_the_published_posts(): void
    {
        $blog = Larablog::collection('blog');

        $this->assertSame(['moderation', 'gaming'], array_keys($blog->tags('en')));
        $this->assertSame(2, $blog->tags('en')['moderation']['count']);
        $this->assertSame(['engineering'], array_keys($blog->categories('es')));
    }

    public function test_the_sitemap_lists_every_page_with_its_alternates(): void
    {
        $entries = Larablog::collection('blog')->sitemap();
        $locs = array_column($entries, 'loc');

        $this->assertContains('http://localhost/blog', $locs);
        $this->assertContains('http://localhost/es/blog', $locs);
        $this->assertContains('http://localhost/es/blog/moderacion-chat-videojuegos', $locs);
        $this->assertNotContains('http://localhost/blog/from-the-future', $locs);

        $spanish = collect($entries)->firstWhere('loc', 'http://localhost/es/blog/moderacion-chat-videojuegos');
        $this->assertSame('http://localhost/blog/chat-moderation-games', $spanish['alternates']['en']);
    }

    public function test_problems_are_collected_without_taking_the_blog_down(): void
    {
        $messages = array_column(Larablog::collection('blog')->errors(), 'message', 'file');

        $this->assertSame('It has no title.', $messages['broken/en.md']);
        $this->assertStringContainsString('already used in es', $messages['duplicate/es.md']);
    }

    public function test_an_edited_file_is_seen_without_clearing_anything(): void
    {
        $file = __DIR__ . '/../fixtures/blog/glossaries/en.md';
        $original = file_get_contents($file);

        try {
            $this->assertNotNull(Larablog::collection('blog')->find('glossaries-that-are-obeyed', 'en'));

            file_put_contents($file, str_replace('title: "Glossaries that are obeyed"', "title: \"Glossaries that are obeyed\"\nslug: obeyed-glossaries", $original));
            touch($file, time() + 5);
            $this->app->forgetScopedInstances();

            $this->assertNotNull(Larablog::collection('blog')->find('obeyed-glossaries', 'en'));
        } finally {
            file_put_contents($file, $original);
        }
    }

    /**
     * A link written by folder follows the reader's language, and falls back to the
     * language the post exists in rather than to a 404.
     */
    public function test_a_post_link_by_folder_resolves_in_the_readers_language(): void
    {
        $blog = Larablog::collection('blog');

        $this->assertStringContainsString(
            '<a href="http://localhost/blog/chat-moderation-games?ref=glossaries">',
            $blog->find('glossaries-that-are-obeyed', 'en')->html(),
        );
        $this->assertStringContainsString('<a href="#">a post that is gone</a>', $blog->find('glossaries-that-are-obeyed', 'en')->html());
        $this->assertStringContainsString(
            '<a href="http://localhost/blog/glossaries-that-are-obeyed#detail">',
            $blog->find('moderacion-chat-videojuegos', 'es')->html(),
            'Not translated: the English one, not nothing.',
        );
        $this->assertSame('http://localhost/es/blog/moderacion-chat-videojuegos', $blog->linkTo('chat-moderation', 'es'));
        $this->assertSame(['nowhere'], $blog->brokenLinks($blog->find('glossaries-that-are-obeyed', 'en')));
    }

    /**
     * A link to a post that exists and is not out yet is its words alone until the day it is,
     * so a published post can point at a scheduled one with no dead link in between.
     */
    public function test_a_link_to_a_scheduled_post_is_plain_text_until_it_is_out(): void
    {
        $blog = Larablog::collection('blog');
        $post = $blog->find('glossaries-that-are-obeyed', 'en');

        $this->assertStringContainsString('Next year, the future one.', $post->html());
        $this->assertNotContains('future', $blog->brokenLinks($post));
        $this->assertSame('2099-01-01', $blog->pendingLinks($post)['future']->toDateString());

        $this->travelTo('2099-01-02');

        $this->assertStringContainsString('the future one</a>', Larablog::collection('blog')->find('glossaries-that-are-obeyed', 'en')->html());
    }

    public function test_a_category_keeps_the_fields_it_has_no_property_for(): void
    {
        $blog = Larablog::collection('blog');

        $this->assertSame('#00c060', $blog->category('engineering', 'en')->meta('accent'));
        $this->assertSame('verde', $blog->category('engineering', 'es')->meta('tone'));
        $this->assertSame('green', $blog->category('engineering', 'en')->meta('tone'));
        $this->assertNull($blog->category('product', 'en')->meta('accent'));
    }
}
