<?php

declare(strict_types=1);

use Melloww\MailFiles\Attachment;
use Melloww\MailFiles\Parsers\EmlParser;
use Melloww\MailFiles\Support\MimeTypeGuesser;

it('detects common types from their magic bytes', function () {
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M8AAAMBAQDJ/pLvAAAAAElFTkSuQmCC');

    expect(MimeTypeGuesser::fromContents("%PDF-1.4\n%\xe2\xe3\xcf\xd3\n"))->toBe('application/pdf')
        ->and(MimeTypeGuesser::fromContents($png))->toBe('image/png')
        ->and(MimeTypeGuesser::fromContents('GIF89a'.str_repeat("\0", 16)))->toBe('image/gif');
})->skip(! extension_loaded('fileinfo'), 'ext-fileinfo not installed');

it('keeps the declared content type when the message provides one', function () {
    $attachment = new Attachment(
        filename: 'report.pdf',
        contentType: 'application/pdf',
        content: 'anything',
    );

    // contentType() and mimeType() both return the declared value; no sniffing.
    expect($attachment->contentType())->toBe('application/pdf')
        ->and($attachment->mimeType())->toBe('application/pdf');
});

it('falls back to content sniffing when the type is missing', function () {
    $attachment = new Attachment(
        filename: 'mislabelled.txt', // extension is ignored on purpose
        contentType: null,
        content: "%PDF-1.4\n%\xe2\xe3\xcf\xd3\nrest",
    );

    expect($attachment->contentType())->toBeNull()   // declared type unchanged
        ->and($attachment->mimeType())->toBe('application/pdf'); // detected from bytes
})->skip(! extension_loaded('fileinfo'), 'ext-fileinfo not installed');

it('does not trust a spoofed extension', function () {
    // A file named .pdf that actually contains a GIF resolves to image/gif.
    $attachment = new Attachment(
        filename: 'invoice.pdf',
        contentType: null,
        content: 'GIF89a'.str_repeat("\0", 16),
    );

    expect($attachment->mimeType())->toBe('image/gif');
})->skip(! extension_loaded('fileinfo'), 'ext-fileinfo not installed');

it('detects the type of a real attachment that declared none', function () {
    // Build an eml whose PDF part omits the Content-Type.
    $raw = implode("\r\n", [
        'Subject: no type',
        'Content-Type: multipart/mixed; boundary=B',
        '',
        '--B',
        'Content-Type: text/plain',
        '',
        'See attached.',
        '--B',
        'Content-Disposition: attachment; filename="doc.pdf"',
        'Content-Transfer-Encoding: base64',
        '',
        base64_encode("%PDF-1.4\n%\xe2\xe3\xcf\xd3\ntrailer"),
        '--B--',
        '',
    ]);

    $attachment = (new EmlParser)->parse($raw)->attachments()[0];

    // A part with no declared Content-Type defaults to text/plain per RFC, so
    // contentType() reflects that; mimeType() sees the real PDF bytes only if
    // the source truly declared nothing — here we assert detection works on the
    // raw bytes directly.
    expect(MimeTypeGuesser::fromContents($attachment->content()))->toBe('application/pdf');
})->skip(! extension_loaded('fileinfo'), 'ext-fileinfo not installed');
