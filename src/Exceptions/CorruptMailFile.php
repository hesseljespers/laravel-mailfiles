<?php

declare(strict_types=1);

namespace Melloww\MailFiles\Exceptions;

class CorruptMailFile extends MailFileException
{
    public static function make(string $reason): self
    {
        return new self("The mail file appears to be corrupt or unsupported: {$reason}");
    }
}
