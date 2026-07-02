<?php

declare(strict_types=1);

namespace Melloww\MailFiles\Exceptions;

class UnknownMailFormat extends MailFileException
{
    public static function make(?string $hint = null): self
    {
        $suffix = $hint ? " ({$hint})" : '';

        return new self(
            'Could not determine whether the input is an EML (RFC 822) or MSG (Outlook) message'.$suffix.'.'
        );
    }
}
