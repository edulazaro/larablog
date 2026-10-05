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
    protected $signature = 'larablog:check {collection? : Only this collection} {--pending : Also list links to posts that are not out yet}';

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

                // Scheduled posts too: a link written today to a folder that does not exist
                // would otherwise only be found the day the post goes live.
                foreach ($blog->brokenLinks($post) as $id) {
                    $this->line("  <error>✗</error> {$post->id}/{$post->locale}: links to post:{$id}, which does not exist.");
                    $failed = true;
                }

                // Not an error: the link is plain text until its post is out.
                if ($this->option('pending')) {
                    foreach ($blog->pendingLinks($post) as $id => $date) {
                        $when = $date ? 'on ' . $date->toDateString() : 'when it stops being a draft';
                        $this->line("  <comment>·</comment> {$post->id}/{$post->locale}: post:{$id} becomes a link {$when}.");
                    }
                }
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
