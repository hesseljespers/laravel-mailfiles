<?php

declare(strict_types=1);

namespace Melloww\MailFiles\Support;

use Melloww\MailFiles\Address;
use Melloww\MailFiles\Email;
use Melloww\MailFiles\Enums\MailFormat;
use Melloww\MailFiles\Headers;

/**
 * Builds a headers-only {@see Email} from a header bag — the envelope and
 * metadata without touching bodies, attachments, calendars or nested messages.
 * Used by the metadata-only read path (e.g. inbox indexing).
 */
final class EmailFactory
{
    public static function headersOnly(MailFormat $format, Headers $headers): Email
    {
        return new Email(
            format: $format,
            subject: self::decoded($headers, 'subject'),
            from: self::firstAddress($headers->get('from')),
            sender: self::firstAddress($headers->get('sender')),
            to: AddressParser::parseList($headers->get('to')),
            cc: AddressParser::parseList($headers->get('cc')),
            bcc: AddressParser::parseList($headers->get('bcc')),
            replyTo: AddressParser::parseList($headers->get('reply-to')),
            date: DateParser::parse($headers->get('date')),
            messageId: self::messageId($headers),
            headers: $headers,
            headersOnly: true,
        );
    }

    private static function firstAddress(?string $headerValue): ?Address
    {
        return AddressParser::parseList($headerValue)[0] ?? null;
    }

    private static function decoded(Headers $headers, string $name): ?string
    {
        $value = $headers->get($name);

        return $value !== null ? MimeHeaderDecoder::decode($value) : null;
    }

    private static function messageId(Headers $headers): ?string
    {
        $value = $headers->get('message-id');

        if ($value === null) {
            return null;
        }

        $value = trim($value, " \t<>");

        return $value === '' ? null : $value;
    }
}
