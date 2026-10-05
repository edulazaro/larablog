<?php

namespace EduLazaro\Larablog\Tests\Feature;

use EduLazaro\Laracards\Card;
use EduLazaro\Larablog\Cards\PostCards;
use EduLazaro\Larablog\Tests\TestCase;

/**
 * The check command and the social cards.
 */
class ToolsTest extends TestCase
{
    public function test_check_reports_errors_and_missing_translations_and_fails(): void
    {
        $this->artisan('larablog:check')
            ->expectsOutputToContain('broken/en.md: It has no title.')
            ->expectsOutputToContain('glossaries: no es version.')
            ->expectsOutputToContain('glossaries/en: links to post:nowhere, which does not exist.')
            ->doesntExpectOutputToContain('post:future, which')
            ->assertExitCode(1);
    }

    /**
     * A link to a scheduled post is not an error, and `--pending` says when it becomes one.
     */
    public function test_check_lists_links_waiting_for_their_post(): void
    {
        $this->artisan('larablog:check', ['--pending' => true])
            ->expectsOutputToContain('glossaries/en: post:future becomes a link on 2099-01-01.');
    }

    public function test_one_card_per_post_and_language_named_after_its_slug(): void
    {
        $cards = iterator_to_array((new PostCards())->cards(), false);
        $outputs = array_map(fn (Card $card) => $card->outputPath(), $cards);

        $this->assertContains(public_path('img/blog/en/chat-moderation-games.png'), $outputs);
        $this->assertContains(public_path('img/blog/es/moderacion-chat-videojuegos.png'), $outputs);
        $this->assertContains(public_path('img/blog/en/from-the-future.png'), $outputs, 'scheduled posts get their card ahead of time');
        $this->assertNotContains(public_path('img/blog/en/half-written.png'), $outputs);

        $spanish = collect($cards)->first(fn (Card $card) => str_ends_with($card->outputPath(), 'moderacion-chat-videojuegos.png'));
        $this->assertSame('INGENIERÍA', $spanish->payload()['category_label']);
        $this->assertSame('1 de septiembre de 2026', $spanish->payload()['date_formatted']);
        $this->assertSame('#00c060', $spanish->payload()['accent'], 'The category\'s accent wins over its colour on a card.');
    }

    public function test_the_cards_are_registered_with_laracards(): void
    {
        $this->assertSame(PostCards::class, config('laracards.sources.larablog'));
    }

    public function test_no_cards_when_a_collection_turns_them_off(): void
    {
        config(['larablog.collections.blog.cards.enabled' => false]);

        $this->assertSame([], iterator_to_array((new PostCards())->cards(), false));
    }
}
