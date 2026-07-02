<?php

declare(strict_types=1);

namespace Melloww\MailFiles\Enums;

/**
 * Where a message in a thread came from / how confidently it was recovered.
 */
enum ThreadEntryType: string
{
    /** The message being read itself (always the first, newest entry). */
    case Message = 'message';

    /** A quoted "forwarded message" block detected in the body. */
    case Forwarded = 'forwarded';

    /** A quoted reply attribution ("On … wrote:") detected in the body. */
    case Reply = 'reply';

    /** An original message recovered structurally from an attached email. */
    case Attached = 'attached';
}
