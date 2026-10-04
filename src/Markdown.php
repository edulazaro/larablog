<?php

namespace EduLazaro\Larablog;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Str;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\ExternalLink\ExternalLinkExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\MarkdownConverter;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads a post file: its frontmatter, and its body as HTML with an anchor on every heading.
 *
 * Rendered bodies are cached by the file's path and modification time, so editing a post
 * is seen on the next request and an unchanged one is never rendered twice.
 */
final class Markdown
{
    /**
     * @param Repository $cache
     */
    public function __construct(private Repository $cache)
    {
    }

    /**
     * The frontmatter and the raw body of a file.
     *
     * @param string $content
     * @return array{0: array<string, mixed>, 1: string}
     *
     * @throws Exceptions\InvalidPost
     */
    public static function split(string $content): array
    {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;

        if (! preg_match('/^---\R(.*?)\R---\R?(.*)$/s', $content, $m)) {
            return [[], $content];
        }

        try {
            $meta = Yaml::parse($m[1], Yaml::PARSE_DATETIME);
        } catch (\Throwable $e) {
            throw new Exceptions\InvalidPost('The frontmatter is not valid YAML: ' . $e->getMessage(), previous: $e);
        }

        return [is_array($meta) ? $meta : [], $m[2]];
    }

    /**
     * @param string $file
     * @return array{html: string, headings: array<int, array{level: int, id: string, text: string}>}
     */
    public function render(string $file): array
    {
        $key = 'larablog:html:' . sha1($file . '|' . (string) @filemtime($file));

        $render = fn () => $this->convert(self::split((string) file_get_contents($file))[1]);

        return config('larablog.cache.enabled') ? $this->cache->rememberForever($key, $render) : $render();
    }

    /**
     * @param string $markdown
     * @return array{html: string, headings: array<int, array{level: int, id: string, text: string}>}
     */
    public function convert(string $markdown): array
    {
        $html = (string) $this->converter()->convert($markdown);

        return $this->anchor($html);
    }

    /**
     * Gives every h2 and h3 an id from its text, unique within the post, and lists them.
     *
     * Done on the HTML rather than through a CommonMark extension so a heading written as
     * raw HTML in the Markdown gets one too, and an id already written by hand is kept.
     *
     * @param string $html
     * @return array{html: string, headings: array<int, array{level: int, id: string, text: string}>}
     */
    private function anchor(string $html): array
    {
        $headings = [];
        $seen = [];

        $html = (string) preg_replace_callback('/<h([23])(\s[^>]*)?>(.*?)<\/h\1>/si', function (array $m) use (&$headings, &$seen) {
            $attributes = $m[2] ?? '';
            $text = trim(html_entity_decode(strip_tags($m[3]), ENT_QUOTES | ENT_HTML5));

            if (preg_match('/\sid="([^"]+)"/', $attributes, $existing)) {
                $id = $existing[1];
            } else {
                $base = Str::slug($text) ?: 'section';
                $id = $base;

                for ($n = 2; isset($seen[$id]); $n++) {
                    $id = "{$base}-{$n}";
                }

                $attributes .= ' id="' . $id . '"';
            }

            $seen[$id] = true;
            $headings[] = ['level' => (int) $m[1], 'id' => $id, 'text' => $text];

            return "<h{$m[1]}{$attributes}>{$m[3]}</h{$m[1]}>";
        }, $html);

        return ['html' => $html, 'headings' => $headings];
    }

    /**
     * @return MarkdownConverter
     */
    private function converter(): MarkdownConverter
    {
        $options = config('larablog.markdown', []);

        $config = [
            'html_input' => $options['html_input'] ?? 'allow',
            'allow_unsafe_links' => (bool) ($options['allow_unsafe_links'] ?? false),
        ];

        if ($options['external_links'] ?? true) {
            $config['external_link'] = [
                'internal_hosts' => array_filter([parse_url((string) config('app.url'), PHP_URL_HOST)]),
                'open_in_new_window' => true,
                'nofollow' => '',
                'noopener' => 'external',
                'noreferrer' => 'external',
            ];
        }

        $environment = new Environment($config);
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new GithubFlavoredMarkdownExtension());

        if ($options['external_links'] ?? true) {
            $environment->addExtension(new ExternalLinkExtension());
        }

        return new MarkdownConverter($environment);
    }
}
