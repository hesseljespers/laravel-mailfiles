<?php

// config for Melloww/MailFiles
return [

    /*
    |--------------------------------------------------------------------------
    | EML parsing driver
    |--------------------------------------------------------------------------
    |
    | How RFC 822 / .eml messages are parsed:
    |
    |   "native"    Pure-PHP parser bundled with this package. Requires no PHP
    |               extension beyond mbstring and is the recommended default.
    |
    |   "mailparse" Delegate structural parsing to the PHP `mailparse` extension
    |               (must be installed). Decoding and the public API are
    |               identical to the native driver.
    |
    |   "auto"      Use "mailparse" when the extension is loaded, otherwise fall
    |               back to "native".
    |
    | .msg (Outlook MAPI) files are always read by the bundled native reader.
    |
    */
    'eml' => [
        'driver' => env('MAILFILES_EML_DRIVER', 'native'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Attachments & nested messages
    |--------------------------------------------------------------------------
    |
    | When an attachment is itself an email (a .eml inside a .eml, a .msg inside
    | a .msg, or one inside the other), it is parsed recursively and made
    | available through $attachment->embeddedMessage(). "max_depth" bounds how
    | deep this recursion goes, guarding against pathological/malicious files.
    |
    */
    'attachments' => [
        'extract_nested' => env('MAILFILES_EXTRACT_NESTED', true),
        'max_depth' => env('MAILFILES_MAX_DEPTH', 5),
    ],

];
