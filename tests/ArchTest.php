<?php

declare(strict_types=1);

arch('it will not use debugging functions')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r'])
    ->each->not->toBeUsed();

arch('strict types are declared everywhere')
    ->expect('Melloww\MailFiles')
    ->toUseStrictTypes();

arch('value objects and contracts stay framework-agnostic')
    ->expect([
        'Melloww\MailFiles\Email',
        'Melloww\MailFiles\Address',
        'Melloww\MailFiles\Attachment',
        'Melloww\MailFiles\Parsers\EmlParser',
        'Melloww\MailFiles\Parsers\MsgParser',
        'Melloww\MailFiles\Ole\OleReader',
    ])
    ->not->toUse(['Illuminate\Support\Facades\Facade']);
