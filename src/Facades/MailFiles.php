<?php

declare(strict_types=1);

namespace Melloww\MailFiles\Facades;

use Illuminate\Support\Facades\Facade;
use Melloww\MailFiles\MailFileReader;

/**
 * @method static \Melloww\MailFiles\Email read(string $path)
 * @method static \Melloww\MailFiles\Email readString(string $contents)
 * @method static \Melloww\MailFiles\Email readUploadedFile(\Illuminate\Http\UploadedFile $file)
 * @method static \Melloww\MailFiles\Email readHeaders(string $path)
 * @method static \Melloww\MailFiles\Email readHeadersString(string $contents)
 * @method static \Melloww\MailFiles\Email readEml(string $path)
 * @method static \Melloww\MailFiles\Email readMsg(string $path)
 * @method static \Generator<int, \Melloww\MailFiles\Email> readMbox(string $path)
 * @method static \Generator<string, \Melloww\MailFiles\Email> readDirectory(string $directory, bool $recursive = false)
 * @method static \Melloww\MailFiles\Enums\MailFormat detectFormat(string $contents)
 *
 * @see MailFileReader
 */
class MailFiles extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return MailFileReader::class;
    }
}
