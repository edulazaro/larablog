![Larablog](art/banner.png)

# Larablog

<p align="center">
    <a href="https://github.com/edulazaro/larablog/actions/workflows/tests.yml"><img src="https://github.com/edulazaro/larablog/actions/workflows/tests.yml/badge.svg" alt="Tests"></a>
    <a href="https://packagist.org/packages/edulazaro/larablog"><img src="https://img.shields.io/packagist/v/edulazaro/larablog" alt="Latest Stable Version"></a>
    <a href="https://packagist.org/packages/edulazaro/larablog"><img src="https://img.shields.io/packagist/php-v/edulazaro/larablog" alt="PHP Version"></a>
    <a href="https://github.com/edulazaro/larablog/blob/main/LICENSE"><img src="https://img.shields.io/packagist/l/edulazaro/larablog" alt="License"></a>
</p>

Markdown blogs for Laravel in several languages, where **every language has its own URL**.

One folder per post, one file per language. Each file declares its own slug, so the English
post lives at `/blog/chat-moderation-games` and the Spanish one at
`/es/blog/moderacion-chat-videojuegos`, and each page knows the other: hreflang, a language
switcher, the sitemap and a link shared in the wrong language all just work.

- Posts are Markdown files with YAML frontmatter, read once and cached until a file changes
- A URL per language, with translated words (`/blog/category/x`, `/es/blog/categoria/x`)
- A slug from another language redirects to the right post in this one (301)
- Categories and authors in two YAML files, named per language
- Drafts and scheduled posts, an RSS feed per language, sitemap entries with alternates
- Anchors on every heading for an "On this page" index, safe external links
- Links between posts by folder (`post:chat-moderation`), resolved in the reader's language
- A social card per post and language, drawn by [Laracards](https://github.com/edulazaro/laracards)
- `php artisan larablog:check` before a visitor finds the broken post
- Your views: Larablog finds and paginates the posts, your site draws them

## Requirements

PHP 8.2+ and Laravel 12 or 13 (tested on both). [Laralang](https://github.com/edulazaro/laralang)
is optional: with it the blog shares the site's language prefixes.

## Install

```bash
composer require edulazaro/larablog
php artisan vendor:publish --tag=larablog-config
```

## Content

```
content/blog/
  _authors.yml
  _categories.yml
  chat-moderation/
    en.md
    es.md
  glossaries/
    en.md
```

The folder name groups the translations and never appears in a URL. A language missing for a
post is allowed; `larablog:check` points it out.

```markdown
---
title: "Chat moderation for games"
slug: chat-moderation-games
description: "Catching toxicity without killing the banter."
date: 2026-09-01
updated: 2026-09-20
category: engineering
tags: [gaming, moderation]
author: edu
image: /img/covers/chat.jpg     # optional: otherwise the Laracards card is used
featured: true
draft: false
---

The body, in Markdown.
```

`slug` defaults to the title, slugified. A post dated in the future is scheduled: it is not
listed, not in the feed or the sitemap, and answers 404 until its day. Any other field stays
available as `$post->meta('field')`.

```yaml
# _categories.yml
engineering:
  name: { en: Engineering, es: Ingeniería }
  description: { en: How it is built, es: Cómo está hecho }
  color: "#009e4d"
```

```yaml
# _authors.yml
edu:
  name: Eduardo Lázaro
  role: { en: Founder, es: Fundador }
  avatar: /img/authors/edu.webp
  links: { github: https://github.com/edulazaro }
```

A category slug is its name, slugified, per language (`ingenieria`), unless you give `slug`.

Any other field of a category stays available as `$category->meta('field')`, in the page's
language when it is written per language. `accent` is also what the social card is tinted
with, when the `color` your views use is a class name rather than a colour:

```yaml
engineering:
  name: { en: Engineering, es: Ingeniería }
  color: teal              # your CSS: class="cat-{{ $category->color }}"
  accent: "#2dd4bf"        # the card
```

## Linking posts

Link another post by its **folder**, not by its slug:

```markdown
Read [how glossaries are applied](post:glossaries) first, or [the part on
placeholders](post:glossaries#placeholders).
```

On an English page that becomes `/blog/glossaries-that-are-obeyed`, on a Spanish one
`/es/blog/glosarios-que-se-cumplen`, and if the post is not translated it points at the
language it does exist in. Renaming a slug never breaks the links to it. A link to a post
that is not published points at `#` and fails `larablog:check`. `#anchor` and `?query` are kept.

## Routes

```php
// routes/web.php
use EduLazaro\Larablog\Larablog;

Larablog::routes('blog');
```

That registers, per language, the listing, a post, a category, a tag and the feed, named
`{locale}.blog.index|show|category|tag|feed`. With [Laralang](https://github.com/edulazaro/laralang)
installed the language prefixes are Laralang's, so the blog shares them with the rest of the
site; without it the default language sits at the root and the others under their code.

```php
// config/larablog.php
'collections' => [
    'blog' => [
        'path' => base_path('content/blog'),
        'locales' => ['en', 'es'],
        'paths' => ['en' => 'blog', 'es' => 'blog'],
        'segments' => [
            'category' => ['en' => 'category', 'es' => 'categoria'],
            'tag' => ['en' => 'tag', 'es' => 'etiqueta'],
        ],
        'views' => ['index' => 'blog.index', 'show' => 'blog.show', 'category' => 'blog.index', 'tag' => 'blog.index'],
        'per_page' => 12,
        'cards' => ['enabled' => true, 'template' => 'post', 'path' => 'img/blog'],
    ],
],
```

Several collections (a blog, guides, case studies) each get their own block and their own
`Larablog::routes('guides')`.

## Views

Every view gets `$blog`, `$locale` and `$alternates` (language => URL of this same page).
The listing gets `$posts` (a paginator), `$categories`, and `$category` or `$tag` when
filtered; a post gets `$post` and `$related`.

```blade
@foreach ($alternates as $locale => $url)
    <link rel="alternate" hreflang="{{ $locale }}" href="{{ $url }}">
@endforeach

<h1>{{ $post->title }}</h1>
<p>{{ $post->author()?->name }} · {{ $post->date->locale($locale)->isoFormat('LL') }} · {{ $post->readingTime }} min</p>

<nav>
    @foreach ($post->headings() as $heading)
        <a href="#{{ $heading['id'] }}">{{ $heading['text'] }}</a>
    @endforeach
</nav>

<article>{!! $post->html() !!}</article>
```

## From PHP

```php
$blog = Larablog::collection('blog');

$blog->posts('es');                       // published, newest first
$blog->find('moderacion-chat-videojuegos', 'es');
$post->alternates();                      // ['en' => '…/blog/chat-moderation-games', 'es' => '…']
$post->translations();                    // the same post in the other languages
$post->related(3);
$blog->categories('es');
$blog->tags('en');
$blog->sitemap();                         // every URL with lastmod and its alternates
```

## Social cards

Larablog registers itself as a [Laracards](https://github.com/edulazaro/laracards) source, so
`php artisan cards:generate` draws one card per post and language, at
`public/img/blog/{locale}/{slug}.png`. The template gets `title`, `description`,
`category_label`, `accent` (the category's colour), `author_name`, `date_formatted` (in the
post's language), `reading_time`, `brand_label` and `locale`. `$post->imageUrl()` returns the
frontmatter's `image`, or the card when it exists.

## Checking

```bash
php artisan larablog:check
```

Errors (a post without a title, a slug used twice in one language, broken YAML) are posts
that are not published at all, and a `post:` link leading to no published post. Warnings are posts missing a language. It exits 1 on errors,
for CI.

## Testing

```bash
composer install
vendor/bin/phpunit
```

The suite runs on Testbench against fixtures in `tests/fixtures/blog`: a translated post, one
with no translation, a scheduled one, a draft, a post with no title and a duplicated slug.
GitHub Actions runs it on PHP 8.2 to 8.4 with Laravel 12 and 13 on every push.

## Author

Created by [Edu Lazaro](https://edulazaro.com)

## License

Larablog is open-sourced software licensed under the [MIT license](LICENSE).
