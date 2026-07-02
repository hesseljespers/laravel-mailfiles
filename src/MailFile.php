<?php

declare(strict_types=1);

namespace Melloww\MailFiles;

use Melloww\MailFiles\Enums\MailFormat;

/**
 * Framework-agnostic static entry point for reading `.eml` and `.msg` files.
 *
 * Useful outside of Laravel (or when you just want a one-liner):
 *
 *     $email = MailFile::read('/path/to/message.msg');
 *     echo $email->subject();
 *
 * Inside a Laravel application you can equally use the `MailFiles` facade,
 * which resolves a container-managed, config-aware reader.
 */
final class MailFile
{
    public static function read(string $path): Email
    {
        return self::reader()->read($path);
    }

    public static function fromString(string $contents): Email
    {
        return self::reader()->readString($contents);
    }

    /**
     * Read only the envelope + metadata (no body/attachments) — for indexing.
     */
    public static function headers(string $path): Email
    {
        return self::reader()->readHeaders($path);
    }

    public static function eml(string $path): Email
    {
        return self::reader()->readEml($path);
    }

    public static function msg(string $path): Email
    {
        return self::reader()->readMsg($path);
    }

    public static function detectFormat(string $contents): MailFormat
    {
        return self::reader()->detectFormat($contents);
    }

    /**
     * Stream every message from a Unix mbox spool.
     *
     * @return \Generator<int, Email>
     */
    public static function mbox(string $path): \Generator
    {
        yield from self::reader()->readMbox($path);
    }

    /**
     * Read every .eml/.msg file in a directory, keyed by path.
     *
     * @return \Generator<string, Email>
     */
    public static function directory(string $path, bool $recursive = false): \Generator
    {
        yield from self::reader()->readDirectory($path, $recursive);
    }

    public static function reader(): MailFileReader
    {
        return new MailFileReader;
    }
}
