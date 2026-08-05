<?php

declare(strict_types=1);

namespace PhilipRehberger\Interchange\Signing;

/**
 * A named signing scheme.
 *
 * The contract is additive: every service keeps its native scheme working and
 * gains `standard-webhooks` as an additional, per-destination option. This
 * interface is what makes "additive, never replacement" implementable rather
 * than aspirational.
 */
interface SignatureScheme
{
    public function name(): string;

    /**
     * @return array<string, string> headers to attach to the outbound request
     */
    public function sign(string $messageId, string $payload, string $secret, ?int $timestamp = null): array;

    /**
     * @param  array<string, string>  $headers
     * @param  array<int, string>|string  $secrets
     */
    public function verify(array $headers, string $payload, array|string $secrets): bool;
}
