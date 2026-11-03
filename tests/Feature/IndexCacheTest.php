<?php

namespace EduLazaro\Larablog\Tests\Feature;

use EduLazaro\Larablog\Index;
use EduLazaro\Larablog\Larablog;
use EduLazaro\Larablog\Tests\TestCase;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Support\Facades\File;
use Mockery;

class IndexCacheTest extends TestCase
{
    private ?string $content = null;

    protected function tearDown(): void
    {
        if ($this->content !== null) {
            File::deleteDirectory($this->content);
        }

        parent::tearDown();
    }

    public function test_a_request_builds_a_missing_index_without_a_command_and_rebuilds_after_cache_eviction(): void
    {
        $this->content();

        $this->assertNotNull($this->blog()->find('chat-moderation-games', 'en'));
        cache()->flush();
        $this->renameArticle();
        $this->app->forgetScopedInstances();

        $this->assertNotNull($this->blog()->find('renamed-chat', 'en'));
        $this->assertNull($this->blog()->find('chat-moderation-games', 'en'));
    }

    public function test_a_warm_index_is_reused_until_the_next_check_and_then_detects_renamed_added_and_deleted_files(): void
    {
        $path = $this->content();
        $this->freezeTime();
        $this->assertNotNull($this->blog()->find('chat-moderation-games', 'en'));
        $this->renameArticle();
        mkdir($path . '/new-post');
        file_put_contents($path . '/new-post/en.md', "---\ntitle: New article\nslug: new-article\ndate: 2025-01-01\n---\nNew text.");
        unlink($path . '/glossaries/en.md');
        $this->app->forgetScopedInstances();

        $this->assertNotNull($this->blog()->find('chat-moderation-games', 'en'));
        $this->assertNull($this->blog()->find('renamed-chat', 'en'));
        $this->assertNull($this->blog()->find('new-article', 'en'));
        $this->assertNull($this->blog()->find('glossaries-that-are-obeyed', 'en'));
        $this->travel(61)->seconds();
        $this->app->forgetScopedInstances();

        $this->assertNull($this->blog()->find('chat-moderation-games', 'en'));
        $this->assertNotNull($this->blog()->find('renamed-chat', 'en'));
        $this->assertNotNull($this->blog()->find('new-article', 'en'));
        $this->assertSame(2, count($this->blog()->posts('en')));
    }

    public function test_the_optional_command_refreshes_the_cache_and_the_current_collection_immediately(): void
    {
        $this->content();
        $blog = $this->blog();
        $this->assertNotNull($blog->find('chat-moderation-games', 'en'));
        $this->renameArticle();

        $this->artisan('larablog:cache', ['collection' => 'blog'])->expectsOutputToContain('blog: 5 files indexed.')->assertExitCode(0);

        $this->assertNotNull($blog->find('renamed-chat', 'en'));
        $this->assertNull($blog->find('chat-moderation-games', 'en'));
        $this->app->forgetScopedInstances();
        $this->assertNotNull($this->blog()->find('renamed-chat', 'en'));
    }

    public function test_the_cache_command_rejects_an_unknown_collection(): void
    {
        $this->artisan('larablog:cache', ['collection' => 'missing'])->expectsOutputToContain('Unknown collection: missing.')->assertExitCode(1);
    }

    public function test_disabled_cache_and_zero_check_interval_keep_immediate_file_detection(): void
    {
        $this->content();
        config(['larablog.cache.enabled' => false]);
        $this->assertNotNull($this->blog()->find('chat-moderation-games', 'en'));
        $this->renameArticle();
        $this->app->forgetScopedInstances();
        $this->assertNotNull($this->blog()->find('renamed-chat', 'en'));

        config(['larablog.cache.enabled' => true, 'larablog.cache.check_interval' => 0]);
        $this->app->forgetScopedInstances();
        $this->assertNotNull($this->blog()->find('renamed-chat', 'en'));
        $this->renameArticle('renamed-chat', 'renamed-again');
        $this->app->forgetScopedInstances();
        $this->assertNotNull($this->blog()->find('renamed-again', 'en'));
    }

    public function test_old_published_configurations_use_the_production_default_without_new_settings(): void
    {
        $this->content();
        $this->app->detectEnvironment(fn () => 'production');
        config(['larablog.cache' => ['enabled' => true, 'store' => null]]);
        $this->assertNotNull($this->blog()->find('chat-moderation-games', 'en'));
        $this->renameArticle();
        $this->app->forgetScopedInstances();

        $this->assertNotNull($this->blog()->find('chat-moderation-games', 'en'));
        $this->travel(61)->seconds();
        $this->app->forgetScopedInstances();
        $this->assertNotNull($this->blog()->find('renamed-chat', 'en'));
    }

    public function test_same_named_collections_with_different_paths_do_not_share_an_index(): void
    {
        $path = $this->content();
        $this->assertNotNull($this->blog()->find('chat-moderation-games', 'en'));
        mkdir($path . '/other');
        mkdir($path . '/other/article');
        file_put_contents($path . '/other/article/en.md', "---\ntitle: Isolated\nslug: isolated\n---\nText.");
        config(['larablog.collections.blog.path' => $path . '/other']);
        $this->app->forgetScopedInstances();

        $this->assertNotNull($this->blog()->find('isolated', 'en'));
        $this->assertNull($this->blog()->find('chat-moderation-games', 'en'));
    }

    public function test_scheduled_posts_become_visible_without_rebuilding_the_index(): void
    {
        $this->content();
        config(['larablog.cache.check_interval' => PHP_INT_MAX]);
        $this->assertNull($this->blog()->find('from-the-future', 'en'));

        $this->travelTo('2099-01-02');
        $this->app->forgetScopedInstances();

        $this->assertNotNull($this->blog()->find('from-the-future', 'en'));
        $this->assertNull($this->blog()->find('half-written', 'en'));
    }

    public function test_lookup_keeps_the_latest_published_match_across_languages_and_shares_post_instances(): void
    {
        $path = $this->content();
        file_put_contents($path . '/chat-moderation/en.md', "---\ntitle: Shared\nslug: shared\ndate: 2024-01-01\n---\nEnglish.");
        file_put_contents($path . '/chat-moderation/es.md', "---\ntitle: Compartido\nslug: shared\ndate: 2025-01-01\n---\nEspañol.");

        $blog = $this->blog();
        $english = $blog->find('shared', 'en');
        $spanish = $blog->find('shared', 'es');

        $this->assertSame('en', $english->locale);
        $this->assertSame('es', $spanish->locale);
        $this->assertSame($spanish, $blog->findInAnyLocale('shared'));
        $this->assertSame($english, collect($blog->all())->first(fn ($post) => $post->slug === 'shared' && $post->locale === 'en'));
    }

    public function test_a_busy_refresh_serves_the_existing_snapshot_instead_of_rebuilding(): void
    {
        $path = $this->content();
        $lock = Mockery::mock(Lock::class);
        $lock->shouldReceive('block')->once()->andReturnUsing(fn ($seconds, $callback) => $callback());
        $lock->shouldReceive('get')->once()->andReturn(false);
        $store = Mockery::mock(ArrayStore::class)->makePartial();
        $store->shouldReceive('lock')->andReturn($lock);
        $index = new Index('blog', $path, ['en', 'es'], new Repository($store));
        $first = $index->load();
        $this->renameArticle();
        $this->travel(61)->seconds();

        $this->assertSame($first, $index->load());
    }

    public function test_a_cold_request_can_still_read_content_if_the_lock_wait_times_out(): void
    {
        $path = $this->content();
        $lock = Mockery::mock(Lock::class);
        $lock->shouldReceive('block')->once()->andThrow(new LockTimeoutException());
        $store = Mockery::mock(ArrayStore::class)->makePartial();
        $store->shouldReceive('lock')->andReturn($lock);

        $index = (new Index('blog', $path, ['en', 'es'], new Repository($store)))->load();

        $this->assertArrayHasKey('chat-moderation-games', $index['lookup']['en']);
    }

    public function test_stores_without_lock_support_still_build_and_reuse_the_index(): void
    {
        $path = $this->content();
        $backing = new ArrayStore();
        $store = Mockery::mock(Store::class);
        $store->shouldReceive('get')->andReturnUsing(fn ($key) => $backing->get($key));
        $store->shouldReceive('forever')->once()->andReturnUsing(fn ($key, $value) => $backing->forever($key, $value));
        $index = new Index('blog', $path, ['en', 'es'], new Repository($store));
        $first = $index->load();
        $this->renameArticle();

        $this->assertSame($first, $index->load());
    }

    public function test_same_second_file_edits_with_a_different_size_refresh_the_rendered_article(): void
    {
        $path = $this->content();
        config(['larablog.cache.check_interval' => 0]);
        $file = $path . '/chat-moderation/en.md';
        $this->assertStringNotContainsString('Fresh paragraph.', $this->blog()->find('chat-moderation-games', 'en')->html());
        $mtime = filemtime($file);
        file_put_contents($file, file_get_contents($file) . "\n\nFresh paragraph.\n");
        touch($file, $mtime);
        $this->app->forgetScopedInstances();

        $this->assertStringContainsString('Fresh paragraph.', $this->blog()->find('chat-moderation-games', 'en')->html());
    }

    public function test_check_always_validates_current_files_even_with_a_warm_production_index(): void
    {
        $this->content();
        $this->assertNotNull($this->blog()->find('chat-moderation-games', 'en'));
        file_put_contents($this->content . '/chat-moderation/en.md', "---\ntitle: \"\"\n---\nBroken.");

        $this->artisan('larablog:check')->expectsOutputToContain('chat-moderation/en.md: It has no title.')->assertExitCode(1);
    }

    public function test_file_cache_reuses_the_index_between_collection_instances(): void
    {
        $path = $this->content();
        config([
            'cache.stores.larablog-test' => ['driver' => 'file', 'path' => $path . '/cache'],
            'larablog.cache.store' => 'larablog-test',
        ]);
        $this->assertNotNull($this->blog()->find('chat-moderation-games', 'en'));
        $this->renameArticle();
        $this->app->forgetScopedInstances();

        $this->assertNotNull($this->blog()->find('chat-moderation-games', 'en'));
        $this->artisan('larablog:cache')->assertExitCode(0);
        $this->app->forgetScopedInstances();
        $this->assertNotNull($this->blog()->find('renamed-chat', 'en'));
    }

    public function test_the_optional_command_respects_disabled_caching(): void
    {
        $this->content();
        config(['larablog.cache.enabled' => false]);

        $this->artisan('larablog:cache')->expectsOutputToContain('Caching is disabled.')->assertExitCode(0);
        $this->renameArticle();
        $this->app->forgetScopedInstances();
        $this->assertNotNull($this->blog()->find('renamed-chat', 'en'));
    }

    private function content(): string
    {
        $this->content = sys_get_temp_dir() . '/larablog-index-' . bin2hex(random_bytes(8));
        File::copyDirectory(__DIR__ . '/../fixtures/blog', $this->content);
        config(['larablog.collections.blog.path' => $this->content, 'larablog.cache.check_interval' => 60]);

        return $this->content;
    }

    private function renameArticle(string $from = 'chat-moderation-games', string $to = 'renamed-chat'): void
    {
        $path = $this->content . '/chat-moderation/en.md';
        $body = file_get_contents($path);
        $body = str_contains($body, 'slug: ' . $from)
            ? str_replace('slug: ' . $from, 'slug: ' . $to, $body)
            : str_replace('title: "Chat moderation for games"', "title: \"Chat moderation for games\"\nslug: {$to}", $body);
        file_put_contents($path, $body);
        touch($path, time() + 5);
    }

    private function blog(): \EduLazaro\Larablog\Blog
    {
        return Larablog::collection('blog');
    }
}
