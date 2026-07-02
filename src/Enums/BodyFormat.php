<?php

declare(strict_types=1);

namespace Melloww\MailFiles\Enums;

/**
 * The representation to return a message body in.
 */
enum BodyFormat: string
{
    case Html = 'html';

    case Text = 'text';

    case Markdown = 'markdown';
}
