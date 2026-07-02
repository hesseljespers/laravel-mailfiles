<?php

declare(strict_types=1);

use Melloww\MailFiles\Parsers\EmlParser;

it('selects text and the richest html from a nested alternative/related tree', function () {
    $raw = implode("\r\n", [
        'From: a@example.com',
        'Subject: nested',
        'Content-Type: multipart/mixed; boundary=MIX',
        '',
        '--MIX',
        'Content-Type: multipart/alternative; boundary=ALT',
        '',
        '--ALT',
        'Content-Type: text/plain; charset=UTF-8',
        '',
        'plain body text',
        '--ALT',
        'Content-Type: multipart/related; boundary=REL',
        '',
        '--REL',
        'Content-Type: text/html; charset=UTF-8',
        '',
        '<p>rich <b>html</b> body <img src="cid:logo"></p>',
        '--REL',
        'Content-Type: image/png',
        'Content-ID: <logo>',
        'Content-Transfer-Encoding: base64',
        '',
        base64_encode('PNGDATA'),
        '--REL--',
        '--ALT--',
        '--MIX',
        'Content-Type: application/pdf',
        'Content-Disposition: attachment; filename="doc.pdf"',
        'Content-Transfer-Encoding: base64',
        '',
        base64_encode('%PDF-1.4'),
        '--MIX--',
        '',
    ]);

    $email = (new EmlParser)->parse($raw);

    expect($email->textBody())->toBe('plain body text')
        ->and($email->htmlBody())->toContain('rich <b>html</b> body')
        // The html and the plain text are the body, not attachments.
        ->and($email->attachments())->toHaveCount(1)
        ->and($email->attachments()[0]->filename())->toBe('doc.pdf')
        // The related image is inline (referenced via cid:logo).
        ->and($email->inlineAttachments())->toHaveCount(1)
        ->and($email->inlineAttachments()[0]->contentId())->toBe('logo');
});

it('prefers the last (richest) representation in an alternative', function () {
    // Two html parts in an alternative: the later one is the one to keep.
    $raw = implode("\r\n", [
        'Subject: richest',
        'Content-Type: multipart/alternative; boundary=ALT',
        '',
        '--ALT',
        'Content-Type: text/html; charset=UTF-8',
        '',
        '<p>older</p>',
        '--ALT',
        'Content-Type: text/html; charset=UTF-8',
        '',
        '<p>newer richer</p>',
        '--ALT--',
        '',
    ]);

    expect((new EmlParser)->parse($raw)->htmlBody())->toBe('<p>newer richer</p>');
});
