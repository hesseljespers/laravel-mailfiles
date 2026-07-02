<?php

declare(strict_types=1);

namespace Melloww\MailFiles\Contracts;

use Melloww\MailFiles\Email;

/**
 * Strategy for turning raw RFC 822 / MIME bytes into a unified {@see Email}.
 *
 * Two implementations ship with the package: a dependency-free native parser
 * and an optional one backed by the PHP `mailparse` extension.
 */
interface ParsesEml
{
    public function parse(string $raw): Email;
}
