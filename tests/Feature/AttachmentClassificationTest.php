<?php

declare(strict_types=1);

use Melloww\MailFiles\Enums\AttachmentDisposition;
use Melloww\MailFiles\MailFile;

it('separates genuine attachments from inline/embedded body images', function () {
    $email = MailFile::read(fixture('eml/signature-with-inline-images.eml'));

    $real = array_map(fn ($a) => $a->filename(), $email->attachments());
    $inline = array_map(fn ($a) => $a->contentId(), $email->inlineAttachments());

    expect($email->allAttachments())->toHaveCount(6)
        ->and($email->attachments())->toHaveCount(2)
        ->and($email->inlineAttachments())->toHaveCount(4)
        ->and($real)->toEqualCanonicalizing(['Q3-report.pdf', 'team-photo.jpg'])
        ->and($inline)->toEqualCanonicalizing(['logo', 'linkedin', 'twitter', 'pixel']);
});

it('treats a body-referenced image as inline even when it is marked as an attachment', function () {
    // linkedin.png carries "Content-Disposition: attachment" but the HTML body
    // references it through cid:linkedin — the classic Outlook footer-icon case.
    $email = MailFile::read(fixture('eml/signature-with-inline-images.eml'));

    $linkedin = collect($email->allAttachments())->firstWhere(fn ($a) => $a->contentId() === 'linkedin');

    expect($linkedin)->not->toBeNull()
        ->and($linkedin->disposition())->toBe(AttachmentDisposition::Inline)
        ->and($linkedin->isInline())->toBeTrue();
});

it('keeps a genuine image attachment as a real attachment', function () {
    // team-photo.jpg is an image but is not referenced anywhere in the body,
    // so it must remain a real attachment rather than being hidden as inline.
    $email = MailFile::read(fixture('eml/signature-with-inline-images.eml'));

    $photo = collect($email->attachments())->firstWhere(fn ($a) => $a->filename() === 'team-photo.jpg');

    expect($photo)->not->toBeNull()
        ->and($photo->disposition())->toBe(AttachmentDisposition::Attachment)
        ->and($photo->isInline())->toBeFalse();
});

it('flags inline images through isEmbeddedImage()', function () {
    $email = MailFile::read(fixture('eml/signature-with-inline-images.eml'));

    expect(collect($email->inlineAttachments())->every(fn ($a) => $a->isEmbeddedImage()))->toBeTrue()
        ->and(collect($email->attachments())->every(fn ($a) => ! $a->isEmbeddedImage()))->toBeTrue();
});

it('classifies Outlook MHTML-referenced images as inline (msg)', function () {
    // test-outlook2003.msg embeds 11 images referenced by the body; none are
    // real attachments.
    $email = MailFile::read(fixture('msg/test-outlook2003.msg'));

    expect($email->attachments())->toBeEmpty()
        ->and($email->inlineAttachments())->toHaveCount(11)
        ->and(collect($email->inlineAttachments())->every(fn ($a) => $a->disposition() === AttachmentDisposition::Inline))->toBeTrue();
});

it('keeps a real attachment and an embedded message as attachments (msg)', function () {
    $email = MailFile::read(fixture('msg/testMSG_att_msg.msg'));

    $names = array_map(fn ($a) => $a->filename(), $email->attachments());

    expect($email->attachments())->toHaveCount(2)
        ->and($email->inlineAttachments())->toBeEmpty()
        ->and($names)->toContain('smbprn.00009008.KdcPjl.pdf');
});
