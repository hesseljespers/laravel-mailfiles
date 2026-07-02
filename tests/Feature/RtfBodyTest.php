<?php

declare(strict_types=1);

use Melloww\MailFiles\MailFile;
use Melloww\MailFiles\Mapi\RtfCompression;
use Melloww\MailFiles\Mapi\RtfConverter;

it('exposes the decompressed RTF body of a msg', function () {
    $email = MailFile::read(fixture('msg/testMSG.msg'));

    expect($email->hasRtfBody())->toBeTrue()
        ->and($email->rtfBody())->toStartWith('{\\rtf1');
});

it('recovers HTML encapsulated in RTF when a msg has no HTML body', function () {
    // test-outlook.msg ships only a plain body + \fromhtml RTF.
    $email = MailFile::read(fixture('msg/test-outlook.msg'));

    expect($email->hasRtfBody())->toBeTrue()
        ->and($email->hasHtmlBody())->toBeTrue()
        ->and($email->htmlBody())->toContain('<')
        ->and(strtolower($email->htmlBody()))->toContain('html');
});

it('decodes multi-byte characters from RTF (Big5)', function () {
    $email = MailFile::read(fixture('msg/testMSG_chinese.msg'));

    // Whatever the body source, the Chinese text must survive as UTF-8.
    expect($email->body())->toContain('中文');
});

it('decompresses stored (uncompressed) RTF', function () {
    $raw = 'hello world';
    $blob = pack('V', strlen($raw) + 12) // compSize
        .pack('V', strlen($raw))         // rawSize
        .pack('V', 0x414C454D)           // magic 'MELA' (uncompressed)
        .pack('V', 0)                    // crc
        .$raw;

    expect(RtfCompression::decompress($blob))->toBe($raw);
});

it('returns null when RTF does not encapsulate HTML', function () {
    $rtf = '{\\rtf1\\ansi\\fromtext hello\\par }';

    expect(RtfConverter::toHtml($rtf))->toBeNull()
        ->and(RtfConverter::toText($rtf))->toContain('hello');
});
