<?php

namespace EduLazaro\Larablog\Http;

use EduLazaro\Larablog\Blog;
use EduLazaro\Larablog\Feed;
use EduLazaro\Larablog\Larablog;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * The collection's pages. Larablog finds and paginates; the site's views draw.
 *
 * Every view gets `blog`, `locale` and `alternates` (language => URL of this same page),
 * which is what a language switcher and the hreflang links need.
 */
class BlogController
{
    /**
     * @param Request $request
     * @return View
     */
    public function index(Request $request): View
    {
        $blog = $this->blog($request);

        return view($blog->config('views.index', 'blog.index'), [
            'blog' => $blog,
            'locale' => app()->getLocale(),
            'posts' => $blog->paginate($blog->posts()),
            'categories' => $blog->categories(),
            'category' => null,
            'tag' => null,
            'alternates' => $blog->indexAlternates(),
        ]);
    }

    /**
     * A post in this language. A slug that belongs to the post in ANOTHER language (a link
     * shared from the Spanish site opened on the English one) is a permanent redirect to the
     * right address rather than a 404: one post, one URL per language, and every other
     * spelling of it leading there.
     *
     * @param Request $request
     * @param string $slug
     * @return View|RedirectResponse
     */
    public function show(Request $request, string $slug): View|RedirectResponse
    {
        $blog = $this->blog($request);
        $locale = app()->getLocale();
        $post = $blog->find($slug, $locale);

        if (! $post) {
            $elsewhere = $blog->findInAnyLocale($slug);
            abort_unless($elsewhere !== null, 404);

            $target = $blog->translationsOf($elsewhere, true)[$locale] ?? $elsewhere;

            return redirect()->to($target->url(), 301);
        }

        return view($blog->config('views.show', 'blog.show'), [
            'blog' => $blog,
            'locale' => $locale,
            'post' => $post,
            'related' => $post->related(),
            'alternates' => $post->alternates(),
        ]);
    }

    /**
     * @param Request $request
     * @param string $category
     * @return View
     */
    public function category(Request $request, string $category): View
    {
        $blog = $this->blog($request);
        $found = $blog->categoryBySlug($category);
        abort_unless($found !== null, 404);

        $posts = $blog->inCategory($found);
        abort_if($posts === [], 404);

        return view($blog->config('views.category', 'blog.index'), [
            'blog' => $blog,
            'locale' => app()->getLocale(),
            'posts' => $blog->paginate($posts),
            'categories' => $blog->categories(),
            'category' => $found,
            'tag' => null,
            'alternates' => $blog->categoryAlternates($found),
        ]);
    }

    /**
     * @param Request $request
     * @param string $tag
     * @return View
     */
    public function tag(Request $request, string $tag): View
    {
        $blog = $this->blog($request);
        $posts = $blog->withTag($tag);
        abort_if($posts === [], 404);

        return view($blog->config('views.tag', 'blog.index'), [
            'blog' => $blog,
            'locale' => app()->getLocale(),
            'posts' => $blog->paginate($posts),
            'categories' => $blog->categories(),
            'category' => null,
            'tag' => $blog->tags()[$tag]['name'] ?? Str::headline($tag),
            'alternates' => [app()->getLocale() => $request->url()],
        ]);
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function feed(Request $request): Response
    {
        $blog = $this->blog($request);
        abort_unless((bool) $blog->config('feed.enabled', true), 404);

        return response(Feed::for($blog, app()->getLocale()), 200, ['Content-Type' => 'application/rss+xml; charset=UTF-8']);
    }

    /**
     * @param Request $request
     * @return Blog
     */
    private function blog(Request $request): Blog
    {
        return Larablog::collection((string) ($request->route()?->defaults['larablog'] ?? 'blog'));
    }
}
