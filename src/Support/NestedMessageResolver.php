<?php

declare(strict_types=1);

namespace Melloww\MailFiles\Support;

use Melloww\MailFiles\Email;
use Melloww\MailFiles\Ole\OleReader;
use Melloww\MailFiles\ParseOptions;
use Melloww\MailFiles\Parsers\EmlParser;
use Melloww\MailFiles\Parsers\MsgParser;
use Throwable;

/**
 * Decides whether an attachment is itself a mail message and, if so, parses it
 * into a nested {@see Email}. Works across formats: an `.eml` may carry a `.msg`
 * and vice versa.
 */
final class NestedMessageResolver
{
    public static function resolve(
        string $content,
        ?string $contentType,
        ?string $filename,
        ParseOptions $options,
        int $depth,
    ): ?Email {
        if (! $options->extractNestedMessages || $content === '' || $depth >= $options->maxDepth) {
            return null;
        }

        // A .msg is unambiguous thanks to the OLE2 signature.
        if (OleReader::isOle($content)) {
            return self::attempt(fn () => (new MsgParser($options))->parseAtDepth($content, $depth + 1));
        }

        $type = strtolower(trim((string) $contentType));
        $name = strtolower(trim((string) $filename));

        if ($type === 'message/rfc822' || str_ends_with($name, '.eml') || self::looksLikeEml($content)) {
            return self::attempt(fn () => (new EmlParser($options))->parseAtDepth($content, $depth + 1));
        }

        return null;
    }

    /**
     * @param  callable(): Email  $parser
     */
    private static function attempt(callable $parser): ?Email
    {
        try {
            return $parser();
        } catch (Throwable) {
            // A malformed nested message must never break parsing of the outer one.
            return null;
        }
    }

    /**
     * Conservative sniff for a raw RFC 822 message: the very first line must be a
     * header and a MIME-Version header must appear near the top.
     */
    private static function looksLikeEml(string $content): bool
    {
        $head = substr($content, 0, 2048);

        return preg_match('/^[A-Za-z][A-Za-z0-9-]*:[ \t]/', $head) === 1
            && preg_match('/^MIME-Version[ \t]*:/im', $head) === 1;
    }
}
