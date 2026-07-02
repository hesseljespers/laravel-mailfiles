<?php

declare(strict_types=1);

namespace Melloww\MailFiles\Exceptions;

use Exception;

/**
 * Base exception for every error raised while reading a mail file.
 */
class MailFileException extends Exception
{
    public static function fileNotReadable(string $path): self
    {
        return new self("The mail file [{$path}] does not exist or is not readable.");
    }

    public static function emptyInput(): self
    {
        return new self('Cannot parse an empty string as a mail file.');
    }
}
