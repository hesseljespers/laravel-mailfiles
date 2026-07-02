<?php

declare(strict_types=1);

namespace Melloww\MailFiles\Enums;

/**
 * The importance/priority a message was sent with.
 */
enum Priority: string
{
    case High = 'high';

    case Normal = 'normal';

    case Low = 'low';
}
