<?php

declare(strict_types=1);

use Melloww\MailFiles\Email;
use Melloww\MailFiles\Enums\MailFormat;
use Melloww\MailFiles\Ole\OleReader;
use Melloww\MailFiles\Parsers\MsgParser;

function parseMsg(string $fixture): Email
{
    return (new MsgParser)->parse(file_get_contents(fixture("msg/{$fixture}")));
}

it('detects the OLE2 compound-file signature', function () {
    expect(OleReader::isOle(file_get_contents(fixture('msg/testMSG.msg'))))->toBeTrue()
        ->and(OleReader::isOle('not an ole file'))->toBeFalse();
});

it('reads the envelope of an Outlook message', function () {
    $email = parseMsg('testMSG.msg');

    expect($email->format())->toBe(MailFormat::Msg)
        ->and($email->subject())->toBe('MIME registry use cases')
        ->and($email->from()->email)->toBe('jukka.zitting@gmail.com')
        ->and($email->from()->name)->toBe('Jukka Zitting')
        ->and($email->to()[0]->email)->toBe('tika-dev@lucene.apache.org')
        ->and($email->messageId())->toBe('510143ac0901291116s5f368dfby9d6423ce61acc36a@mail.gmail.com')
        ->and($email->date()?->format('Y-m-d'))->toBe('2009-01-29')
        ->and($email->textBody())->toContain('Hi,');
});

it('decodes multi-byte (Chinese) subject, body and html', function () {
    $email = parseMsg('testMSG_chinese.msg');

    expect($email->subject())->toContain('格式測試')
        ->and($email->hasHtmlBody())->toBeTrue()
        ->and($email->body())->toContain('中文測試');
});

it('reads a legacy Outlook Express message with an accented sender name', function () {
    $email = parseMsg('test-outlook.msg');

    expect($email->subject())->toBe('Microsoft Outlook Express 6')
        ->and($email->from()->email)->toBe('msoe@microsoft.com')
        ->and($email->from()->name)->toContain('Équipe')
        ->and($email->textBody())->not->toBeEmpty();
});

it('produces a populated headers bag even without transport headers', function () {
    $headers = parseMsg('test-outlook.msg')->headers();

    expect($headers->get('Subject'))->toBe('Microsoft Outlook Express 6')
        ->and($headers->has('From'))->toBeTrue();
});
