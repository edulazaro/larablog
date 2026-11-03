<?php

namespace EduLazaro\Larablog\Console;

use EduLazaro\Larablog\Larablog;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\LockTimeoutException;

/**
 * Optional cache warming for deploys; a visitor can also build a missing index.
 */
class CacheCommand extends Command
{
    /** @var string */
    protected $signature = 'larablog:cache {collection? : Only this collection}';

    /** @var string */
    protected $description = 'Rebuild the blog indexes without waiting for the next request';

    public function handle(): int
    {
        $collections = Larablog::collections();
        $only = $this->argument('collection');
        if ($only !== null && ! isset($collections[$only])) {
            $this->error("Unknown collection: {$only}.");

            return self::FAILURE;
        }

        foreach ($collections as $name => $blog) {
            if ($only !== null && $name !== $only) {
                continue;
            }

            try {
                $count = $blog->rebuildIndex();
            } catch (LockTimeoutException) {
                $this->error("{$name}: another process is rebuilding the index. Try again shortly.");

                return self::FAILURE;
            }

            $this->line("{$name}: {$count} files indexed." . (config('larablog.cache.enabled') ? '' : ' Caching is disabled.'));
        }

        return self::SUCCESS;
    }
}
