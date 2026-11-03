<?php

namespace EduLazaro\Larablog\Tests\Feature;

use EduLazaro\Larablog\Larablog;
use EduLazaro\Larablog\Markdown;
use EduLazaro\Larablog\Post;
use EduLazaro\Larablog\Tests\TestCase;
use Illuminate\Support\Facades\File;

class ConditionalContentTest extends TestCase
{
    private ?string $content = null;

    protected function tearDown(): void
    {
        if ($this->content !== null) {
            File::deleteDirectory($this->content);
        }

        parent::tearDown();
    }

    public function test_a_list_shows_one_published_item_and_removes_two_pending_items_completely(): void
    {
        $post = $this->article(<<<'MD'
- ::: if-published post="chat-moderation"
  Turnstile with Laravel: [Published article](/blog/chat-moderation-games)
  :::

- ::: if-published post="future"
  Hidden React label: [React article](/react)
  :::

- ::: if-published post="draft"
  Hidden Vue label: [Vue article](/vue)
  :::
MD);

        $html = $post->html();

        $this->assertSame(1, substr_count($html, '<ul>'));
        $this->assertSame(1, substr_count($html, '<li>'));
        $this->assertStringContainsString('Turnstile with Laravel:', $html);
        $this->assertStringContainsString('<a href="/blog/chat-moderation-games">Published article</a>', $html);
        $this->assertStringNotContainsString('Hidden React', $html);
        $this->assertStringNotContainsString('Hidden Vue', $html);
        $this->assertStringNotContainsString('larablog:', $html);
        $this->assertStringNotContainsString(':::', $html);
    }

    public function test_a_list_with_no_published_items_disappears_without_empty_bullets(): void
    {
        $post = $this->article(<<<'MD'
Before.

- ::: if-published post="future"
  Hidden React paragraph.
  :::
- ::: if-published post="draft"
  Hidden Vue paragraph.
  :::

After.
MD);

        $html = $post->html();

        $this->assertStringContainsString('<p>Before.</p>', $html);
        $this->assertStringContainsString('<p>After.</p>', $html);
        $this->assertStringNotContainsString('<ul', $html);
        $this->assertStringNotContainsString('<li', $html);
        $this->assertStringNotContainsString('Hidden', $html);
    }

    public function test_conditionals_support_paragraphs_headings_images_and_multiple_elements(): void
    {
        $post = $this->article(<<<'MD'
## Always visible

::: if-published post="future"
### Hidden heading

Hidden paragraph with **emphasis**.

![Hidden image](/hidden.png)
:::

::: if-published post="chat-moderation"
### Available heading

Available paragraph with **emphasis**.

![Available image](/available.png)
:::
MD);

        $html = $post->html();

        $this->assertStringNotContainsString('Hidden', $html);
        $this->assertStringNotContainsString('/hidden.png', $html);
        $this->assertStringContainsString('<h3 id="available-heading">Available heading</h3>', $html);
        $this->assertStringContainsString('<strong>emphasis</strong>', $html);
        $this->assertStringContainsString('/available.png', $html);
        $this->assertSame(['Always visible', 'Available heading'], array_column($post->headings(), 'text'));
    }

    public function test_publication_reveals_cached_content_and_its_headings_without_editing_or_rebuilding(): void
    {
        $post = $this->article(<<<'MD'
## Permanent heading

::: if-published post="future"
## Scheduled heading

Scheduled paragraph.
:::
MD);
        $markdown = app(Markdown::class);
        $template = $markdown->render($post->file);
        $this->assertStringNotContainsString('Scheduled paragraph', $post->html());
        $this->assertSame(['Permanent heading'], array_column($post->headings(), 'text'));

        $this->travelTo('2099-01-02');

        $this->assertStringContainsString('Scheduled paragraph', $post->html());
        $this->assertSame(['Permanent heading', 'Scheduled heading'], array_column($post->headings(), 'text'));
        $this->assertSame($template, $markdown->render($post->file));
    }

    public function test_nested_conditions_close_the_innermost_block_and_respect_outer_visibility(): void
    {
        $post = $this->article(<<<'MD'
::: if-published post="chat-moderation"
Outer start.

::: if-published post="future"
Hidden inner.
:::

Outer end.
:::

::: if-published post="future"
::: if-published post="chat-moderation"
Hidden by outer condition.
:::
:::

Visible afterwards.
MD);

        $html = $post->html();

        $this->assertStringContainsString('Outer start.', $html);
        $this->assertStringContainsString('Outer end.', $html);
        $this->assertStringContainsString('Visible afterwards.', $html);
        $this->assertStringNotContainsString('Hidden', $html);
        $this->assertSame([true, true, true, true], array_column($post->conditions(), 'closed'));
    }

    public function test_a_conditional_fragment_inside_an_existing_item_keeps_its_other_content(): void
    {
        $post = $this->article(<<<'MD'
- Visible item text.

  ::: if-published post="future"
  Hidden extra paragraph.
  :::

- Another visible item.
MD);

        $html = $post->html();

        $this->assertSame(2, substr_count($html, '<li>'));
        $this->assertStringContainsString('Visible item text.', $html);
        $this->assertStringContainsString('Another visible item.', $html);
        $this->assertStringNotContainsString('Hidden extra', $html);
    }

    public function test_numbered_lists_and_nested_lists_remove_only_the_conditional_items(): void
    {
        $post = $this->article(<<<'MD'
1. Visible first item.
2. ::: if-published post="future"
   Hidden second item.
   :::
3. Visible third item.

- Parent item.
  - ::: if-published post="future"
    Hidden nested item.
    :::
  - Visible nested item.
MD);

        $html = $post->html();

        $this->assertStringContainsString('<ol>', $html);
        $this->assertSame(4, substr_count($html, '<li>'));
        $this->assertStringNotContainsString('Hidden', $html);
        $this->assertStringContainsString('Visible nested item.', $html);
    }

    public function test_fenced_examples_are_literal_and_do_not_create_dependencies(): void
    {
        $post = $this->article(<<<'MD'
```markdown
::: if-published post="missing-example"
Literal example.
:::
```
MD);

        $this->assertStringContainsString('Literal example.', $post->html());
        $this->assertStringContainsString('if-published', $post->html());
        $this->assertSame([], $post->conditions());
        $this->assertSame([], Larablog::collection('blog')->brokenLinks($post));
    }

    public function test_a_closing_marker_inside_a_code_fence_does_not_close_the_condition(): void
    {
        $post = $this->article(<<<'MD'
::: if-published post="chat-moderation"
```text
:::
```

Visible after the code sample.
:::
MD);

        $this->assertStringContainsString('Visible after the code sample.', $post->html());
        $this->assertStringContainsString('<code class="language-text">:::', $post->html());
        $this->assertSame([['post' => 'chat-moderation', 'closed' => true]], $post->conditions());
    }

    public function test_unknown_references_are_hidden_and_reported_by_the_check_command(): void
    {
        $post = $this->article("::: if-published post=\"missing-target\"\nHidden unknown target.\n:::");

        $this->assertStringNotContainsString('Hidden unknown', $post->html());
        $this->assertSame(['missing-target'], Larablog::collection('blog')->brokenLinks($post));
        $this->artisan('larablog:check')->expectsOutputToContain('conditional-demo/en: links to post:missing-target, which does not exist.')->assertExitCode(1);
    }

    public function test_an_unclosed_condition_stays_hidden_and_is_reported(): void
    {
        $post = $this->article("::: if-published post=\"chat-moderation\"\nHidden unclosed content.");

        $this->assertStringNotContainsString('Hidden unclosed', $post->html());
        $this->assertSame([['post' => 'chat-moderation', 'closed' => false]], $post->conditions());
        $this->artisan('larablog:check')->expectsOutputToContain('if-published post:chat-moderation is missing its closing :::.')->assertExitCode(1);
    }

    public function test_pending_dependencies_appear_in_the_existing_pending_report(): void
    {
        $post = $this->article("::: if-published post=\"future\"\nScheduled content.\n:::");

        $this->assertSame('2099-01-01', Larablog::collection('blog')->pendingLinks($post)['future']->toDateString());
        $this->artisan('larablog:check', ['--pending' => true])->expectsOutputToContain('conditional-demo/en: post:future becomes a link on 2099-01-01.');
    }

    public function test_generated_conditionals_work_when_raw_html_is_stripped(): void
    {
        config(['larablog.markdown.html_input' => 'strip']);
        $post = $this->article("::: if-published post=\"chat-moderation\"\nAvailable content.\n:::\n\n::: if-published post=\"future\"\nHidden content.\n:::");

        $this->assertStringContainsString('Available content.', $post->html());
        $this->assertStringNotContainsString('Hidden content.', $post->html());
    }

    public function test_each_numbered_item_can_have_its_own_condition_and_an_empty_numbered_list_disappears(): void
    {
        $post = $this->article("1. ::: if-published post=\"future\"\n   Hidden number.\n   :::\n");

        $this->assertStringNotContainsString('<ol', $post->html());
        $this->assertStringNotContainsString('<li', $post->html());
    }

    public function test_conditions_in_other_languages_use_the_existing_translation_fallback(): void
    {
        $post = $this->article("::: if-published post=\"glossaries\"\nAvailable through the English translation.\n:::", 'es');

        $this->assertStringContainsString('Available through the English translation.', $post->html());
        $this->assertSame('es', $post->locale);
    }

    public function test_preview_flags_also_control_the_visibility_of_conditional_content(): void
    {
        $post = $this->article("::: if-published post=\"draft\"\nDraft fragment.\n:::\n\n::: if-published post=\"future\"\nFuture fragment.\n:::");
        $this->assertStringNotContainsString('Draft fragment.', $post->html());
        $this->assertStringNotContainsString('Future fragment.', $post->html());

        config(['larablog.show_drafts' => true, 'larablog.show_scheduled' => true]);

        $this->assertStringContainsString('Draft fragment.', $post->html());
        $this->assertStringContainsString('Future fragment.', $post->html());
    }

    public function test_existing_heading_anchors_stay_stable_when_a_hidden_heading_is_published(): void
    {
        $post = $this->article("::: if-published post=\"future\"\n## Same heading\n:::\n\n## Same heading");
        $this->assertSame(['same-heading-2'], array_column($post->headings(), 'id'));

        $this->travelTo('2099-01-02');

        $this->assertSame(['same-heading', 'same-heading-2'], array_column($post->headings(), 'id'));
    }

    public function test_a_removed_target_hides_its_fragment_even_when_its_metadata_is_cached(): void
    {
        $post = $this->article("::: if-published post=\"glossaries\"\nDependent fragment.\n:::");
        $this->assertStringContainsString('Dependent fragment.', $post->html());
        unlink($this->content . '/glossaries/en.md');

        $this->assertStringNotContainsString('Dependent fragment.', $post->html());
    }

    public function test_indentation_single_quotes_and_blockquotes_support_the_same_syntax(): void
    {
        $post = $this->article("  ::: if-published post='chat-moderation'\nIndented fragment.\n  :::\n\n> ::: if-published post='future'\n> Hidden quote.\n> :::");

        $this->assertStringContainsString('Indented fragment.', $post->html());
        $this->assertStringNotContainsString('Hidden quote.', $post->html());
    }

    private function article(string $body, string $locale = 'en'): Post
    {
        $this->content = sys_get_temp_dir() . '/larablog-conditional-' . bin2hex(random_bytes(8));
        File::copyDirectory(__DIR__ . '/../fixtures/blog', $this->content);
        mkdir($this->content . '/conditional-demo');
        file_put_contents($this->content . '/conditional-demo/' . $locale . '.md', "---\ntitle: Conditional demo\nslug: conditional-demo\ndate: 2025-01-01\n---\n\n" . $body);
        config(['larablog.collections.blog.path' => $this->content, 'larablog.cache.check_interval' => PHP_INT_MAX]);

        return Larablog::collection('blog')->find('conditional-demo', $locale);
    }
}
