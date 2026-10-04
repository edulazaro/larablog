<?php

namespace EduLazaro\Larablog\Console;

use EduLazaro\Larablog\Larablog;
use Illuminate\Console\Command;

/**
 * Reads every collection and says what is wrong with it, before a visitor finds out.
 *
 * Errors are posts that are not published at all (no title, a slug used twice in one
 * language). Warnings are posts missing in a language the collection is written in, which
 * is allowed but usually forgotten rather than decided.
 */
class CheckCommand extends Command
{
    /** @var string */
    protected $signature = 'larablog:check {collection? : Only this collection}';

    /** @var string */
    protected $description = 'Check the blog posts: titles, slugs per language, categories, authors, missing translations';

    /**
     * @return int
     */
    public function handle(): int
    {
        $collections = Larablog::collections();

        if ($only = $this->argument('collection')) {
            $collections = array_intersect_key($collections, [$only => true]);
        }

        $failed = false;

        foreach ($collections as $name => $blog) {
            $this->line("<info>{$name}</info>");

            foreach ($blog->errors() as $error) {
                $this->line("  <error>✗</error> {$error['file']}: {$error['message']}");
                $failed = true;
            }

            $byId = [];

            foreach ($blog->all() as $post) {
                $byId[$post->id][] = $post->locale;
            }

            foreach ($byId as $id => $locales) {
                $missing = array_diff($blog->locales(), $locales);

                if ($missing !== []) {
                    $this->line("  <comment>!</comment> {$id}: no " . implode(', ', $missing) . ' version.');
                }
            }

            $this->line('  ' . count($blog->all()) . ' files, ' . count($byId) . ' posts, ' . implode('/', $blog->locales()) . '.');
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
