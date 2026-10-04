<?php

/*
| Larablog: Markdown blogs in several languages, each post with its own URL per language.
|
| A collection is one blog (or one set of guides, case studies, whitepapers...). On disk:
|
|     {path}/_authors.yml            who writes (optional)
|     {path}/_categories.yml         the categories, named per language (optional)
|     {path}/{post-id}/en.md         one folder per post, one file per language
|     {path}/{post-id}/es.md
|
| The folder groups the translations; every file declares its OWN `slug`, so the Spanish
| post lives at /es/blog/moderacion-de-chat and the English one at /blog/chat-moderation,
| and each page links the other through hreflang. The folder name never reaches a URL.
*/
return [

    'collections' => [

        'blog' => [
            'path' => base_path('content/blog'),

            // Languages the collection is written in, first is the default. Null means
            // config('laralang.locales') when Laralang is installed, else the app locale.
            'locales' => null,

            // The first segment of the collection's URLs, per language. With Laralang the
            // language prefix (/es) is added by Laralang; without it, by Larablog.
            'paths' => ['en' => 'blog', 'es' => 'blog'],

            // The words in category and tag URLs, per language.
            'segments' => [
                'category' => ['en' => 'category', 'es' => 'categoria'],
                'tag' => ['en' => 'tag', 'es' => 'etiqueta'],
            ],

            // Your views: Larablog finds and paginates the posts, the site draws them.
            'views' => [
                'index' => 'blog.index',
                'show' => 'blog.show',
                'category' => 'blog.index',
                'tag' => 'blog.index',
            ],

            'per_page' => 12,

            // Social cards drawn with Laracards, one per post and language. `template` is a
            // template configured in config/laracards.php.
            'cards' => [
                'enabled' => true,
                'template' => 'post',
                'path' => 'img/blog',
                'brand' => null,
            ],

            // The feed of each language, at /{path}/feed.
            'feed' => [
                'enabled' => true,
                'title' => null,
                'description' => null,
                'limit' => 20,
            ],
        ],

    ],

    // Posts dated in the future, and drafts, stay off every list, feed and sitemap, and
    // answer 404. Turn these on locally to preview them.
    'show_scheduled' => env('LARABLOG_SHOW_SCHEDULED', false),
    'show_drafts' => env('LARABLOG_SHOW_DRAFTS', false),

    // Words a minute, for the reading time when a post does not say it.
    'words_per_minute' => 220,

    // The index of every collection is cached and rebuilt when any of its files changes.
    'cache' => [
        'enabled' => env('LARABLOG_CACHE', true),
        'store' => null,
    ],

    // CommonMark. The content is written by the site's owner, so HTML in Markdown is
    // allowed by default; set 'strip' or 'escape' if anybody else writes posts.
    'markdown' => [
        'html_input' => 'allow',
        'allow_unsafe_links' => false,
        // Links to other hosts open with rel="noopener noreferrer" and target="_blank".
        'external_links' => true,
    ],

];
