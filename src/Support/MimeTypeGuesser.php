<?php

declare(strict_types=1);

namespace Melloww\MailFiles\Support;

use finfo;

/**
 * Detects a MIME type from the actual bytes of a payload (libmagic, via the
 * fileinfo extension).
 *
 * Content sniffing is used deliberately instead of trusting the file-name
 * extension: an extension is attacker-controlled metadata and can lie about
 * what a payload really is, whereas the magic-number signature reflects the
 * real content.
 */
final class MimeTypeGuesser
{
    /**
     * @return string|null the detected MIME type, or null when it cannot be determined
     */
    public static function fromContents(string $contents): ?string
    {
        if ($contents === '' || ! extension_loaded('fileinfo')) {
            return null;
        }

        // The magic signature lives at the start; a prefix is enough and avoids
        // scanning very large payloads.
        $sample = strlen($contents) > 8192 ? substr($contents, 0, 8192) : $contents;

        $detected = (new finfo(FILEINFO_MIME_TYPE))->buffer($sample);

        if ($detected === false || $detected === '') {
            return null;
        }

        return strtolower($detected);
    }
}
