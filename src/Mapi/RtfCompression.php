<?php

declare(strict_types=1);

namespace Melloww\MailFiles\Mapi;

/**
 * Decompresses the "compressed RTF" stored in the MAPI PR_RTF_COMPRESSED
 * property, per [MS-OXRTFCP]. Both the LZ77-derived compressed form (magic
 * "LZFu") and the stored/uncompressed form (magic "MELA") are handled.
 */
final class RtfCompression
{
    private const MAGIC_COMPRESSED = 0x75465A4C;   // 'LZFu'

    private const MAGIC_UNCOMPRESSED = 0x414C454D;  // 'MELA'

    private const DICTIONARY_SIZE = 4096;

    /**
     * The dictionary is pre-seeded with this exact 207-byte RTF prelude (single
     * quotes keep the backslashes literal; the CRLF is inserted explicitly).
     */
    private const INIT_DICTIONARY =
        '{\rtf1\ansi\mac\deff0\deftab720{\fonttbl;}{\f0\fnil \froman \fswiss \fmodern '
        .'\fscript \fdecor MS Sans SerifSymbolArialTimes New RomanCourier'
        .'{\colortbl\red0\green0\blue0'
        ."\r\n"
        .'\par \pard\plain\f0\fs20\b\i\u\tab\tx';

    public static function decompress(string $data): ?string
    {
        if (strlen($data) < 16) {
            return null;
        }

        $rawSize = self::u32($data, 4);
        $magic = self::u32($data, 8);

        if ($magic === self::MAGIC_UNCOMPRESSED) {
            return substr($data, 16, $rawSize);
        }

        if ($magic !== self::MAGIC_COMPRESSED) {
            return null;
        }

        return self::inflate(substr($data, 16), $rawSize);
    }

    private static function inflate(string $input, int $rawSize): string
    {
        $dictionary = self::INIT_DICTIONARY.str_repeat(' ', self::DICTIONARY_SIZE - strlen(self::INIT_DICTIONARY));
        $writePos = strlen(self::INIT_DICTIONARY);

        $output = '';
        $length = strlen($input);
        $pos = 0;

        while ($pos < $length && strlen($output) < $rawSize) {
            $flags = ord($input[$pos++]);

            for ($bit = 0; $bit < 8; $bit++) {
                if (($flags & (1 << $bit)) !== 0) {
                    // Dictionary reference (two bytes: 12-bit offset, 4-bit length).
                    if ($pos + 1 >= $length) {
                        return substr($output, 0, $rawSize);
                    }

                    $hi = ord($input[$pos++]);
                    $lo = ord($input[$pos++]);
                    $offset = ($hi << 4) | ($lo >> 4);
                    $runLength = ($lo & 0x0F) + 2;

                    // A reference to the current write position marks the end.
                    if ($offset === $writePos) {
                        return substr($output, 0, $rawSize);
                    }

                    for ($i = 0; $i < $runLength; $i++) {
                        $char = $dictionary[$offset % self::DICTIONARY_SIZE];
                        $output .= $char;
                        $dictionary[$writePos % self::DICTIONARY_SIZE] = $char;
                        $writePos = ($writePos + 1) % self::DICTIONARY_SIZE;
                        $offset++;
                    }
                } else {
                    // Literal byte.
                    if ($pos >= $length) {
                        return substr($output, 0, $rawSize);
                    }

                    $char = $input[$pos++];
                    $output .= $char;
                    $dictionary[$writePos % self::DICTIONARY_SIZE] = $char;
                    $writePos = ($writePos + 1) % self::DICTIONARY_SIZE;
                }

                if (strlen($output) >= $rawSize) {
                    break;
                }
            }
        }

        return substr($output, 0, $rawSize);
    }

    private static function u32(string $data, int $offset): int
    {
        $slice = substr($data, $offset, 4);

        return strlen($slice) === 4 ? (unpack('V', $slice)[1] ?? 0) : 0;
    }
}
