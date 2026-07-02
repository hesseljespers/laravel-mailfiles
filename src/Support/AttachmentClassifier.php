<?php

declare(strict_types=1);

namespace Melloww\MailFiles\Support;

use Melloww\MailFiles\Enums\AttachmentDisposition;

/**
 * Decides whether a message part is a genuine attachment or an inline/embedded
 * resource (a signature logo, social icon, tracking pixel, ...).
 *
 * The Content-Disposition header alone is unreliable — many clients, Outlook in
 * particular, label body images as "attachment". The dependable signal is
 * whether the body actually *references* the part, so that drives the decision;
 * the disposition header and MIME structure are used as supporting evidence.
 */
final class AttachmentClassifier
{
    public static function classify(
        ?string $htmlBody,
        ?string $contentId,
        ?string $contentLocation,
        bool $explicitInline,
        bool $explicitAttachment,
        bool $insideRelated,
        bool $isImage,
    ): AttachmentDisposition {
        // 1. Referenced from the HTML body => it is part of the body, not a file.
        if ($htmlBody !== null && $htmlBody !== '') {
            if ($contentId !== null && self::referencesContentId($htmlBody, $contentId)) {
                return AttachmentDisposition::Inline;
            }

            if ($contentLocation !== null && $contentLocation !== '' && str_contains($htmlBody, $contentLocation)) {
                return AttachmentDisposition::Inline;
            }
        }

        // 2. The source explicitly said "inline".
        if ($explicitInline) {
            return AttachmentDisposition::Inline;
        }

        // 3. Images grouped with the body in a multipart/related container are
        //    body resources by construction.
        if ($insideRelated && $isImage) {
            return AttachmentDisposition::Inline;
        }

        // 4. Everything else is treated as a real attachment (an unreferenced
        //    image carrying a stray Content-ID is still a file the user attached).
        unset($explicitAttachment);

        return AttachmentDisposition::Attachment;
    }

    private static function referencesContentId(string $htmlBody, string $contentId): bool
    {
        $id = trim($contentId, " \t<>");

        if ($id === '') {
            return false;
        }

        return stripos($htmlBody, 'cid:'.$id) !== false;
    }
}
