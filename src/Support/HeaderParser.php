<?php

declare(strict_types=1);

namespace Melloww\MailFiles\Support;

use Melloww\MailFiles\Headers;

/**
 * Parses a raw RFC 822 header block (folded lines included) into a Headers bag.
 *
 * Shared by the EML parser and by the MSG parser, which reads the original
 * transport headers out of a `PR_TRANSPORT_MESSAGE_HEADERS` property.
 */
final class HeaderParser
{
    public static function parse(string $block): Headers
    {
        $headers = new Headers;
        $lines = preg_split('/\r\n|\n|\r/', $block) ?: [];

        $name = null;
        $value = '';

        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }

            if ($name !== null && preg_match('/^[ \t]/', $line) === 1) {
                // Folded continuation of the previous header.
                $value .= ' '.ltrim($line);

                continue;
            }

            $colon = strpos($line, ':');
            if ($colon === false) {
                continue;
            }

            if ($name !== null) {
                $headers->add($name, $value);
            }

            $name = substr($line, 0, $colon);
            $value = ltrim(substr($line, $colon + 1));
        }

        if ($name !== null) {
            $headers->add($name, $value);
        }

        return $headers;
    }
}
