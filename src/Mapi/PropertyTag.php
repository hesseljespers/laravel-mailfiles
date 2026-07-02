<?php

declare(strict_types=1);

namespace Melloww\MailFiles\Mapi;

/**
 * The MAPI property IDs the MSG parser cares about, plus the MAPI property
 * type identifiers. See [MS-OXPROPS] for the full registry.
 */
final class PropertyTag
{
    // --- Property types -----------------------------------------------------
    public const TYPE_SHORT = 0x0002;     // PT_SHORT (16-bit int)

    public const TYPE_LONG = 0x0003;      // PT_LONG (32-bit int)

    public const TYPE_BOOLEAN = 0x000B;   // PT_BOOLEAN

    public const TYPE_I8 = 0x0014;        // PT_LONGLONG (64-bit int)

    public const TYPE_STRING8 = 0x001E;   // PT_STRING8 (code-page string)

    public const TYPE_UNICODE = 0x001F;   // PT_UNICODE (UTF-16LE)

    public const TYPE_SYSTIME = 0x0040;   // PT_SYSTIME (FILETIME)

    public const TYPE_BINARY = 0x0102;    // PT_BINARY

    // --- Message properties -------------------------------------------------
    public const SUBJECT = 0x0037;                       // PR_SUBJECT

    public const BODY = 0x1000;                          // PR_BODY (plain text)

    public const HTML = 0x1013;                          // PR_HTML (binary)

    public const RTF_COMPRESSED = 0x1009;                // PR_RTF_COMPRESSED

    public const TRANSPORT_MESSAGE_HEADERS = 0x007D;     // PR_TRANSPORT_MESSAGE_HEADERS

    public const INTERNET_MESSAGE_ID = 0x1035;           // PR_INTERNET_MESSAGE_ID

    public const IN_REPLY_TO_ID = 0x1042;                // PR_IN_REPLY_TO_ID

    public const INTERNET_REFERENCES = 0x1039;           // PR_INTERNET_REFERENCES

    public const IMPORTANCE = 0x0017;                    // PR_IMPORTANCE (0=low, 1=normal, 2=high)

    public const SENSITIVITY = 0x0036;                   // PR_SENSITIVITY (0=none, 1=personal, 2=private, 3=confidential)

    public const CLIENT_SUBMIT_TIME = 0x0039;            // PR_CLIENT_SUBMIT_TIME

    public const MESSAGE_DELIVERY_TIME = 0x0E06;         // PR_MESSAGE_DELIVERY_TIME

    public const INTERNET_CPID = 0x3FDE;                 // PR_INTERNET_CPID

    public const MESSAGE_CODEPAGE = 0x3FFD;              // PR_MESSAGE_CODEPAGE

    public const DISPLAY_TO = 0x0E04;                    // PR_DISPLAY_TO

    public const DISPLAY_CC = 0x0E03;                    // PR_DISPLAY_CC

    public const DISPLAY_BCC = 0x0E02;                   // PR_DISPLAY_BCC

    // --- Sender / author ----------------------------------------------------
    public const SENDER_NAME = 0x0C1A;                   // PR_SENDER_NAME

    public const SENDER_ADDRTYPE = 0x0C1E;               // PR_SENDER_ADDRTYPE

    public const SENDER_EMAIL_ADDRESS = 0x0C1F;          // PR_SENDER_EMAIL_ADDRESS

    public const SENDER_SMTP_ADDRESS = 0x5D01;           // PR_SENDER_SMTP_ADDRESS

    public const SENT_REPRESENTING_NAME = 0x0042;        // PR_SENT_REPRESENTING_NAME

    public const SENT_REPRESENTING_ADDRTYPE = 0x0064;    // PR_SENT_REPRESENTING_ADDRTYPE

    public const SENT_REPRESENTING_EMAIL_ADDRESS = 0x0065; // PR_SENT_REPRESENTING_EMAIL_ADDRESS

    public const SENT_REPRESENTING_SMTP_ADDRESS = 0x5D02;  // PR_SENT_REPRESENTING_SMTP_ADDRESS

    // --- Recipient properties (inside __recip_version1.0_# storages) --------
    public const RECIPIENT_TYPE = 0x0C15;                // PR_RECIPIENT_TYPE (1=To, 2=Cc, 3=Bcc)

    public const DISPLAY_NAME = 0x3001;                  // PR_DISPLAY_NAME

    public const ADDRTYPE = 0x0C1E;                      // PR_ADDRTYPE

    public const EMAIL_ADDRESS = 0x3003;                 // PR_EMAIL_ADDRESS

    public const SMTP_ADDRESS = 0x39FE;                  // PR_SMTP_ADDRESS

    // --- Attachment properties (inside __attach_version1.0_# storages) ------
    public const ATTACH_FILENAME = 0x3704;               // PR_ATTACH_FILENAME (short name)

    public const ATTACH_LONG_FILENAME = 0x3707;          // PR_ATTACH_LONG_FILENAME

    public const ATTACH_DATA_BINARY = 0x3701;            // PR_ATTACH_DATA_BIN

    public const ATTACH_MIME_TAG = 0x370E;               // PR_ATTACH_MIME_TAG

    public const ATTACH_CONTENT_ID = 0x3712;             // PR_ATTACH_CONTENT_ID

    public const ATTACH_CONTENT_LOCATION = 0x3713;       // PR_ATTACH_CONTENT_LOCATION

    public const ATTACH_METHOD = 0x3705;                 // PR_ATTACH_METHOD

    public const ATTACH_FLAGS = 0x3714;                  // PR_ATTACH_FLAGS

    public const ATTACHMENT_HIDDEN = 0x7FFE;             // PR_ATTACHMENT_HIDDEN

    // --- Recipient type values ---------------------------------------------
    public const RECIPIENT_TO = 1;

    public const RECIPIENT_CC = 2;

    public const RECIPIENT_BCC = 3;

    // --- Attachment method values ------------------------------------------
    public const ATTACH_BY_VALUE = 1;

    public const ATTACH_EMBEDDED_MSG = 5;
}
