<?php

declare(strict_types=1);

namespace Melloww\MailFiles\Support;

use Melloww\MailFiles\Email;
use Melloww\MailFiles\Enums\ThreadEntryType;
use Melloww\MailFiles\Thread;
use Melloww\MailFiles\ThreadMessage;

/**
 * Assembles the {@see Thread} for an {@see Email} by combining, newest-first:
 *   1. the message itself (accurate),
 *   2. quoted forward/reply blocks parsed from the body (heuristic),
 *   3. originals attached as message/rfc822 or embedded .msg (accurate),
 *   4. a fallback derived from forwarding headers when nothing else is found.
 */
final class ThreadBuilder
{
    /** Headers that reveal the original author of a forwarded/resent message. */
    private const ORIGINAL_FROM_HEADERS = [
        'x-original-sender',
        'x-original-from',
        'x-ms-exchange-organization-originalsender',
        'resent-from',
        'x-forwarded-for',
    ];

    /** Headers that reveal the original recipient. */
    private const ORIGINAL_TO_HEADERS = ['x-original-to', 'resent-to'];

    public static function build(Email $email): Thread
    {
        $entries = [
            new ThreadMessage(
                type: ThreadEntryType::Message,
                from: $email->from(),
                to: $email->to(),
                cc: $email->cc(),
                date: $email->date(),
                subject: $email->subject(),
            ),
        ];

        // Parse both the plain-text body and the HTML-stripped body; Outlook
        // forwards frequently populate only one of them, so keep whichever
        // recovers more of the chain.
        $fromText = ThreadParser::parse($email->textBody() ?? '');
        $fromHtml = $email->hasHtmlBody() ? ThreadParser::parse($email->cleanHtml() ?? '') : [];
        $quoted = count($fromHtml) > count($fromText) ? $fromHtml : $fromText;

        foreach ($quoted as $message) {
            $entries[] = $message;
        }

        foreach ($email->embeddedMessages() as $attached) {
            $entries[] = new ThreadMessage(
                type: ThreadEntryType::Attached,
                from: $attached->from(),
                to: $attached->to(),
                cc: $attached->cc(),
                date: $attached->date(),
                subject: $attached->subject(),
            );
        }

        // Nothing quoted in the body and nothing attached: fall back to the
        // forwarding headers, if any.
        if (count($entries) === 1) {
            $fromHeaders = self::fromHeaders($email);

            if ($fromHeaders !== null) {
                $entries[] = $fromHeaders;
            }
        }

        return new Thread($entries);
    }

    private static function fromHeaders(Email $email): ?ThreadMessage
    {
        $from = null;
        foreach (self::ORIGINAL_FROM_HEADERS as $header) {
            $value = $email->header($header);
            if ($value !== null && trim($value) !== '') {
                $from = AddressParser::parseList($value)[0] ?? null;
                if ($from !== null) {
                    break;
                }
            }
        }

        $to = [];
        foreach (self::ORIGINAL_TO_HEADERS as $header) {
            $value = $email->header($header);
            if ($value !== null && trim($value) !== '') {
                $to = AddressParser::parseList($value);
                break;
            }
        }

        if ($from === null && $to === []) {
            return null;
        }

        return new ThreadMessage(type: ThreadEntryType::Forwarded, from: $from, to: $to);
    }
}
