<?php

declare(strict_types=1);

namespace PhilipRehberger\Interchange\Signing;

/**
 * No signing. Exists so "unsigned" is an explicit, auditable choice in the same
 * enum as every other scheme, rather than a null the calling code has to
 * remember to handle.
 *
 * verify() returns false unconditionally: a scheme that signs nothing cannot
 * authenticate anything, and returning true would turn "no signature required"
 * into "any signature accepted".
 */
final class NullScheme implements SignatureScheme
{
    public function name(): string
    {
        return 'none';
    }

    public function sign(string $messageId, string $payload, string $secret, ?int $timestamp = null): array
    {
        return [];
    }

    public function verify(array $headers, string $payload, array|string $secrets): bool
    {
        return false;
    }
}
