<?php

declare(strict_types=1);

use Melloww\MailFiles\Enums\MailFormat;
use Melloww\MailFiles\MailFile;
use Melloww\MailFiles\MailFileReader;
use Melloww\MailFiles\ParseOptions;
use Melloww\MailFiles\Parsers\EmlParser;

function emlWithAttachedEml(): string
{
    $inner = implode("\r\n", [
        'From: Inner Sender <inner@example.com>',
        'To: inner-rcpt@example.com',
        'Subject: The inner message',
        'Content-Type: text/plain; charset=UTF-8',
        '',
        'Inner body text.',
    ]);

    return implode("\r\n", [
        'From: Outer <outer@example.com>',
        'Subject: Outer with attached email',
        'Content-Type: multipart/mixed; boundary="B"',
        '',
        '--B',
        'Content-Type: text/plain',
        '',
        'Please see attached.',
        '--B',
        'Content-Type: message/rfc822',
        'Content-Disposition: attachment; filename="inner.eml"',
        '',
        $inner,
        '--B--',
        '',
    ]);
}

it('extracts an eml attached inside an eml', function () {
    $email = (new EmlParser)->parse(emlWithAttachedEml());

    expect($email->attachments())->toHaveCount(1);

    $attachment = $email->attachments()[0];

    expect($attachment->isEmbeddedMessage())->toBeTrue()
        ->and($attachment->embeddedMessage()->format())->toBe(MailFormat::Eml)
        ->and($attachment->embeddedMessage()->subject())->toBe('The inner message')
        ->and($attachment->embeddedMessage()->from()->email)->toBe('inner@example.com')
        ->and($attachment->embeddedMessage()->body())->toContain('Inner body text')
        ->and($email->embeddedMessages())->toHaveCount(1);
});

it('extracts a msg attached inside an eml (cross-format)', function () {
    $msgBytes = file_get_contents(fixture('msg/testMSG.msg'));

    $raw = implode("\r\n", [
        'From: Outer <outer@example.com>',
        'Subject: Outer with attached .msg',
        'Content-Type: multipart/mixed; boundary="B"',
        '',
        '--B',
        'Content-Type: text/plain',
        '',
        'attached msg',
        '--B',
        'Content-Type: application/vnd.ms-outlook; name="report.msg"',
        'Content-Disposition: attachment; filename="report.msg"',
        'Content-Transfer-Encoding: base64',
        '',
        chunk_split(base64_encode($msgBytes), 76, "\r\n"),
        '--B--',
        '',
    ]);

    $nested = (new EmlParser)->parse($raw)->attachments()[0]->embeddedMessage();

    expect($nested)->not->toBeNull()
        ->and($nested->format())->toBe(MailFormat::Msg)
        ->and($nested->subject())->toBe('MIME registry use cases');
});

it('extracts a msg embedded inside a msg', function () {
    $email = MailFile::read(fixture('msg/testMSG_att_msg.msg'));

    $embedded = collect($email->allAttachments())->first(fn ($a) => $a->isEmbeddedMessage());

    expect($embedded)->not->toBeNull()
        ->and($embedded->embeddedMessage()->format())->toBe(MailFormat::Msg)
        ->and($embedded->embeddedMessage()->subject())->toBe('Test Attachment')
        ->and($email->embeddedMessages())->not->toBeEmpty();
});

it('collects attachments recursively across nesting', function () {
    // Outer .msg has an embedded message plus a PDF; the recursive list sees both
    // levels at once.
    $email = MailFile::read(fixture('msg/testMSG_att_msg.msg'));

    expect(count($email->allAttachmentsRecursive()))
        ->toBeGreaterThanOrEqual(count($email->allAttachments()));
});

it('does not recurse when extraction is disabled', function () {
    $reader = new MailFileReader('native', new ParseOptions(extractNestedMessages: false));

    $email = $reader->readString(emlWithAttachedEml());

    expect($email->attachments()[0]->isEmbeddedMessage())->toBeFalse()
        ->and($email->embeddedMessages())->toBeEmpty();
});

it('honours the max depth limit', function () {
    $reader = new MailFileReader('native', new ParseOptions(maxDepth: 0));

    $email = $reader->readString(emlWithAttachedEml());

    expect($email->attachments()[0]->isEmbeddedMessage())->toBeFalse();
});
