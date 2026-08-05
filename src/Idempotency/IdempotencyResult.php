<?php

declare(strict_types=1);

namespace PhilipRehberger\Interchange\Idempotency;

final readonly class IdempotencyResult
{
    private function __construct(
        public string $outcome,
        public ?int $status = null,
        public ?string $body = null,
    ) {}

    /** No prior record — the caller should proceed. */
    public static function fresh(): self
    {
        return new self('fresh');
    }

    /** Same key, same body — return the recorded response, do not re-perform. */
    public static function replay(int $status, string $body): self
    {
        return new self('replay', $status, $body);
    }

    /**
     * Same key, DIFFERENT body. The caller reused an idempotency key for a
     * different request, which is a client bug: silently serving the first
     * response would hide it, and performing the second would break the
     * guarantee the key exists to provide.
     */
    public static function conflict(): self
    {
        return new self('conflict');
    }

    public function isFresh(): bool
    {
        return $this->outcome === 'fresh';
    }

    public function isReplay(): bool
    {
        return $this->outcome === 'replay';
    }

    public function isConflict(): bool
    {
        return $this->outcome === 'conflict';
    }
}
