<?php

declare(strict_types=1);

use Melloww\MailFiles\Attachment;
use Melloww\MailFiles\Enums\AttachmentDisposition;
use Melloww\MailFiles\MailFile;

it('does not resolve attachment content until it is read', function () {
    $calls = 0;
    $attachment = new Attachment(
        filename: 'big.bin',
        contentType: 'application/octet-stream',
        content: function () use (&$calls): string {
            $calls++;

            return 'the-decoded-bytes';
        },
        disposition: AttachmentDisposition::Attachment,
    );

    // Reading metadata must not resolve the payload.
    expect($attachment->filename())->toBe('big.bin')
        ->and($attachment->contentType())->toBe('application/octet-stream')
        ->and($attachment->isInline())->toBeFalse()
        ->and($calls)->toBe(0);

    // First read resolves it; subsequent reads are memoised.
    expect($attachment->content())->toBe('the-decoded-bytes')
        ->and($attachment->size())->toBe(17)
        ->and($attachment->content())->toBe('the-decoded-bytes')
        ->and($calls)->toBe(1);
});

it('saveTo() writes the resolved bytes of a closure-backed attachment', function () {
    $attachment = new Attachment(
        filename: 'note.txt',
        contentType: 'text/plain',
        content: fn (): string => 'resolved payload',
    );

    $path = tempnam(sys_get_temp_dir(), 'mailfiles-');

    try {
        $written = $attachment->saveTo($path);

        expect($written)->toBe(16)
            ->and(file_get_contents($path))->toBe('resolved payload');
    } finally {
        @unlink($path);
    }
});

it('resolves an embedded message lazily', function () {
    $calls = 0;
    $attachment = new Attachment(
        filename: 'inner.eml',
        contentType: 'message/rfc822',
        content: 'irrelevant',
        embeddedMessage: function () use (&$calls): ?\Melloww\MailFiles\Email {
            $calls++;

            return null;
        },
    );

    expect($calls)->toBe(0);
    expect($attachment->isEmbeddedMessage())->toBeFalse()
        ->and($calls)->toBe(1);
});

it('still reads real attachments correctly after the lazy refactor', function () {
    // A regression guard tying the lazy path back to real fixtures.
    $eml = MailFile::read(fixture('eml/m0012.eml'));
    expect($eml->attachments()[0]->filename())->toBe('file.txt')
        ->and($eml->attachments()[0]->content())->toBeString();

    $msg = MailFile::read(fixture('msg/testMSG_att_msg.msg'));
    $pdf = collect($msg->attachments())->firstWhere(fn ($a) => $a->filename() === 'smbprn.00009008.KdcPjl.pdf');
    expect($pdf->content())->toStartWith('%PDF');
});
