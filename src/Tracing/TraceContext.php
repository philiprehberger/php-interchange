<?php

declare(strict_types=1);

namespace PhilipRehberger\Interchange\Tracing;

/**
 * W3C Trace Context identifiers and header serialisation.
 *
 * Field formats are taken from the published specification (verified
 * 2026-08-05), not from memory:
 *
 *   traceparent = version "-" trace-id "-" parent-id "-" trace-flags
 *
 *   version     2 lowercase hex digits; "ff" is invalid
 *   trace-id    32 lowercase hex digits; all-zeroes invalid
 *   parent-id   16 lowercase hex digits; all-zeroes invalid
 *               (the spec's term; it carries the CALLER's span id)
 *   trace-flags 2 lowercase hex digits; only bit 0 (sampled) is defined,
 *               all other bits MUST be zero
 *
 * @see https://www.w3.org/TR/trace-context/
 */
final class TraceContext
{
    public const VERSION = '00';

    public const INVALID_VERSION = 'ff';

    public const INVALID_TRACE_ID = '00000000000000000000000000000000';

    public const INVALID_PARENT_ID = '0000000000000000';

    public function __construct(
        public readonly string $traceId,
        public readonly string $parentId,
        public readonly bool $sampled = true,
        public readonly ?TraceState $state = null,
    ) {}

    public static function start(?TraceState $state = null): self
    {
        return new self(self::newTraceId(), self::newParentId(), true, $state);
    }

    /**
     * Parse an inbound traceparent. Returns null when absent or invalid — the
     * caller MUST then mint a new trace rather than continuing a broken one.
     */
    public static function parse(?string $traceparent, ?string $tracestate = null): ?self
    {
        if ($traceparent === null) {
            return null;
        }

        $parts = explode('-', trim($traceparent));

        if (count($parts) < 4) {
            return null;
        }

        [$version, $traceId, $parentId, $flags] = $parts;

        if (! preg_match('/^[0-9a-f]{2}$/', $version) || $version === self::INVALID_VERSION) {
            return null;
        }

        if (! self::isValidTraceId($traceId) || ! self::isValidParentId($parentId)) {
            return null;
        }

        if (! preg_match('/^[0-9a-f]{2}$/', $flags)) {
            return null;
        }

        return new self(
            $traceId,
            $parentId,
            (hexdec($flags) & 0x01) === 0x01,
            TraceState::parse($tracestate),
        );
    }

    /** A child of this context: same trace, a freshly minted span as the new parent-id. */
    public function child(): self
    {
        return new self($this->traceId, self::newParentId(), $this->sampled, $this->state);
    }

    public function withState(TraceState $state): self
    {
        return new self($this->traceId, $this->parentId, $this->sampled, $state);
    }

    public function toTraceparent(): string
    {
        // Only bit 0 is defined; every other bit must be zero.
        return sprintf('%s-%s-%s-%02x', self::VERSION, $this->traceId, $this->parentId, $this->sampled ? 0x01 : 0x00);
    }

    public function toTracestate(): ?string
    {
        return $this->state?->toHeader();
    }

    /** @return array<string, string> headers to attach to an outbound request */
    public function toHeaders(): array
    {
        $headers = ['traceparent' => $this->toTraceparent()];
        $state = $this->toTracestate();

        if ($state !== null && $state !== '') {
            $headers['tracestate'] = $state;
        }

        return $headers;
    }

    public static function newTraceId(): string
    {
        do {
            $id = bin2hex(random_bytes(16));
        } while ($id === self::INVALID_TRACE_ID);

        return $id;
    }

    public static function newParentId(): string
    {
        do {
            $id = bin2hex(random_bytes(8));
        } while ($id === self::INVALID_PARENT_ID);

        return $id;
    }

    public static function isValidTraceId(string $id): bool
    {
        return $id !== self::INVALID_TRACE_ID && preg_match('/^[0-9a-f]{32}$/', $id) === 1;
    }

    public static function isValidParentId(string $id): bool
    {
        return $id !== self::INVALID_PARENT_ID && preg_match('/^[0-9a-f]{16}$/', $id) === 1;
    }
}
