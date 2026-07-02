<?php

declare(strict_types=1);

namespace Melloww\MailFiles\Enums;

/**
 * The on-disk container format a parsed message originated from.
 */
enum MailFormat: string
{
    /** RFC 822 / MIME message, typically a `.eml` file. */
    case Eml = 'eml';

    /** Microsoft Outlook MAPI message stored in an OLE2 compound file, typically a `.msg` file. */
    case Msg = 'msg';

    /**
     * The conventional file extension for this format (without a leading dot).
     */
    public function extension(): string
    {
        return $this->value;
    }

    /**
     * A human friendly label.
     */
    public function label(): string
    {
        return match ($this) {
            self::Eml => 'RFC 822 (EML)',
            self::Msg => 'Outlook MAPI (MSG)',
        };
    }
}
