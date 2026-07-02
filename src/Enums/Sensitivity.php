<?php

declare(strict_types=1);

namespace Melloww\MailFiles\Enums;

/**
 * The confidentiality/sensitivity a message was marked with.
 */
enum Sensitivity: string
{
    case Normal = 'normal';

    case Personal = 'personal';

    case Private = 'private';

    case Confidential = 'confidential';
}
