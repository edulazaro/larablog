<?php

namespace EduLazaro\Larablog\Tests\Feature;

use EduLazaro\Larablog\Tests\TestCase;

/**
 * The pages, in each language, with their own words.
 */
class RoutesTest extends TestCase
{
    public function test_the_listing_in_each_language(): void
    {
        $this->get('/blog')->assertOk()->assertSee('INDEX en')->assertSee('POST chat-moderation-games')
            ->assertSee('ALT es http://localhost/es/blog');

        $this->get('/es/blog')->assertOk()->assertSee('INDEX es')->assertSee('POST moderacion-chat-videojuegos')
            ->assertDontSee('chat-moderation-games');
    }

    public function test_a_post_in_each_language(): void
    {
        $this->get('/blog/chat-moderation-games')->assertOk()->assertSee('SHOW Chat moderation for games')
            ->assertSee('ALT es http://localhost/es/blog/moderacion-chat-videojuegos')
            ->assertSee('RELATED glossaries-that-are-obeyed');

        $this->get('/es/blog/moderacion-chat-videojuegos')->assertOk()->assertSee('SHOW Moderación de chat en videojuegos');
    }

    /**
     * A link shared from one language and opened in another lands on the right address.
     */
    public function test_a_slug_from_another_language_redirects_to_this_languages_post(): void
    {
        $this->get('/es/blog/chat-moderation-games')->assertStatus(301)->assertRedirect('http://localhost/es/blog/moderacion-chat-videojuegos');
        $this->get('/blog/moderacion-chat-videojuegos')->assertStatus(301)->assertRedirect('http://localhost/blog/chat-moderation-games');
    }

    public function test_a_post_with_no_translation_redirects_to_the_language_it_exists_in(): void
    {
        $this->get('/es/blog/glossaries-that-are-obeyed')->assertStatus(301)->assertRedirect('http://localhost/blog/glossaries-that-are-obeyed');
    }

    public function test_unknown_scheduled_and_draft_posts_are_404(): void
    {
        $this->get('/blog/nothing-here')->assertNotFound();
        $this->get('/blog/from-the-future')->assertNotFound();
        $this->get('/blog/half-written')->assertNotFound();
    }

    public function test_categories_have_translated_words_and_slugs(): void
    {
        $this->get('/blog/category/engineering')->assertOk()->assertSee('INDEX en Engineering')
            ->assertSee('ALT es http://localhost/es/blog/categoria/ingenieria');

        $this->get('/es/blog/categoria/ingenieria')->assertOk()->assertSee('INDEX es Ingeniería');
        $this->get('/es/blog/categoria/producto')->assertNotFound();
    }

    public function test_tags(): void
    {
        $this->get('/blog/tag/moderation')->assertOk()->assertSee('POST glossaries-that-are-obeyed')->assertSee('POST chat-moderation-games');
        $this->get('/es/blog/etiqueta/videojuegos')->assertOk()->assertSee('POST moderacion-chat-videojuegos');
        $this->get('/blog/tag/nothing')->assertNotFound();
    }

    public function test_each_language_has_its_feed(): void
    {
        $en = $this->get('/blog/feed')->assertOk()->assertHeader('Content-Type', 'application/rss+xml; charset=UTF-8')->getContent();

        $this->assertStringContainsString('<link>http://localhost/blog/chat-moderation-games</link>', $en);
        $this->assertStringContainsString('<language>en</language>', $en);
        $this->assertStringNotContainsString('From the future', $en);

        $es = $this->get('/es/blog/feed')->assertOk()->getContent();
        $this->assertStringContainsString('moderacion-chat-videojuegos', $es);
        $this->assertStringContainsString('<category>Ingeniería</category>', $es);
    }

    public function test_the_page_is_rendered_in_its_language(): void
    {
        $this->get('/es/blog');
        $this->assertSame('es', app()->getLocale());
    }
}
