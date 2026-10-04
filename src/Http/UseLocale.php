<?php

namespace EduLazaro\Larablog\Http;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Puts the request in the language of the blog page it hit, before anything renders.
 */
class UseLocale
{
    /**
     * @param Request $request
     * @param Closure(Request): Response $next
     * @return Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->route()?->defaults['larablog_locale'] ?? null;

        if (is_string($locale) && $locale !== '') {
            app()->setLocale($locale);
        }

        return $next($request);
    }
}
