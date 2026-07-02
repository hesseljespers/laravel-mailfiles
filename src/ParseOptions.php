<?php

declare(strict_types=1);

namespace Melloww\MailFiles;

/**
 * Tunable options shared by every parser.
 */
final class ParseOptions
{
    public function __construct(
        /**
         * Parse messages that are themselves carried as attachments
         * (a `.eml` inside a `.eml`, a `.msg` inside a `.msg`, or one inside the
         * other) into nested {@see Email} objects reachable through
         * {@see Attachment::embeddedMessage()}.
         */
        public readonly bool $extractNestedMessages = true,

        /**
         * Maximum nesting depth to follow when extracting nested messages.
         * Guards against pathological or malicious deeply-nested files.
         */
        public readonly int $maxDepth = 5,
    ) {}
}
