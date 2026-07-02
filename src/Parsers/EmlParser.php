<?php

declare(strict_types=1);

namespace Melloww\MailFiles\Parsers;

use Melloww\MailFiles\Contracts\ParsesEml;
use Melloww\MailFiles\Email;
use Melloww\MailFiles\Enums\MailFormat;
use Melloww\MailFiles\ParseOptions;
use Melloww\MailFiles\Support\EmailFactory;
use Melloww\MailFiles\Support\HeaderParser;
use Melloww\MailFiles\Support\MimePart;
use Melloww\MailFiles\Support\MimeTreeReader;

/**
 * Dependency-free RFC 822 / MIME parser.
 *
 * Implements header unfolding, nested multipart trees and (via the shared
 * {@see MimeTreeReader}) quoted-printable / base64 transfer decoding, RFC 2047
 * encoded-words and charset conversion — without relying on any PHP extension
 * beyond mbstring.
 */
final class EmlParser implements ParsesEml
{
    private MimeTreeReader $reader;

    public function __construct(
        private readonly ParseOptions $options = new ParseOptions,
    ) {
        $this->reader = new MimeTreeReader;
    }

    public function parse(string $raw): Email
    {
        return $this->parseAtDepth($raw, 0);
    }

    /**
     * Parse only the envelope/metadata from a message, skipping the body and
     * attachments entirely. The input may be just the header block.
     */
    public function parseHeaders(string $raw): Email
    {
        [$headerBlock] = $this->splitHeadersAndBody($this->stripMboxPostmark($raw));

        return EmailFactory::headersOnly(MailFormat::Eml, HeaderParser::parse($headerBlock));
    }

    /**
     * Parse raw MIME bytes that are already known to live at the given nesting
     * depth (used when extracting messages carried inside other messages).
     */
    public function parseAtDepth(string $raw, int $depth): Email
    {
        $root = $this->parseEntity($this->stripMboxPostmark($raw));

        return $this->reader->toEmail($root, $this->options, $depth);
    }

    /**
     * Parse one entity (its own headers + body) into a MimePart, recursing into
     * the child parts of multipart containers.
     */
    private function parseEntity(string $raw): MimePart
    {
        [$headerBlock, $body] = $this->splitHeadersAndBody($raw);

        $part = new MimePart(HeaderParser::parse($headerBlock), $body);

        if ($part->isMultipart() && ($boundary = $part->boundary()) !== null && $boundary !== '') {
            foreach ($this->splitMultipart($body, $boundary) as $childRaw) {
                $part->children[] = $this->parseEntity($childRaw);
            }
        }

        return $part;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitHeadersAndBody(string $raw): array
    {
        $positions = [];
        foreach (["\r\n\r\n", "\n\n", "\r\r"] as $sep) {
            $pos = strpos($raw, $sep);
            if ($pos !== false) {
                $positions[$pos] = strlen($sep);
            }
        }

        if ($positions === []) {
            return [$raw, ''];
        }

        $offset = min(array_keys($positions));

        return [
            substr($raw, 0, $offset),
            substr($raw, $offset + $positions[$offset]),
        ];
    }

    /**
     * Split a multipart body into the raw bytes of each child part.
     *
     * @return list<string>
     */
    private function splitMultipart(string $body, string $boundary): array
    {
        // Prepend a CRLF so a boundary sitting at the very start of the body is matched.
        $haystack = "\r\n".$body;

        $pattern = '/(?:\r\n|\n|\r)--'.preg_quote($boundary, '/').'(--)?[^\r\n]*(?:\r\n|\n|\r|$)/';

        if (preg_match_all($pattern, $haystack, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER) === false) {
            return [];
        }

        $count = count($matches);
        if ($count < 2) {
            return [];
        }

        $parts = [];

        // Content of part k lives between delimiter k and delimiter k+1.
        for ($k = 0; $k < $count - 1; $k++) {
            $start = $matches[$k][0][1] + strlen($matches[$k][0][0]);
            $end = $matches[$k + 1][0][1];
            $parts[] = substr($haystack, $start, $end - $start);
        }

        return $parts;
    }

    private function stripMboxPostmark(string $raw): string
    {
        // Some exports prefix the message with an mbox "From " separator line.
        if (preg_match('/^From .*\r?\n/', $raw) === 1) {
            return preg_replace('/^From .*\r?\n/', '', $raw, 1) ?? $raw;
        }

        return $raw;
    }
}
