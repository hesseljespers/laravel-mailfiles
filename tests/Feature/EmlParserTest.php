<?php

declare(strict_types=1);

use Melloww\MailFiles\Email;
use Melloww\MailFiles\Enums\MailFormat;
use Melloww\MailFiles\Parsers\EmlParser;

function parseEml(string $fixture): Email
{
    return (new EmlParser)->parse(file_get_contents(fixture("eml/{$fixture}")));
}

it('reads the envelope of a simple message', function () {
    $email = parseEml('m0012.eml');

    expect($email->format())->toBe(MailFormat::Eml)
        ->and($email->subject())->toBe('Hello World !')
        ->and($email->from()->email)->toBe('name@company.com')
        ->and($email->from()->name)->toBe('Name')
        ->and($email->messageId())->toBe('CAH_ZkV=-31o34hfbOtyAcQCy-wgiGfaWgmH0tZ39UDCWKWSjBQ@mail.gmail.com')
        ->and($email->date()?->format('Y-m-d'))->toBe('2014-03-16');
});

it('decodes an RFC 2047 encoded subject', function () {
    expect(parseEml('m0001.eml')->subject())->toBe('Mail avec fichier attaché de 1ko');
});

it('decodes encoded-word and UTF-8 addresses', function () {
    $email = parseEml('m0023.eml');

    expect($email->from()->toString())->toBe('Keith Moore <moore@cs.utk.edu>')
        ->and($email->to()[0]->name)->toBe('Keld Jørn Simonsen')
        ->and($email->to()[0]->email)->toBe('keld@dkuug.dk')
        ->and($email->cc()[0]->name)->toBe('André Pirard');
});

it('extracts both text and html bodies from multipart/alternative', function () {
    $email = parseEml('testRFC822-multipart.eml');

    expect($email->hasTextBody())->toBeTrue()
        ->and($email->hasHtmlBody())->toBeTrue()
        ->and($email->textBody())->toContain('This is a test for parsing multi-part mails')
        ->and($email->htmlBody())->toContain('<span');
});

it('decodes a base64 encoded body with international characters', function () {
    expect(parseEml('testRFC822_base64.eml')->body())->toContain('voilà');
});

it('reads attachments with their filename, type and size', function () {
    $email = parseEml('m0012.eml');

    expect($email->attachments())->toHaveCount(1)
        ->and($email->attachments()[0]->filename())->toBe('file.txt')
        ->and($email->attachments()[0]->contentType())->toBe('text/plain')
        ->and($email->attachments()[0]->extension())->toBe('txt');
});

it('decodes non-ascii attachment filenames', function () {
    $email = parseEml('m0018.eml');

    $names = array_map(fn ($a) => $a->filename(), $email->attachments());

    expect($names)->toContain('사진.JPG');
});

it('finds the gif attachment in the multipart message', function () {
    $email = parseEml('testRFC822-multipart.eml');

    $gif = collect($email->allAttachments())->firstWhere(fn ($a) => $a->filename() === 'logo.gif');

    expect($gif)->not->toBeNull()
        ->and($gif->contentType())->toBe('image/gif')
        ->and($gif->content())->toStartWith('GIF8'); // GIF magic bytes => binary correctly decoded
});

it('parses a synthetic message covering encoded words, quoted names and nested multipart', function () {
    $raw = implode("\r\n", [
        'From: =?UTF-8?B?'.base64_encode('Müller').'?= <m@example.com>',
        'To: a@example.com, "Doe, John" <john@example.com>',
        'Subject: =?UTF-8?Q?Hello_=E2=82=AC?=',
        'Date: Mon, 27 Sep 2010 14:29:16 +0100',
        'Message-ID: <abc@example.com>',
        'Content-Type: multipart/mixed; boundary="OUTER"',
        '',
        '--OUTER',
        'Content-Type: multipart/alternative; boundary="INNER"',
        '',
        '--INNER',
        'Content-Type: text/plain; charset=UTF-8',
        '',
        'Plain body',
        '--INNER',
        'Content-Type: text/html; charset=UTF-8',
        '',
        '<p>HTML body</p>',
        '--INNER--',
        '--OUTER',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Disposition: attachment; filename="note.txt"',
        'Content-Transfer-Encoding: base64',
        '',
        base64_encode('attached file contents'),
        '--OUTER--',
        '',
    ]);

    $email = (new EmlParser)->parse($raw);

    expect($email->subject())->toBe('Hello €')
        ->and($email->from()->name)->toBe('Müller')
        ->and($email->from()->email)->toBe('m@example.com')
        ->and($email->to())->toHaveCount(2)
        ->and($email->to()[1]->name)->toBe('Doe, John')
        ->and($email->textBody())->toBe('Plain body')
        ->and($email->htmlBody())->toBe('<p>HTML body</p>')
        ->and($email->body())->toBe('Plain body')
        ->and($email->attachments())->toHaveCount(1)
        ->and($email->attachments()[0]->filename())->toBe('note.txt')
        ->and($email->attachments()[0]->content())->toBe('attached file contents');
});

it('tolerates an mbox "From " postmark line', function () {
    expect(parseEml('m0002.eml')->subject())->toBe('Mail avec fichier attaché de 3ko');
});
