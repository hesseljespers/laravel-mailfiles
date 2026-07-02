<?php

declare(strict_types=1);

namespace Melloww\MailFiles\Support;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * A small, dependency-free HTML to Markdown converter.
 *
 * Covers the constructs that actually show up in email bodies — headings,
 * emphasis, links, images, lists, blockquotes, code, tables and paragraphs.
 * Uses the bundled `dom` extension; if it is unavailable it degrades to a
 * plain-text rendering rather than failing.
 */
final class HtmlToMarkdown
{
    public static function convert(string $html): string
    {
        $html = trim($html);

        if ($html === '') {
            return '';
        }

        if (! extension_loaded('dom')) {
            return self::fallback($html);
        }

        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        // The XML prolog forces UTF-8 interpretation of the markup.
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $body = $dom->getElementsByTagName('body')->item(0) ?? $dom->documentElement;

        $markdown = $body !== null ? self::children($body, false) : '';

        return self::normalize($markdown);
    }

    private static function children(DOMNode $node, bool $pre): string
    {
        $out = '';

        foreach ($node->childNodes as $child) {
            $out .= self::node($child, $pre);
        }

        return $out;
    }

    private static function node(DOMNode $node, bool $pre): string
    {
        if ($node->nodeType === XML_TEXT_NODE) {
            $text = $node->nodeValue ?? '';

            return $pre ? $text : (preg_replace('/\s+/', ' ', $text) ?? $text);
        }

        if (! $node instanceof DOMElement) {
            return '';
        }

        $tag = strtolower($node->nodeName);

        return match ($tag) {
            'script', 'style', 'head', 'title', 'noscript' => '',
            'br' => "\n",
            'hr' => "\n\n---\n\n",
            'h1', 'h2', 'h3', 'h4', 'h5', 'h6' => "\n\n".str_repeat('#', (int) substr($tag, 1)).' '.trim(self::children($node, $pre))."\n\n",
            'p' => "\n\n".trim(self::children($node, $pre))."\n\n",
            'strong', 'b' => self::wrapInline(self::children($node, $pre), '**'),
            'em', 'i' => self::wrapInline(self::children($node, $pre), '*'),
            'code' => $pre ? self::children($node, $pre) : self::wrapInline(self::children($node, $pre), '`'),
            'pre' => "\n\n```\n".trim($node->textContent)."\n```\n\n",
            'blockquote' => self::blockquote(trim(self::children($node, $pre))),
            'a' => self::link($node, $pre),
            'img' => self::image($node),
            'ul' => "\n".self::list($node, false)."\n",
            'ol' => "\n".self::list($node, true)."\n",
            'table' => "\n\n".self::table($node)."\n\n",
            default => self::children($node, $pre), // div, span, font, td, tr, ...
        };
    }

    private static function wrapInline(string $inner, string $marker): string
    {
        if (trim($inner) === '') {
            return $inner;
        }

        // Keep the emphasis markers tight against the text (Markdown requirement).
        preg_match('/^(\s*)(.*?)(\s*)$/s', $inner, $m);

        return $m[1].$marker.$m[2].$marker.$m[3];
    }

    private static function link(DOMElement $node, bool $pre): string
    {
        $text = trim(self::children($node, $pre));
        $href = trim($node->getAttribute('href'));

        if ($href === '' || $href === $text) {
            return $text;
        }

        return "[{$text}]({$href})";
    }

    private static function image(DOMElement $node): string
    {
        $src = trim($node->getAttribute('src'));

        if ($src === '') {
            return '';
        }

        return '!['.trim($node->getAttribute('alt'))."]({$src})";
    }

    private static function list(DOMElement $node, bool $ordered): string
    {
        $lines = [];
        $index = 1;

        foreach ($node->childNodes as $child) {
            if (! $child instanceof DOMElement || strtolower($child->nodeName) !== 'li') {
                continue;
            }

            $marker = $ordered ? ($index++.'. ') : '- ';
            $content = trim(self::children($child, false));
            // Indent any wrapped/nested lines beneath the marker.
            $content = str_replace("\n", "\n".str_repeat(' ', strlen($marker)), $content);
            $lines[] = $marker.$content;
        }

        return implode("\n", $lines);
    }

    private static function blockquote(string $text): string
    {
        if ($text === '') {
            return '';
        }

        $quoted = implode("\n", array_map(
            static fn (string $line): string => '> '.$line,
            explode("\n", $text)
        ));

        return "\n\n{$quoted}\n\n";
    }

    private static function table(DOMElement $node): string
    {
        $rows = [];

        foreach ($node->getElementsByTagName('tr') as $tr) {
            $cells = [];

            foreach ($tr->childNodes as $cell) {
                if ($cell instanceof DOMElement && in_array(strtolower($cell->nodeName), ['td', 'th'], true)) {
                    $cells[] = trim(preg_replace('/\s+/', ' ', self::children($cell, false)) ?? '');
                }
            }

            if ($cells !== []) {
                $rows[] = '| '.implode(' | ', $cells).' |';
            }
        }

        if ($rows === []) {
            return '';
        }

        // Markdown tables need a header separator row.
        $columns = max(1, substr_count($rows[0], '|') - 1);
        array_splice($rows, 1, 0, '|'.str_repeat(' --- |', $columns));

        return implode("\n", $rows);
    }

    private static function normalize(string $markdown): string
    {
        $markdown = preg_replace('/[ \t]+\n/', "\n", $markdown) ?? $markdown;
        $markdown = preg_replace('/\n{3,}/', "\n\n", $markdown) ?? $markdown;

        return trim($markdown);
    }

    private static function fallback(string $html): string
    {
        $html = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $html) ?? $html;
        $html = preg_replace('#<br\s*/?>#i', "\n", $html) ?? $html;
        $html = preg_replace('#</(p|div|tr|h[1-6]|li)>#i', "\n", $html) ?? $html;

        return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
