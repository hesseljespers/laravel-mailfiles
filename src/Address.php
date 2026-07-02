<?php

declare(strict_types=1);

namespace Melloww\MailFiles;

use Stringable;

/**
 * A single mailbox: an optional display name together with an email address.
 *
 * The same value object is produced regardless of whether the message came
 * from an EML or an MSG file.
 */
final class Address implements Stringable
{
    public function __construct(
        public readonly ?string $email = null,
        public readonly ?string $name = null,
    ) {}

    public function email(): ?string
    {
        return $this->email;
    }

    public function name(): ?string
    {
        return $this->name;
    }

    /**
     * The display name when present, otherwise the email address.
     */
    public function displayName(): ?string
    {
        return $this->name ?? $this->email;
    }

    /**
     * Render as `"Display Name" <email@example.com>` (or just the email when no name is set).
     */
    public function toString(): string
    {
        if ($this->name !== null && $this->name !== '' && $this->name !== $this->email) {
            return ($this->email !== null && $this->email !== '')
                ? sprintf('%s <%s>', $this->name, $this->email)
                : $this->name;
        }

        return (string) $this->email;
    }

    public function __toString(): string
    {
        return $this->toString();
    }

    /**
     * @return array{name: string|null, email: string|null}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'email' => $this->email,
        ];
    }
}
