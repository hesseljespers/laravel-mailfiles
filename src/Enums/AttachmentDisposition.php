<?php

declare(strict_types=1);

namespace Melloww\MailFiles\Enums;

/**
 * What role an attachment plays in a message.
 */
enum AttachmentDisposition: string
{
    /**
     * A genuine attachment: a file the sender deliberately attached and that a
     * user would expect to see in an "attachments" list and be able to download.
     */
    case Attachment = 'attachment';

    /**
     * An embedded resource that belongs to the body itself — a signature logo, a
     * social-media icon, a tracking pixel or any image referenced from the HTML
     * through a `cid:` URL. It renders inline and is usually *not* what someone
     * means by "the attachments".
     */
    case Inline = 'inline';
}
