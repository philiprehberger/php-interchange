<?php

declare(strict_types=1);

namespace PhilipRehberger\Interchange\Errors;

/**
 * SPEC §5 — RFC 9457 Problem Details.
 *
 * Measured 2026-08-05: all five services in the fleet already return
 * problem+json, having inherited a common implementation from a shared
 * scaffold. So adoption here is **convergence onto one implementation**, not
 * the addition of a missing capability — which makes this the lowest-risk part
 * of the contract and worth stating so nobody budgets it as new work.
 */
final class ProblemDetails implements \JsonSerializable
{
    public const CONTENT_TYPE = 'application/problem+json';

    /** @param array<string, mixed> $extensions */
    public function __construct(
        public readonly int $status,
        public readonly string $title,
        public readonly ?string $detail = null,
        public readonly string $type = 'about:blank',
        public readonly ?string $instance = null,
        public readonly array $extensions = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $out = [
            'type' => $this->type,
            'title' => $this->title,
            'status' => $this->status,
        ];

        if ($this->detail !== null) {
            $out['detail'] = $this->detail;
        }

        if ($this->instance !== null) {
            $out['instance'] = $this->instance;
        }

        return $out + $this->extensions;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /** Does a response body look like RFC 9457? Used by the conformance suite. */
    public static function looksLikeProblem(mixed $body, ?string $contentType = null): bool
    {
        if ($contentType !== null && str_contains($contentType, 'problem+json')) {
            return true;
        }

        return is_array($body)
            && array_key_exists('title', $body)
            && array_key_exists('status', $body);
    }
}
