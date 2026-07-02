<?php

declare(strict_types=1);

use Melloww\MailFiles\Enums\MailFormat;
use Melloww\MailFiles\MailFile;
use Melloww\MailFiles\MailFileReader;

it('reads eml headers only, without body or attachments', function () {
    $email = MailFile::headers(fixture('eml/m0012.eml'));

    expect($email->isHeadersOnly())->toBeTrue()
        ->and($email->format())->toBe(MailFormat::Eml)
        ->and($email->subject())->toBe('Hello World !')
        ->and($email->from()->email)->toBe('name@company.com')
        // Body and attachments are intentionally not resolved.
        ->and($email->body())->toBeNull()
        ->and($email->attachments())->toBe([])
        ->and($email->hasAttachments())->toBeFalse();
});

it('reads msg headers only, skipping body and attachments', function () {
    // testMSG_att_msg.msg has a PDF and an embedded message when read fully.
    $email = MailFile::headers(fixture('msg/testMSG_att_msg.msg'));

    expect($email->isHeadersOnly())->toBeTrue()
        ->and($email->format())->toBe(MailFormat::Msg)
        ->and($email->subject())->toBe('test email')
        ->and($email->from())->not->toBeNull()
        ->and($email->textBody())->toBeNull()
        ->and($email->htmlBody())->toBeNull()
        ->and($email->rtfBody())->toBeNull()
        ->and($email->attachments())->toBe([])
        ->and($email->embeddedMessages())->toBe([]);
});

it('recovers metadata in a headers-only read', function () {
    $raw = implode("\r\n", [
        'From: a@example.com',
        'To: b@example.com',
        'Subject: RE: hi',
        'In-Reply-To: <parent@example.com>',
        'Importance: high',
        '',
        'This body should be ignored by a headers-only read.',
    ]);

    $email = (new MailFileReader)->readHeadersString($raw);

    expect($email->subject())->toBe('RE: hi')
        ->and($email->inReplyTo())->toBe('parent@example.com')
        ->and($email->priority()->value)->toBe('high')
        ->and($email->body())->toBeNull();
});

it('gives the same envelope as a full read', function () {
    $full = MailFile::read(fixture('eml/m0023.eml'));
    $headers = MailFile::headers(fixture('eml/m0023.eml'));

    expect($headers->subject())->toBe($full->subject())
        ->and($headers->from()->toString())->toBe($full->from()->toString())
        ->and($headers->to()[0]->email)->toBe($full->to()[0]->email);
});
